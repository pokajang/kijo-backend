<?php

namespace App\Services\Clients;

use App\Http\Requests\Client\DeleteUnassignedClientPicRequest;
use App\Http\Requests\Client\ListClientsRequest;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UnassignClientPicRequest;
use App\Http\Requests\Client\UpdateClientPicRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientCompanyLookupService extends ClientBaseService
{

    public function listAll(ListClientsRequest $request): JsonResponse
    {
        $perPage = (int) ($request->validated()['per_page'] ?? 25);

        $branchSummary = DB::table('client_company_branch')
            ->selectRaw("company_id, COUNT(*) AS branch_count, GROUP_CONCAT(CONCAT(COALESCE(NULLIF(TRIM(branch_name), ''), 'Branch'), ': ', COALESCE(NULLIF(TRIM(address), ''), '-'), CASE WHEN TRIM(COALESCE(zip, '')) <> '' OR TRIM(COALESCE(city, '')) <> '' OR TRIM(COALESCE(state, '')) <> '' THEN CONCAT(', ', TRIM(COALESCE(zip, '')), ' ', TRIM(COALESCE(city, '')), ' ', TRIM(COALESCE(state, ''))) ELSE '' END, CASE WHEN TRIM(COALESCE(country, '')) <> '' THEN CONCAT(' (', TRIM(country), ')') ELSE '' END) ORDER BY branch_id ASC SEPARATOR ' || ') AS branch_summary")
            ->whereNull('deleted_at')
            ->groupBy('company_id');

        $paginator = DB::table('client_company as cc')
            ->leftJoinSub($branchSummary, 'cb', static function ($join): void {
                $join->on('cb.company_id', '=', 'cc.company_id');
            })
            ->select([
                'cc.company_id',
                'cc.company_name',
                'cc.ssm_number',
                'cc.tax_id_no_tin',
                'cc.client_status',
                'cc.address',
                'cc.city',
                'cc.state',
                'cc.zip',
                'cc.created_at',
                'cc.updated_at',
                DB::raw('COALESCE(cb.branch_count, 0) AS branch_count'),
                'cb.branch_summary',
            ])
            ->whereNull('cc.deleted_at')
            ->orderBy('cc.company_name')
            ->paginate($perPage);

        $rows = $paginator->items();
        $companyIds = array_values(array_filter(array_map(static fn ($row) => (int) ($row->company_id ?? 0), $rows)));

        $picRows = empty($companyIds)
            ? collect()
            : DB::table('client_pic')
                ->select(['pic_id', 'company_id', 'full_name', 'email', 'mobile_number', 'position'])
                ->whereNull('deleted_at')
                ->whereNotNull('company_id')
                ->whereIn('company_id', $companyIds)
                ->orderBy('company_id')
                ->orderBy('pic_id')
                ->get();

        $picPreviewMap = [];
        $picCountMap = [];
        $picSearchMap = [];

        foreach ($picRows as $pic) {
            $companyId = (int) ($pic->company_id ?? 0);
            if ($companyId <= 0) {
                continue;
            }

            $picCountMap[$companyId] = ($picCountMap[$companyId] ?? 0) + 1;

            if (!isset($picPreviewMap[$companyId])) {
                $picPreviewMap[$companyId] = [];
            }

            if (count($picPreviewMap[$companyId]) < 2) {
                $picPreviewMap[$companyId][] = [
                    'pic_id' => isset($pic->pic_id) ? (int) $pic->pic_id : null,
                    'full_name' => $pic->full_name ?? '',
                    'email' => $pic->email ?? '',
                    'mobile_number' => $pic->mobile_number ?? '',
                    'position' => $pic->position ?? '',
                ];
            }

            $tokens = array_values(array_filter([
                trim((string) ($pic->full_name ?? '')),
                trim((string) ($pic->email ?? '')),
                trim((string) ($pic->mobile_number ?? '')),
                trim((string) ($pic->position ?? '')),
            ]));

            if (!isset($picSearchMap[$companyId])) {
                $picSearchMap[$companyId] = [];
            }

            if (!empty($tokens)) {
                $picSearchMap[$companyId][] = implode(' ', $tokens);
            }
        }

        $companies = array_map(function ($company) use ($picCountMap, $picPreviewMap, $picSearchMap): array {
            $companyId = (int) ($company->company_id ?? 0);

            return [
                'company_id' => $companyId,
                'company_name' => $company->company_name,
                'ssm_number' => $company->ssm_number,
                'tax_id_no_tin' => $company->tax_id_no_tin,
                'client_status' => $company->client_status,
                'address' => $company->address,
                'city' => $company->city,
                'state' => $company->state,
                'zip' => $company->zip,
                'created_at' => $company->created_at,
                'updated_at' => $company->updated_at,
                'branch_count' => (int) ($company->branch_count ?? 0),
                'branch_summary' => $company->branch_summary,
                'pic_count' => $picCountMap[$companyId] ?? 0,
                'pic_preview' => $picPreviewMap[$companyId] ?? [],
                'pic_search_blob' => isset($picSearchMap[$companyId]) ? implode(' ', $picSearchMap[$companyId]) : '',
            ];
        }, $rows);

        return $this->success($companies, null, 200, [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }


    public function listClients(): JsonResponse
    {
        $companies = DB::table('client_company')
            ->select(['company_id', 'company_name', 'ssm_number', 'tax_id_no_tin', 'client_status', 'address', 'city', 'state', 'zip'])
            ->get();

        return $this->success($companies);
    }


    public function listClientOptions(): JsonResponse
    {
        $clients = DB::table('client_company')
            ->select(['company_id', 'company_name'])
            ->whereNull('deleted_at')
            ->orderBy('company_name')
            ->get();

        return $this->success($clients);
    }


    public function listCompanyBranches(Request $request, ?int $companyId = null): JsonResponse
    {
        $companyId = $companyId ?? (int) $request->input('company_id', 0);
        if ($companyId <= 0) {
            return $this->error('Invalid company_id');
        }

        $branches = DB::table('client_company_branch')
            ->select(['branch_id', 'company_id', 'branch_name', 'address', 'city', 'state', 'zip', 'country', 'status', 'created_at'])
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('branch_id')
            ->get();

        return $this->success($branches);
    }
}
