<?php

namespace App\Services\Vendors;

use App\Http\Requests\Vendor\ApproveVendorPaymentRequest;
use App\Http\Requests\Vendor\DeactivateVendorRequest;
use App\Http\Requests\Vendor\DeleteVendorPaymentRequest;
use App\Http\Requests\Vendor\GetVendorPaymentsRequest;
use App\Http\Requests\Vendor\ListProjectVendorsRequest;
use App\Http\Requests\Vendor\ListVendorMainDetailsRequest;
use App\Http\Requests\Vendor\ListVendorsRequest;
use App\Http\Requests\Vendor\ListVendorPaymentsRequest;
use App\Http\Requests\Vendor\PermanentDeleteVendorRequest;
use App\Http\Requests\Vendor\ReactivateVendorRequest;
use App\Http\Requests\Vendor\StoreVendorPaymentRequest;
use App\Http\Requests\Vendor\StoreVendorRequest;
use App\Http\Requests\Vendor\UpdateVendorRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VendorProjectAssignmentService extends VendorBaseService
{

    public function projectVendors(ListProjectVendorsRequest $request)
    {
        $perPage = $this->resolvePerPage($request->validated(), 25);

        $projectPaginator = DB::table('project_vendors as pv')
            ->join('projects_main as p', 'pv.project_id', '=', 'p.id')
            ->select([
                'p.id as project_id',
                'p.project_name',
                'p.status as project_status',
                'p.description as project_description',
            ])
            ->distinct()
            ->orderBy('p.project_name', 'asc')
            ->paginate($perPage);

        $projectRows = collect($projectPaginator->items())
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();

        $projectIds = array_values(array_map(fn ($row) => (int) $row['project_id'], $projectRows));

        $vendorsByProject = [];
        if (!empty($projectIds)) {
            $rows = DB::table('project_vendors as pv')
                ->join('projects_main as p', 'pv.project_id', '=', 'p.id')
                ->join('vendor_main_details as v', 'pv.vendor_id', '=', 'v.vendor_id')
                ->whereIn('p.id', $projectIds)
                ->orderBy('p.project_name', 'asc')
                ->orderBy('v.vendor_name', 'asc')
                ->select([
                    'p.id as project_id',
                    'v.vendor_id',
                    'v.vendor_name',
                    'v.bank_name',
                    'v.bank_account',
                    'v.bank_holder_name',
                    'pv.award_value',
                    'pv.position',
                    'pv.remarks',
                    'pv.services_description',
                    'pv.venue_details',
                    'pv.fee_breakdown',
                    'pv.payment_terms',
                ])
                ->get();

            foreach ($rows as $row) {
                $pid = (int) $row->project_id;
                $vendorsByProject[$pid][] = [
                    'vendor_id'            => $row->vendor_id,
                    'vendor_name'          => $row->vendor_name,
                    'bank_name'            => $row->bank_name,
                    'bank_account'         => $row->bank_account,
                    'bank_holder_name'     => $row->bank_holder_name,
                    'award_value'          => $row->award_value,
                    'position'             => $row->position,
                    'remarks'              => $row->remarks,
                    'services_description' => $row->services_description,
                    'venue_details'        => $row->venue_details,
                    'fee_breakdown'        => $row->fee_breakdown,
                    'payment_terms'        => $row->payment_terms,
                ];
            }
        }

        $data = array_map(function (array $projectRow) use ($vendorsByProject) {
            $pid = (int) $projectRow['project_id'];
            return [
                'project_id'   => $projectRow['project_id'],
                'project_name' => $projectRow['project_name'],
                'status'       => $projectRow['project_status'],
                'description'  => $projectRow['project_description'],
                'vendors'      => $vendorsByProject[$pid] ?? [],
            ];
        }, $projectRows);

        return response()->json([
            'status'     => 'success',
            'data'       => $data,
            'pagination' => [
                'current_page' => $projectPaginator->currentPage(),
                'last_page'    => $projectPaginator->lastPage(),
                'per_page'     => $projectPaginator->perPage(),
                'total'        => $projectPaginator->total(),
            ],
        ]);
    }
}
