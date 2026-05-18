<?php

namespace App\Services\Debtors;

use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DebtorService
{
    private const OPEN_STATUS = 'Open';
    private const PAID_STATUS = 'Paid';
    private const CANCELLED_STATUS = 'Cancelled';

    public function __construct(private AuditLogService $auditLog)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $asOfDate = $this->asOfDate($request);
            $status = strtolower(trim((string) $request->query('status', 'open')));
            $source = strtolower(trim((string) $request->query('source', 'all')));
            $search = trim((string) $request->query('q', ''));

            $rows = [];
            if ($source === 'all' || $source === 'invoice') {
                $rows = array_merge($rows, $this->systemInvoiceRows($asOfDate, $status, $search));
            }
            if (($source === 'all' || $source === 'manual') && $this->manualDebtorsTableReady()) {
                $rows = array_merge($rows, $this->manualRows($asOfDate, $status, $search));
            }

            usort($rows, static function (array $left, array $right): int {
                $dateCompare = strcmp((string) ($left['invoiceDate'] ?? ''), (string) ($right['invoiceDate'] ?? ''));
                if ($dateCompare !== 0) {
                    return $dateCompare;
                }

                return strcmp((string) ($left['sourceId'] ?? ''), (string) ($right['sourceId'] ?? ''));
            });

            return response()->json([
                'status' => 'success',
                'asOfDate' => $asOfDate,
                'debtors' => array_values($rows),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function showManual(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->manualDebtorsTableReady()) {
                return $this->manualDebtorsNotReadyResponse();
            }

            $record = DB::table('manual_debtors')->where('id', $id)->first();
            if (! $record) {
                return response()->json(['status' => 'error', 'message' => 'Manual debtor not found.'], 404);
            }

            return response()->json([
                'status' => 'success',
                'debtor' => $this->normalizeManualRecord($record, $this->asOfDate($request)),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function storeManual(Request $request): JsonResponse
    {
        $storedAttachment = null;

        try {
            if (! $this->manualDebtorsTableReady()) {
                return $this->manualDebtorsNotReadyResponse();
            }

            $data = $this->validateManualPayload($request);
            $attachment = $this->storeAttachment($request);
            $storedAttachment = $attachment['path'] ?? null;

            $now = now();
            $id = DB::table('manual_debtors')->insertGetId([
                ...$this->manualPayloadColumns($data),
                'attachment_path' => $attachment['path'],
                'attachment_original_name' => $attachment['originalName'],
                'attachment_mime_type' => $attachment['mimeType'],
                'created_by' => (int) $request->session()->get('staff_id', 0) ?: null,
                'created_by_code' => trim((string) $request->session()->get('name_code', '')) ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->auditLog->log($request, "Created manual debtor {$data['invoice_ref_no']}");

            return response()->json(['status' => 'success', 'id' => $id], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($storedAttachment) {
                AppFilePaths::deleteStoredPath($storedAttachment);
            }
            return response()->json([
                'status' => 'error',
                'message' => $e->validator->errors()->first() ?: 'Invalid manual debtor.',
                'errors' => $e->validator->errors(),
            ], 422);
        } catch (\Throwable $e) {
            if ($storedAttachment) {
                AppFilePaths::deleteStoredPath($storedAttachment);
            }
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function updateManual(Request $request, int $id): JsonResponse
    {
        $storedAttachment = null;

        try {
            if (! $this->manualDebtorsTableReady()) {
                return $this->manualDebtorsNotReadyResponse();
            }

            $existing = DB::table('manual_debtors')->where('id', $id)->first();
            if (! $existing) {
                return response()->json(['status' => 'error', 'message' => 'Manual debtor not found.'], 404);
            }

            $data = $this->validateManualPayload($request, $id);
            $attachment = $this->storeAttachment($request);
            $storedAttachment = $attachment['path'] ?? null;
            $updates = [
                ...$this->manualPayloadColumns($data),
                'updated_at' => now(),
            ];

            if (! empty($attachment['path'])) {
                $updates['attachment_path'] = $attachment['path'];
                $updates['attachment_original_name'] = $attachment['originalName'];
                $updates['attachment_mime_type'] = $attachment['mimeType'];
            }

            DB::table('manual_debtors')->where('id', $id)->update($updates);

            if (! empty($attachment['path']) && ! empty($existing->attachment_path)) {
                AppFilePaths::deleteStoredPath((string) $existing->attachment_path);
            }

            $this->auditLog->log($request, "Updated manual debtor {$data['invoice_ref_no']}");

            return response()->json(['status' => 'success']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($storedAttachment) {
                AppFilePaths::deleteStoredPath($storedAttachment);
            }
            return response()->json([
                'status' => 'error',
                'message' => $e->validator->errors()->first() ?: 'Invalid manual debtor.',
                'errors' => $e->validator->errors(),
            ], 422);
        } catch (\Throwable $e) {
            if ($storedAttachment) {
                AppFilePaths::deleteStoredPath($storedAttachment);
            }
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function markManualPaid(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->manualDebtorsTableReady()) {
                return $this->manualDebtorsNotReadyResponse();
            }

            $validated = $request->validate([
                'paid_date' => ['required', 'date_format:Y-m-d'],
                'paid_amount' => ['required', 'numeric', 'gt:0'],
                'paid_remarks' => ['nullable', 'string', 'max:2000'],
            ]);

            $affected = DB::table('manual_debtors')->where('id', $id)->update([
                'status' => self::PAID_STATUS,
                'paid_date' => $validated['paid_date'],
                'paid_amount' => $validated['paid_amount'],
                'paid_remarks' => trim((string) ($validated['paid_remarks'] ?? '')) ?: null,
                'updated_at' => now(),
            ]);

            if ($affected < 1) {
                return response()->json(['status' => 'error', 'message' => 'Manual debtor not found.'], 404);
            }

            $this->auditLog->log($request, "Marked manual debtor ID {$id} as Paid");

            return response()->json(['status' => 'success']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->validator->errors()->first() ?: 'Invalid payment details.',
                'errors' => $e->validator->errors(),
            ], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function markManualOpen(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->manualDebtorsTableReady()) {
                return $this->manualDebtorsNotReadyResponse();
            }

            $affected = DB::table('manual_debtors')->where('id', $id)->update([
                'status' => self::OPEN_STATUS,
                'paid_date' => null,
                'paid_amount' => null,
                'paid_remarks' => null,
                'updated_at' => now(),
            ]);

            if ($affected < 1) {
                return response()->json(['status' => 'error', 'message' => 'Manual debtor not found.'], 404);
            }

            $this->auditLog->log($request, "Marked manual debtor ID {$id} as Open");

            return response()->json(['status' => 'success']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function destroyManual(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->manualDebtorsTableReady()) {
                return $this->manualDebtorsNotReadyResponse();
            }

            $record = DB::table('manual_debtors')->where('id', $id)->first();
            if (! $record) {
                return response()->json(['status' => 'error', 'message' => 'Manual debtor not found.'], 404);
            }

            DB::table('manual_debtors')->where('id', $id)->delete();
            if (! empty($record->attachment_path)) {
                AppFilePaths::deleteStoredPath((string) $record->attachment_path);
            }

            $this->auditLog->log($request, "Deleted manual debtor {$record->invoice_ref_no}");

            return response()->json(['status' => 'success']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function manualAttachment(int $id)
    {
        if (! $this->manualDebtorsTableReady()) {
            abort(503, 'Manual debtor storage is not ready. Please run database migrations.');
        }

        $record = DB::table('manual_debtors')->where('id', $id)->first();
        if (! $record || empty($record->attachment_path)) {
            abort(404);
        }

        return AppFilePaths::storedPathResponse(
            (string) $record->attachment_path,
            (string) ($record->attachment_original_name ?: basename((string) $record->attachment_path)),
        );
    }

    private function validateManualPayload(Request $request, ?int $ignoreId = null): array
    {
        $uniqueRule = 'unique:manual_debtors,invoice_ref_no';
        if ($ignoreId) {
            $uniqueRule .= ',' . $ignoreId;
        }

        $data = $request->validate([
            'invoice_ref_no' => ['required', 'string', 'max:191', $uniqueRule],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'pic_id' => ['nullable', 'integer', 'min:1'],
            'client_name' => ['required', 'string', 'max:191'],
            'pic_name' => ['nullable', 'string', 'max:2000'],
            'pic_phone' => ['nullable', 'string', 'max:1000'],
            'pic_email' => ['nullable', 'string', 'max:2000'],
            'service_type' => [
                'nullable',
                'string',
                'in:Training,Industrial Hygiene,Manpower Supply,Equipment Supply,Special Service',
            ],
            'service_period' => ['nullable', 'string', 'max:191'],
            'service_start_date' => ['nullable', 'date_format:Y-m-d'],
            'service_end_date' => ['nullable', 'date_format:Y-m-d'],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'grand_total' => ['required', 'numeric', 'gt:0'],
            'status' => ['nullable', 'in:Open,Paid,Cancelled'],
            'payment_method' => ['nullable', 'string', 'max:120'],
            'paid_date' => ['nullable', 'date_format:Y-m-d'],
            'paid_amount' => ['nullable', 'numeric', 'gt:0'],
            'paid_remarks' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $status = $data['status'] ?? self::OPEN_STATUS;
        if ($status === self::PAID_STATUS && (empty($data['paid_date']) || empty($data['paid_amount']))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'paid_date' => 'Paid manual debtors require payment date and amount.',
            ]);
        }

        if (
            ! empty($data['service_start_date'])
            && ! empty($data['service_end_date'])
            && $data['service_end_date'] < $data['service_start_date']
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'service_end_date' => 'Service end date must be on or after the start date.',
            ]);
        }

        $this->validateManualClientLink($data);

        return $data;
    }

    private function validateManualClientLink(array $data): void
    {
        $clientId = (int) ($data['client_id'] ?? 0);
        $picId = (int) ($data['pic_id'] ?? 0);

        if ($clientId > 0) {
            if (! Schema::hasTable('client_company')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'client_id' => 'Client records are not available.',
                ]);
            }

            $clientExists = DB::table('client_company')
                ->where('company_id', $clientId)
                ->when(Schema::hasColumn('client_company', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->exists();

            if (! $clientExists) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'client_id' => 'Selected client was not found.',
                ]);
            }
        }

        if ($picId <= 0) {
            return;
        }

        if ($clientId <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pic_id' => 'Select a client before selecting a PIC.',
            ]);
        }

        if (! Schema::hasTable('client_pic')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pic_id' => 'Client PIC records are not available.',
            ]);
        }

        $picExists = DB::table('client_pic')
            ->where('pic_id', $picId)
            ->where('company_id', $clientId)
            ->when(Schema::hasColumn('client_pic', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->exists();

        if (! $picExists) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pic_id' => 'Selected PIC does not belong to the selected client.',
            ]);
        }
    }

    private function manualPayloadColumns(array $data): array
    {
        $status = $data['status'] ?? self::OPEN_STATUS;
        $isPaid = $status === self::PAID_STATUS;

        return [
            'invoice_ref_no' => trim((string) $data['invoice_ref_no']),
            'client_id' => ! empty($data['client_id']) ? (int) $data['client_id'] : null,
            'pic_id' => ! empty($data['pic_id']) ? (int) $data['pic_id'] : null,
            'client_name' => trim((string) $data['client_name']),
            'pic_name' => trim((string) ($data['pic_name'] ?? '')) ?: null,
            'pic_phone' => trim((string) ($data['pic_phone'] ?? '')) ?: null,
            'pic_email' => trim((string) ($data['pic_email'] ?? '')) ?: null,
            'service_type' => trim((string) ($data['service_type'] ?? '')) ?: null,
            'service_period' => $this->manualServicePeriodLabel($data),
            'service_start_date' => ! empty($data['service_start_date']) ? Carbon::parse($data['service_start_date'])->format('Y-m-d') : null,
            'service_end_date' => ! empty($data['service_end_date']) ? Carbon::parse($data['service_end_date'])->format('Y-m-d') : null,
            'purpose' => trim((string) ($data['purpose'] ?? '')) ?: null,
            'invoice_date' => Carbon::parse($data['invoice_date'])->format('Y-m-d'),
            'grand_total' => (float) $data['grand_total'],
            'status' => $status,
            'payment_method' => trim((string) ($data['payment_method'] ?? '')) ?: null,
            'paid_date' => $isPaid && ! empty($data['paid_date']) ? Carbon::parse($data['paid_date'])->format('Y-m-d') : null,
            'paid_amount' => $isPaid && isset($data['paid_amount']) ? (float) $data['paid_amount'] : null,
            'paid_remarks' => $isPaid ? (trim((string) ($data['paid_remarks'] ?? '')) ?: null) : null,
        ];
    }

    private function manualServicePeriodLabel(array $data): ?string
    {
        $start = trim((string) ($data['service_start_date'] ?? ''));
        $end = trim((string) ($data['service_end_date'] ?? ''));

        if ($start !== '' && $end !== '') {
            return $start === $end ? $start : "{$start} - {$end}";
        }

        if ($start !== '') {
            return $start;
        }

        if ($end !== '') {
            return $end;
        }

        return trim((string) ($data['service_period'] ?? '')) ?: null;
    }

    private function storeAttachment(Request $request): array
    {
        if (! $request->hasFile('attachment')) {
            return ['path' => null, 'originalName' => null, 'mimeType' => null];
        }

        $file = $request->file('attachment');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = AppFilePaths::storeFileAs(
            'commercial-debtors/manual',
            $file,
            Str::uuid()->toString() . '.' . $extension,
        );

        return [
            'path' => $path,
            'originalName' => $file->getClientOriginalName(),
            'mimeType' => $file->getMimeType(),
        ];
    }

    private function systemInvoiceRows(string $asOfDate, string $status, string $search): array
    {
        if (! Schema::hasTable('invoices')) {
            return [];
        }

        $query = DB::table('invoices as i')
            ->leftJoin('client_company as cc', 'i.client_id', '=', 'cc.company_id')
            ->leftJoin('projects_main as pm', 'i.project_id', '=', 'pm.id')
            ->leftJoin('staff_general as sg', 'pm.created_by', '=', 'sg.staff_id')
            ->whereDate('i.invoice_date', '<=', $asOfDate)
            ->selectRaw("i.id, i.client_id, i.project_id, i.invoice_ref_no, i.invoice_date, i.grand_total, i.status, i.paid_date, i.paid_amount,
                COALESCE(NULLIF(i.invoice_client_name, ''), cc.company_name) AS client_name,
                COALESCE(NULLIF(i.invoice_pic_name, ''), '-') AS pic_name,
                COALESCE(NULLIF(i.invoice_pic_phone, ''), '') AS pic_phone,
                COALESCE(NULLIF(i.invoice_pic_email, ''), '') AS pic_email,
                COALESCE(NULLIF(i.invoice_purpose, ''), NULLIF(pm.project_name, '')) AS purpose,
                COALESCE(NULLIF(sg.name_code, ''), '-') AS internal_pic_code");

        if (Schema::hasColumn('invoices', 'service_type')) {
            $query->addSelect('i.service_type');
        }
        if (Schema::hasColumn('invoices', 'payment_method')) {
            $query->addSelect('i.payment_method');
        }
        if (Schema::hasColumn('invoices', 'paid_remarks')) {
            $query->addSelect('i.paid_remarks');
        }

        $this->applyStatusFilter($query, 'i.status', $status);
        $this->applySearchFilter($query, [
            'i.invoice_ref_no',
            'i.invoice_client_name',
            'cc.company_name',
            'i.invoice_pic_name',
            'i.invoice_purpose',
            'pm.project_name',
            'sg.name_code',
        ], $search);

        return $query->limit(2000)->get()->map(fn ($row) => $this->normalizeInvoiceRecord($row, $asOfDate))->all();
    }

    private function manualRows(string $asOfDate, string $status, string $search): array
    {
        $query = DB::table('manual_debtors')->whereDate('invoice_date', '<=', $asOfDate);

        $this->applyStatusFilter($query, 'status', $status);
        $this->applySearchFilter($query, [
            'invoice_ref_no',
            'client_name',
            'pic_name',
            'service_type',
            'purpose',
            'created_by_code',
        ], $search);

        return $query
            ->limit(2000)
            ->get()
            ->map(fn ($row) => $this->normalizeManualRecord($row, $asOfDate))
            ->all();
    }

    private function normalizeInvoiceRecord(object $row, string $asOfDate): array
    {
        $invoiceDate = (string) ($row->invoice_date ?? '');

        return [
            'sourceType' => 'invoice',
            'sourceId' => (int) $row->id,
            'invoiceRef' => (string) ($row->invoice_ref_no ?? "Invoice #{$row->id}"),
            'invoice_ref_no' => (string) ($row->invoice_ref_no ?? "Invoice #{$row->id}"),
            'client' => (string) ($row->client_name ?? '') ?: 'Client #' . (string) ($row->client_id ?? ''),
            'client_name' => (string) ($row->client_name ?? '') ?: 'Client #' . (string) ($row->client_id ?? ''),
            'pic' => (string) ($row->pic_name ?? '-'),
            'picPhone' => (string) ($row->pic_phone ?? ''),
            'picEmail' => (string) ($row->pic_email ?? ''),
            'serviceType' => (string) ($row->service_type ?? ''),
            'servicePeriod' => '',
            'purpose' => (string) ($row->purpose ?? '') ?: 'Project #' . (string) ($row->project_id ?? ''),
            'invoiceDate' => $invoiceDate,
            'invoice_date' => $invoiceDate,
            'ageDays' => $this->ageDays($invoiceDate, $asOfDate),
            'grandTotal' => (float) ($row->grand_total ?? 0),
            'grand_total' => (float) ($row->grand_total ?? 0),
            'status' => (string) ($row->status ?? ''),
            'paymentMethod' => (string) ($row->payment_method ?? ''),
            'paidDate' => (string) ($row->paid_date ?? ''),
            'paidAmount' => $row->paid_amount !== null ? (float) $row->paid_amount : null,
            'paidRemarks' => (string) ($row->paid_remarks ?? ''),
            'internalPicCode' => (string) ($row->internal_pic_code ?? '-'),
            'attachmentUrl' => '',
            'canEdit' => false,
            'canDelete' => false,
            'canMarkPaid' => ! $this->isClosedStatus((string) ($row->status ?? '')),
        ];
    }

    private function normalizeManualRecord(object $row, string $asOfDate): array
    {
        $invoiceDate = (string) ($row->invoice_date ?? '');
        $id = (int) $row->id;

        return [
            'sourceType' => 'manual',
            'sourceId' => $id,
            'invoiceRef' => (string) ($row->invoice_ref_no ?? "Manual #{$id}"),
            'invoice_ref_no' => (string) ($row->invoice_ref_no ?? "Manual #{$id}"),
            'clientId' => ! empty($row->client_id) ? (int) $row->client_id : null,
            'client_id' => ! empty($row->client_id) ? (int) $row->client_id : null,
            'picId' => ! empty($row->pic_id) ? (int) $row->pic_id : null,
            'pic_id' => ! empty($row->pic_id) ? (int) $row->pic_id : null,
            'client' => (string) ($row->client_name ?? '-'),
            'client_name' => (string) ($row->client_name ?? '-'),
            'pic' => (string) ($row->pic_name ?? '-'),
            'picPhone' => (string) ($row->pic_phone ?? ''),
            'picEmail' => (string) ($row->pic_email ?? ''),
            'serviceType' => (string) ($row->service_type ?? ''),
            'servicePeriod' => (string) ($row->service_period ?? ''),
            'serviceStartDate' => (string) ($row->service_start_date ?? ''),
            'service_start_date' => (string) ($row->service_start_date ?? ''),
            'serviceEndDate' => (string) ($row->service_end_date ?? ''),
            'service_end_date' => (string) ($row->service_end_date ?? ''),
            'purpose' => (string) ($row->purpose ?? ''),
            'invoiceDate' => $invoiceDate,
            'invoice_date' => $invoiceDate,
            'ageDays' => $this->ageDays($invoiceDate, $asOfDate),
            'grandTotal' => (float) ($row->grand_total ?? 0),
            'grand_total' => (float) ($row->grand_total ?? 0),
            'status' => (string) ($row->status ?? self::OPEN_STATUS),
            'paymentMethod' => (string) ($row->payment_method ?? ''),
            'paidDate' => (string) ($row->paid_date ?? ''),
            'paidAmount' => $row->paid_amount !== null ? (float) $row->paid_amount : null,
            'paidRemarks' => (string) ($row->paid_remarks ?? ''),
            'internalPicCode' => (string) ($row->created_by_code ?? ''),
            'attachmentUrl' => ! empty($row->attachment_path) ? url("debtors/manual/{$id}/attachment") : '',
            'attachmentOriginalName' => (string) ($row->attachment_original_name ?? ''),
            'attachmentMimeType' => (string) ($row->attachment_mime_type ?? ''),
            'canEdit' => true,
            'canDelete' => true,
            'canMarkPaid' => ! $this->isClosedStatus((string) ($row->status ?? '')),
        ];
    }

    private function applyStatusFilter($query, string $column, string $status): void
    {
        if ($status === '' || $status === 'open') {
            $query->whereRaw("LOWER(TRIM(COALESCE({$column}, 'open'))) NOT IN ('paid', 'cancelled', 'canceled', 'void')");
            return;
        }

        if ($status === 'all') {
            return;
        }

        if ($status === 'cancelled') {
            $query->whereRaw("LOWER(TRIM(COALESCE({$column}, ''))) IN ('cancelled', 'canceled', 'void')");
            return;
        }

        $query->whereRaw("LOWER(TRIM(COALESCE({$column}, ''))) = ?", [$status]);
    }

    private function applySearchFilter($query, array $columns, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function ($nested) use ($columns, $search): void {
            foreach ($columns as $column) {
                $nested->orWhere($column, 'like', '%' . $search . '%');
            }
        });
    }

    private function isClosedStatus(string $status): bool
    {
        $normalized = strtolower(trim($status));
        return in_array($normalized, ['paid', 'cancelled', 'canceled', 'void'], true);
    }

    private function ageDays(string $invoiceDate, string $asOfDate): int
    {
        try {
            return Carbon::parse($invoiceDate)->startOfDay()->diffInDays(Carbon::parse($asOfDate)->startOfDay(), false);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function asOfDate(Request $request): string
    {
        $raw = trim((string) ($request->query('as_of_date') ?: $request->query('end_date') ?: $request->input('as_of_date') ?: $request->input('end_date')));
        if ($raw === '') {
            return now()->format('Y-m-d');
        }

        try {
            return Carbon::parse($raw)->format('Y-m-d');
        } catch (\Throwable) {
            return now()->format('Y-m-d');
        }
    }

    private function manualDebtorsTableReady(): bool
    {
        return Schema::hasTable('manual_debtors');
    }

    private function manualDebtorsNotReadyResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Manual debtor storage is not ready. Please run database migrations.',
        ], 503);
    }
}
