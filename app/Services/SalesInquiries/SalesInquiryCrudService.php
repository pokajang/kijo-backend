<?php

namespace App\Services\SalesInquiries;

use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SalesInquiryCrudService extends SalesInquiryBaseService
{

    public function index(Request $request): JsonResponse
    {
        if (!$this->tableReady()) {
            return $this->error('Sales inquiries table is not available.', 409);
        }

        $query = DB::table('sales_inquiries')
            ->whereNull('deleted_at')
            ->orderByDesc('inquiry_date')
            ->orderByDesc('id');

        $search = trim((string) $request->input('q', ''));
        $status = trim((string) $request->input('status', ''));
        $source = trim((string) $request->input('source', ''));
        $serviceRequired = trim((string) $request->input('serviceRequired', $request->input('service_required', '')));
        $startDate = trim((string) $request->input('startDate', $request->input('start_date', '')));
        $endDate = trim((string) $request->input('endDate', $request->input('end_date', '')));

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($source !== '') {
            $query->where('source', $source);
        }

        if ($serviceRequired !== '') {
            $query->where('service_required', $serviceRequired);
        }

        if ($startDate !== '' && $endDate !== '') {
            $query->whereBetween('inquiry_date', [
                Carbon::parse($startDate)->format('Y-m-d'),
                Carbon::parse($endDate)->format('Y-m-d'),
            ]);
        }

        if ($search !== '') {
            $query->where(function ($nested) use ($search): void {
                $like = '%' . $search . '%';
                $nested
                    ->where('company_name', 'like', $like)
                    ->orWhere('ssm_number', 'like', $like)
                    ->orWhere('tax_id_no_tin', 'like', $like)
                    ->orWhere('contact_name', 'like', $like)
                    ->orWhere('mobile', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('address', 'like', $like)
                    ->orWhere('source_remarks', 'like', $like)
                    ->orWhere('remarks', 'like', $like);
            });
        }

        $rawRows = $query->limit(1000)->get();
        $proofsByInquiryId = $this->proofsByInquiryIds($rawRows->pluck('id')->all());
        $rows = $rawRows
            ->map(fn ($row) => $this->mapRow($row, $proofsByInquiryId[(int) $row->id] ?? []))
            ->values();

        return $this->success($rows);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (!$this->tableReady()) {
            return $this->error('Sales inquiries table is not available.', 409);
        }

        $row = DB::table('sales_inquiries')->whereNull('deleted_at')->where('id', $id)->first();
        if (!$row) {
            return $this->error('Inquiry not found.', 404);
        }

        return $this->success($this->mapRow($row));
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->tableReady()) {
            return $this->error('Sales inquiries table is not available.', 409);
        }

        $data = $this->validatedPayload($request);
        $proofs = $this->storeProofsFromRequest($request);
        $firstProof = $proofs[0] ?? ['path' => null, 'originalName' => null, 'mimeType' => null];
        $staffId = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        $staffName = trim((string) $request->session()->get('full_name', ''));
        $now = now();

        try {
            $id = DB::transaction(function () use ($data, $firstProof, $proofs, $staffId, $staffCode, $staffName, $now): int {
                $id = DB::table('sales_inquiries')->insertGetId([
                    ...$this->rowPayload($data),
                    'proof_path' => $firstProof['path'],
                    'proof_original_name' => $firstProof['originalName'],
                    'proof_mime_type' => $firstProof['mimeType'],
                    'owner_staff_id' => $staffId ?: null,
                    'owner_staff_code' => $staffCode ?: null,
                    'owner_staff_name' => $staffName ?: null,
                    'owner_assigned_by_id' => $staffId ?: null,
                    'owner_assigned_by_code' => $staffCode ?: null,
                    'owner_assigned_by_name' => $staffName ?: null,
                    'owner_assigned_at' => $staffId ? $now : null,
                    'created_by' => $staffId ?: null,
                    'created_by_code' => $staffCode ?: null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->insertProofRows($id, $proofs, $now);

                return (int) $id;
            });
        } catch (\Throwable $exception) {
            $this->deleteStoredProofFiles($proofs);
            throw $exception;
        }

        $this->auditLog->log($request, 'Created sales inquiry: ' . $data['companyName']);

        $row = DB::table('sales_inquiries')->where('id', $id)->first();
        return $this->success($this->mapRow($row), 'Inquiry saved.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->tableReady()) {
            return $this->error('Sales inquiries table is not available.', 409);
        }

        $row = DB::table('sales_inquiries')->whereNull('deleted_at')->where('id', $id)->first();
        if (!$row) {
            return $this->error('Inquiry not found.', 404);
        }

        $data = $this->validatedPayload($request);
        $proofs = $this->storeProofsFromRequest($request);
        $removedProofIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->input('removedProofIds', [])),
        )));
        $removedProofRows = !empty($removedProofIds) && $this->proofTableReady()
            ? DB::table('sales_inquiry_proofs')
                ->whereNull('deleted_at')
                ->where('sales_inquiry_id', $id)
                ->whereIn('id', $removedProofIds)
                ->get()
            : collect();
        $updates = [
            ...$this->rowPayload($data),
            'updated_at' => now(),
        ];

        try {
            DB::transaction(function () use ($id, $updates, $proofs, $removedProofIds): void {
                DB::table('sales_inquiries')->where('id', $id)->update($updates);

                if (!empty($removedProofIds) && $this->proofTableReady()) {
                    DB::table('sales_inquiry_proofs')
                        ->whereNull('deleted_at')
                        ->where('sales_inquiry_id', $id)
                        ->whereIn('id', $removedProofIds)
                        ->update([
                            'deleted_at' => now(),
                            'updated_at' => now(),
                        ]);
                }

                $this->insertProofRows($id, $proofs, now());
                $this->syncLegacyProofColumns($id);
            });
        } catch (\Throwable $exception) {
            $this->deleteStoredProofFiles($proofs);
            throw $exception;
        }

        $this->deleteProofRowsFiles($removedProofRows);
        $this->auditLog->log($request, 'Updated sales inquiry: ' . $data['companyName']);

        $updated = DB::table('sales_inquiries')->where('id', $id)->first();
        return $this->success($this->mapRow($updated), 'Inquiry updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (!$this->tableReady()) {
            return $this->error('Sales inquiries table is not available.', 409);
        }

        $row = DB::table('sales_inquiries')->whereNull('deleted_at')->where('id', $id)->first();
        if (!$row) {
            return $this->error('Inquiry not found.', 404);
        }

        $proofRows = $this->proofRowsForInquiry($id);

        DB::transaction(function () use ($id): void {
            DB::table('sales_inquiries')->where('id', $id)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

            if ($this->proofTableReady()) {
                DB::table('sales_inquiry_proofs')
                    ->whereNull('deleted_at')
                    ->where('sales_inquiry_id', $id)
                    ->update([
                        'deleted_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        });

        $this->deleteProofRowsFiles($proofRows);
        if ($proofRows->isEmpty() && !empty($row->proof_path)) {
            \App\Support\AppFilePaths::deleteStoredPath((string) $row->proof_path);
        }

        $this->auditLog->log($request, 'Deleted sales inquiry: ' . (string) $row->company_name);

        return $this->success(null, 'Inquiry deleted.');
    }
}
