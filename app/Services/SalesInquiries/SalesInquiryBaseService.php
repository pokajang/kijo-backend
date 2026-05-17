<?php

namespace App\Services\SalesInquiries;

use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class SalesInquiryBaseService
{
    protected const STATUSES = [
        'new',
        'contacted',
        'qualified',
        'quote_created',
        'converted_client',
        'lost',
        'archived',
    ];

    protected const SERVICES = [
        'training',
        'consultancy_iso',
        'consultancy_ihoh',
        'man_power',
        'equipment_supply',
        'engineering',
        'infrastructure',
        'special_service',
    ];

    public function __construct(protected AuditLogService $auditLog) {}

    protected function validatedPayload(Request $request): array
    {
        $data = $request->validate([
            'companyName' => ['required', 'string', 'max:191'],
            'ssmNumber' => ['nullable', 'string', 'max:80'],
            'taxIdNoTin' => ['nullable', 'string', 'max:80'],
            'contactName' => ['nullable', 'string', 'max:191'],
            'mobile' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'zip' => ['nullable', 'string', 'max:30'],
            'serviceRequired' => ['required', 'string', 'in:' . implode(',', self::SERVICES)],
            'source' => ['required', 'string', 'max:100'],
            'sourceRemarks' => ['nullable', 'string', 'max:500'],
            'inquiryDate' => ['required', 'date'],
            'status' => ['required', 'string', 'in:' . implode(',', self::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'proofDataUrl' => ['nullable', 'string'],
            'proofOriginalName' => ['nullable', 'string', 'max:191'],
            'proofMimeType' => ['nullable', 'string', 'max:100'],
            'proofs' => ['nullable', 'array', 'max:10'],
            'proofs.*.dataUrl' => ['required_with:proofs', 'string'],
            'proofs.*.originalName' => ['nullable', 'string', 'max:191'],
            'proofs.*.mimeType' => ['nullable', 'string', 'max:100'],
            'removedProofIds' => ['nullable', 'array'],
            'removedProofIds.*' => ['integer', 'min:1'],
        ]);

        if (trim((string) ($data['mobile'] ?? '')) === '' && trim((string) ($data['email'] ?? '')) === '') {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Mobile or email is required.',
            ], 422));
        }

        return $data;
    }

    protected function rowPayload(array $data): array
    {
        return [
            'company_name' => trim((string) $data['companyName']),
            'ssm_number' => trim((string) ($data['ssmNumber'] ?? '')) ?: null,
            'tax_id_no_tin' => trim((string) ($data['taxIdNoTin'] ?? '')) ?: null,
            'contact_name' => trim((string) ($data['contactName'] ?? '')) ?: null,
            'mobile' => trim((string) ($data['mobile'] ?? '')) ?: null,
            'email' => trim((string) ($data['email'] ?? '')) ?: null,
            'address' => trim((string) ($data['address'] ?? '')) ?: null,
            'city' => trim((string) ($data['city'] ?? '')) ?: null,
            'state' => trim((string) ($data['state'] ?? '')) ?: null,
            'zip' => trim((string) ($data['zip'] ?? '')) ?: null,
            'service_required' => trim((string) ($data['serviceRequired'] ?? '')) ?: null,
            'source' => trim((string) $data['source']),
            'source_remarks' => trim((string) ($data['sourceRemarks'] ?? '')) ?: null,
            'inquiry_date' => Carbon::parse($data['inquiryDate'])->format('Y-m-d'),
            'status' => in_array(($data['status'] ?? ''), self::STATUSES, true) ? $data['status'] : 'new',
            'remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
        ];
    }

    protected function storeProofsFromRequest(Request $request): array
    {
        $proofPayloads = [];
        $proofs = $request->input('proofs', []);

        if (is_array($proofs)) {
            foreach ($proofs as $proof) {
                if (is_array($proof)) {
                    $proofPayloads[] = [
                        'dataUrl' => (string) ($proof['dataUrl'] ?? ''),
                        'originalName' => (string) ($proof['originalName'] ?? ''),
                    ];
                }
            }
        }

        $legacyDataUrl = trim((string) $request->input('proofDataUrl', ''));
        if ($legacyDataUrl !== '') {
            $proofPayloads[] = [
                'dataUrl' => $legacyDataUrl,
                'originalName' => trim((string) $request->input('proofOriginalName', '')),
            ];
        }

        $storedProofs = [];
        foreach ($proofPayloads as $proofPayload) {
            $storedProofs[] = $this->storeProofFromDataUrl(
                $proofPayload['dataUrl'],
                $proofPayload['originalName'],
            );
        }

        return $storedProofs;
    }

    protected function storeProofFromDataUrl(string $dataUrl, string $originalName = ''): array
    {
        $dataUrl = trim($dataUrl);
        if ($dataUrl === '' || !str_starts_with($dataUrl, 'data:image/')) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Invalid proof image.',
            ], 422));
        }

        if (!preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUrl, $matches)) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Invalid proof image.',
            ], 422));
        }

        $mimeType = strtolower($matches[1]);
        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        if (!array_key_exists($mimeType, $allowedMimeTypes)) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Screenshot proof must be a JPG, PNG, WebP, or GIF image.',
            ], 422));
        }

        $binary = base64_decode($matches[2], true);
        if ($binary === false || strlen($binary) > 500 * 1024) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Screenshot proof must be 500KB or less.',
            ], 422));
        }

        $extension = $allowedMimeTypes[$mimeType];

        $folder = 'sales-inquiries/' . now()->format('Y/m');
        $filename = (string) Str::uuid() . '.' . $extension;
        $path = $folder . '/' . $filename;
        AppFilePaths::put($path, $binary);

        return [
            'path' => $path,
            'originalName' => trim($originalName) ?: 'inquiry-proof.' . $extension,
            'mimeType' => $mimeType,
            'fileSize' => strlen($binary),
        ];
    }

    protected function mapRow($row, ?array $proofs = null): array
    {
        $proofs ??= $this->proofRowsForInquiry((int) $row->id)->all();
        $mappedProofs = array_map(fn ($proof) => $this->mapProofRow($proof, (int) $row->id), $proofs);
        $firstProof = $mappedProofs[0] ?? null;

        return [
            'id' => (int) $row->id,
            'companyName' => (string) $row->company_name,
            'ssmNumber' => (string) ($row->ssm_number ?? ''),
            'taxIdNoTin' => (string) ($row->tax_id_no_tin ?? ''),
            'contactName' => (string) ($row->contact_name ?? ''),
            'mobile' => (string) ($row->mobile ?? ''),
            'email' => (string) ($row->email ?? ''),
            'address' => (string) ($row->address ?? ''),
            'city' => (string) ($row->city ?? ''),
            'state' => (string) ($row->state ?? ''),
            'zip' => (string) ($row->zip ?? ''),
            'serviceRequired' => (string) ($row->service_required ?? ''),
            'source' => (string) ($row->source ?? ''),
            'sourceRemarks' => (string) ($row->source_remarks ?? ''),
            'inquiryDate' => (string) $row->inquiry_date,
            'status' => (string) ($row->status ?? 'new'),
            'remarks' => (string) ($row->remarks ?? ''),
            'proofs' => $mappedProofs,
            'proofDataUrl' => (string) ($firstProof['url'] ?? ''),
            'proofOriginalName' => (string) ($firstProof['originalName'] ?? ''),
            'proofMimeType' => (string) ($firstProof['mimeType'] ?? ''),
            'proofCount' => count($mappedProofs),
            'clientId' => $row->client_id !== null ? (int) $row->client_id : null,
            'clientName' => (string) ($row->client_name ?? ''),
            'quoteId' => $row->quote_id !== null ? (int) $row->quote_id : null,
            'quoteRefNo' => (string) ($row->quote_ref_no ?? ''),
            'quoteServiceType' => (string) ($row->quote_service_type ?? ''),
            'ownerStaffId' => $row->owner_staff_id !== null ? (int) $row->owner_staff_id : null,
            'ownerStaffCode' => (string) ($row->owner_staff_code ?? ''),
            'ownerStaffName' => (string) ($row->owner_staff_name ?? ''),
            'ownerAssignedById' => $row->owner_assigned_by_id !== null ? (int) $row->owner_assigned_by_id : null,
            'ownerAssignedByCode' => (string) ($row->owner_assigned_by_code ?? ''),
            'ownerAssignedByName' => (string) ($row->owner_assigned_by_name ?? ''),
            'ownerAssignedAt' => (string) ($row->owner_assigned_at ?? ''),
            'createdByCode' => (string) ($row->created_by_code ?? ''),
            'createdAt' => (string) ($row->created_at ?? ''),
            'updatedAt' => (string) ($row->updated_at ?? ''),
        ];
    }

    protected function insertProofRows(int $inquiryId, array $proofs, $timestamp): void
    {
        if (empty($proofs) || !$this->proofTableReady()) {
            return;
        }

        $maxSortOrder = DB::table('sales_inquiry_proofs')
            ->where('sales_inquiry_id', $inquiryId)
            ->max('sort_order');
        $sortOrder = $maxSortOrder === null ? -1 : (int) $maxSortOrder;

        $rows = [];
        foreach ($proofs as $proof) {
            if (empty($proof['path'])) {
                continue;
            }

            $sortOrder += 1;
            $rows[] = [
                'sales_inquiry_id' => $inquiryId,
                'proof_path' => $proof['path'],
                'original_name' => $proof['originalName'],
                'mime_type' => $proof['mimeType'],
                'file_size' => $proof['fileSize'] ?? null,
                'sort_order' => $sortOrder,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if (!empty($rows)) {
            DB::table('sales_inquiry_proofs')->insert($rows);
        }
    }

    protected function proofsByInquiryIds(array $inquiryIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $inquiryIds)));
        if (empty($ids) || !$this->proofTableReady()) {
            return [];
        }

        return DB::table('sales_inquiry_proofs')
            ->whereNull('deleted_at')
            ->whereIn('sales_inquiry_id', $ids)
            ->orderBy('sales_inquiry_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('sales_inquiry_id')
            ->map(fn ($rows) => $rows->all())
            ->all();
    }

    protected function proofRowsForInquiry(int $inquiryId)
    {
        if (!$this->proofTableReady()) {
            return collect();
        }

        return DB::table('sales_inquiry_proofs')
            ->whereNull('deleted_at')
            ->where('sales_inquiry_id', $inquiryId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    protected function firstProofRow(int $inquiryId)
    {
        if (!$this->proofTableReady()) {
            return null;
        }

        return DB::table('sales_inquiry_proofs')
            ->whereNull('deleted_at')
            ->where('sales_inquiry_id', $inquiryId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    protected function mapProofRow($proof, int $inquiryId): array
    {
        return [
            'id' => (int) $proof->id,
            'url' => url('sales-inquiries/' . $inquiryId . '/proofs/' . (int) $proof->id),
            'originalName' => (string) ($proof->original_name ?? ''),
            'mimeType' => (string) ($proof->mime_type ?? ''),
            'fileSize' => $proof->file_size !== null ? (int) $proof->file_size : null,
            'sortOrder' => (int) ($proof->sort_order ?? 0),
        ];
    }

    protected function syncLegacyProofColumns(int $inquiryId): void
    {
        $proof = $this->firstProofRow($inquiryId);

        DB::table('sales_inquiries')->where('id', $inquiryId)->update([
            'proof_path' => $proof->proof_path ?? null,
            'proof_original_name' => $proof->original_name ?? null,
            'proof_mime_type' => $proof->mime_type ?? null,
            'updated_at' => now(),
        ]);
    }

    protected function deleteStoredProofFiles(array $proofs): void
    {
        foreach ($proofs as $proof) {
            if (!empty($proof['path'])) {
                AppFilePaths::deleteStoredPath((string) $proof['path']);
            }
        }
    }

    protected function deleteProofRowsFiles($proofRows): void
    {
        foreach ($proofRows as $proof) {
            if (!empty($proof->proof_path)) {
                AppFilePaths::deleteStoredPath((string) $proof->proof_path);
            }
        }
    }

    protected function tableReady(): bool
    {
        return Schema::hasTable('sales_inquiries');
    }

    protected function proofTableReady(): bool
    {
        return Schema::hasTable('sales_inquiry_proofs');
    }

    protected function success(mixed $data = null, ?string $message = null, int $statusCode = 200): JsonResponse
    {
        $payload = ['status' => 'success'];
        if ($message !== null) {
            $payload['message'] = $message;
        }
        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $statusCode);
    }

    protected function error(string $message, int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], $statusCode);
    }
}
