<?php

namespace App\Services\Debtors;

use App\Services\AuditLogService;
use App\Services\Receivables\ReceivablePaymentService;
use App\Support\AppFilePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DebtorService
{
    private const OPEN_STATUS = 'Open';

    public function __construct(
        private AuditLogService $auditLog,
        private ReceivablePaymentService $paymentService,
    ) {}

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
        } catch (ValidationException $e) {
            if ($storedAttachment) {
                AppFilePaths::deleteStoredPath($storedAttachment);
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->validator->errors()->first() ?: 'Invalid manual debtor.',
                'errors' => $e->validator->errors(),
            ], 422);
        } catch (HttpExceptionInterface $e) {
            if ($storedAttachment) {
                AppFilePaths::deleteStoredPath($storedAttachment);
            }

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
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
            $previousAttachmentPath = null;
            DB::transaction(function () use ($id, $data, $attachment, &$previousAttachmentPath): void {
                $lockedExisting = DB::table('manual_debtors')->where('id', $id)->lockForUpdate()->first();
                if (! $lockedExisting) {
                    abort(404, 'Manual debtor not found.');
                }
                $this->validateManualPaymentConsistency($id, $data);

                $updates = [
                    ...$this->manualPayloadColumns($data, $lockedExisting),
                    'updated_at' => now(),
                ];
                if (! empty($attachment['path'])) {
                    $updates['attachment_path'] = $attachment['path'];
                    $updates['attachment_original_name'] = $attachment['originalName'];
                    $updates['attachment_mime_type'] = $attachment['mimeType'];
                    $previousAttachmentPath = (string) ($lockedExisting->attachment_path ?? '');
                }

                DB::table('manual_debtors')->where('id', $id)->update($updates);
                $hasPaymentState = (float) ($lockedExisting->paid_amount ?? 0) > 0
                    || (Schema::hasTable('receivable_payments')
                        && DB::table('receivable_payments')
                            ->where('source_type', 'manual')
                            ->where('source_id', $id)
                            ->exists());
                if (! $this->isCancelledStatus((string) ($lockedExisting->status ?? '')) && $hasPaymentState) {
                    $this->paymentService->synchronizeProjection('manual', $id);
                }
            });

            if (! empty($attachment['path']) && $previousAttachmentPath !== '') {
                try {
                    AppFilePaths::deleteStoredPath($previousAttachmentPath);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $this->auditLog->log($request, "Updated manual debtor {$data['invoice_ref_no']}");

            return response()->json(['status' => 'success']);
        } catch (ValidationException $e) {
            if ($storedAttachment) {
                AppFilePaths::deleteStoredPath($storedAttachment);
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->validator->errors()->first() ?: 'Invalid manual debtor.',
                'errors' => $e->validator->errors(),
            ], 422);
        } catch (HttpExceptionInterface $e) {
            if ($storedAttachment) {
                AppFilePaths::deleteStoredPath($storedAttachment);
            }

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
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
        return $this->paymentService->recordPayment($request, 'manual', $id, true);
    }

    public function markManualOpen(Request $request, int $id): JsonResponse
    {
        $request->merge(['reason' => $request->input('reason', 'Reopened through legacy mark-open action')]);

        return $this->paymentService->reverseAllPayments($request, 'manual', $id);
    }

    public function destroyManual(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->manualDebtorsTableReady()) {
                return $this->manualDebtorsNotReadyResponse();
            }

            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:2000'],
            ]);
            $record = null;
            DB::transaction(function () use ($request, $id, $validated, &$record): void {
                $record = DB::table('manual_debtors')->where('id', $id)->lockForUpdate()->first();
                if (! $record) {
                    abort(404, 'Manual debtor not found.');
                }

                $payments = $this->paymentService->deletePaymentsFor('manual', $id);
                $this->paymentService->writeDeletionAudit(
                    $request,
                    'manual',
                    $record,
                    trim((string) $validated['reason']),
                    $payments,
                );
                DB::table('manual_debtors')->where('id', $id)->delete();
            });

            if (! empty($record->attachment_path)) {
                try {
                    AppFilePaths::deleteStoredPath((string) $record->attachment_path);
                } catch (\Throwable $e) {
                    // The database deletion is already committed; report orphan cleanup separately.
                    report($e);
                }
            }

            $this->auditLog->log($request, "Deleted manual debtor {$record->invoice_ref_no}");

            return response()->json(['status' => 'success']);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->validator->errors()->first() ?: 'Deletion reason is required.',
                'errors' => $e->validator->errors(),
            ], 422);
        } catch (HttpExceptionInterface $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
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
        $normalizedReference = preg_replace('/\s+/', ' ', trim((string) $request->input('invoice_ref_no', '')));
        $request->merge(['invoice_ref_no' => $normalizedReference]);
        $uniqueRule = 'unique:manual_debtors,invoice_ref_no';
        if ($ignoreId) {
            $uniqueRule .= ','.$ignoreId;
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
            'override_payment_terms' => ['nullable', 'boolean'],
            'payment_terms_changed' => ['nullable', 'boolean'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'grand_total' => ['required', 'numeric', 'gt:0'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if (
            ! empty($data['service_start_date'])
            && ! empty($data['service_end_date'])
            && $data['service_end_date'] < $data['service_start_date']
        ) {
            throw ValidationException::withMessages([
                'service_end_date' => 'Service end date must be on or after the start date.',
            ]);
        }

        if (
            filter_var($data['override_payment_terms'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && ! isset($data['payment_terms_days'])
        ) {
            throw ValidationException::withMessages([
                'payment_terms_days' => 'Custom payment terms are required when overriding client terms.',
            ]);
        }

        $this->validateManualClientLink($data);

        $duplicateManual = DB::table('manual_debtors')
            ->whereRaw('LOWER(TRIM(invoice_ref_no)) = ?', [mb_strtolower($normalizedReference)])
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
        if ($duplicateManual) {
            throw ValidationException::withMessages([
                'invoice_ref_no' => 'This manual invoice reference already exists.',
            ]);
        }

        if (
            Schema::hasTable('invoices')
            && DB::table('invoices')
                ->whereRaw('LOWER(TRIM(invoice_ref_no)) = ?', [mb_strtolower($normalizedReference)])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'invoice_ref_no' => 'This invoice reference already exists as a system invoice.',
            ]);
        }

        if ($ignoreId) {
            $this->validateManualPaymentConsistency($ignoreId, $data);
        }

        return $data;
    }

    private function validateManualPaymentConsistency(int $id, array $data): void
    {
        $record = DB::table('manual_debtors')->where('id', $id)->first();
        if (! $record) {
            return;
        }

        $hasLedger = Schema::hasTable('receivable_payments')
            && DB::table('receivable_payments')
                ->where('source_type', 'manual')
                ->where('source_id', $id)
                ->exists();
        $paidTotal = $hasLedger
            ? (float) DB::table('receivable_payments')
                ->where('source_type', 'manual')
                ->where('source_id', $id)
                ->whereNull('reversed_at')
                ->sum('amount')
            : (float) ($record->paid_amount ?? 0);
        if ((float) $data['grand_total'] + 0.00001 < $paidTotal) {
            throw ValidationException::withMessages([
                'grand_total' => 'Invoice total cannot be less than the amount already paid.',
            ]);
        }

        $earliestPaymentDate = $hasLedger
            ? DB::table('receivable_payments')
                ->where('source_type', 'manual')
                ->where('source_id', $id)
                ->whereNull('reversed_at')
                ->min('payment_date')
            : ($record->paid_date ?? null);
        if ($earliestPaymentDate && (string) $data['invoice_date'] > (string) $earliestPaymentDate) {
            throw ValidationException::withMessages([
                'invoice_date' => 'Invoice date cannot be after an existing payment date.',
            ]);
        }
    }

    private function validateManualClientLink(array $data): void
    {
        $clientId = (int) ($data['client_id'] ?? 0);
        $picId = (int) ($data['pic_id'] ?? 0);

        if ($clientId > 0) {
            if (! Schema::hasTable('client_company')) {
                throw ValidationException::withMessages([
                    'client_id' => 'Client records are not available.',
                ]);
            }

            $clientExists = DB::table('client_company')
                ->where('company_id', $clientId)
                ->when(Schema::hasColumn('client_company', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->exists();

            if (! $clientExists) {
                throw ValidationException::withMessages([
                    'client_id' => 'Selected client was not found.',
                ]);
            }
        }

        if ($picId <= 0) {
            return;
        }

        if ($clientId <= 0) {
            throw ValidationException::withMessages([
                'pic_id' => 'Select a client before selecting a PIC.',
            ]);
        }

        if (! Schema::hasTable('client_pic')) {
            throw ValidationException::withMessages([
                'pic_id' => 'Client PIC records are not available.',
            ]);
        }

        $picExists = DB::table('client_pic')
            ->where('pic_id', $picId)
            ->where('company_id', $clientId)
            ->when(Schema::hasColumn('client_pic', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->exists();

        if (! $picExists) {
            throw ValidationException::withMessages([
                'pic_id' => 'Selected PIC does not belong to the selected client.',
            ]);
        }
    }

    private function manualPayloadColumns(array $data, ?object $existing = null): array
    {
        $status = $existing ? (string) ($existing->status ?? self::OPEN_STATUS) : self::OPEN_STATUS;
        $paymentTerms = $this->resolveManualPaymentTerms($data, $existing);

        $payload = [
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
        ];

        if (! $existing) {
            $payload['payment_method'] = null;
            $payload['paid_date'] = null;
            $payload['paid_amount'] = null;
            $payload['paid_remarks'] = null;
        }

        if (Schema::hasColumn('manual_debtors', 'payment_terms_days')) {
            $payload['payment_terms_days'] = $paymentTerms['days'];
        }
        if (Schema::hasColumn('manual_debtors', 'payment_terms_source')) {
            $payload['payment_terms_source'] = $paymentTerms['source'];
        }
        if (Schema::hasColumn('manual_debtors', 'due_date')) {
            $payload['due_date'] = $paymentTerms['due_date'];
        }

        return $payload;
    }

    private function resolveManualPaymentTerms(array $data, ?object $existing = null): array
    {
        $invoiceDate = Carbon::parse($data['invoice_date'])->startOfDay();
        $override = filter_var($data['override_payment_terms'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $paymentTermsChanged = filter_var($data['payment_terms_changed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $inputDays = isset($data['payment_terms_days']) && $data['payment_terms_days'] !== ''
            ? max(0, min(365, (int) $data['payment_terms_days']))
            : null;

        if ($existing && ! $paymentTermsChanged) {
            $existingDays = property_exists($existing, 'payment_terms_days') && $existing->payment_terms_days !== null
                ? max(0, min(365, (int) $existing->payment_terms_days))
                : null;
            $existingSource = property_exists($existing, 'payment_terms_source')
                ? (string) ($existing->payment_terms_source ?? 'legacy')
                : 'legacy';

            return [
                'days' => $existingDays,
                'source' => $existingSource ?: 'legacy',
                'due_date' => $existingDays !== null
                    ? $invoiceDate->copy()->addDays($existingDays)->format('Y-m-d')
                    : null,
            ];
        }

        if ($override) {
            $days = $inputDays ?? 30;

            return [
                'days' => $days,
                'source' => 'manual_override',
                'due_date' => $invoiceDate->copy()->addDays($days)->format('Y-m-d'),
            ];
        }

        $clientId = (int) ($data['client_id'] ?? 0);
        if ($clientId > 0 && Schema::hasTable('client_company') && Schema::hasColumn('client_company', 'payment_terms_days')) {
            $clientTerms = DB::table('client_company')
                ->where('company_id', $clientId)
                ->value('payment_terms_days');
            $days = $clientTerms === null ? 30 : max(0, min(365, (int) $clientTerms));

            return [
                'days' => $days,
                'source' => $clientTerms === null ? 'system_default' : 'client',
                'due_date' => $invoiceDate->copy()->addDays($days)->format('Y-m-d'),
            ];
        }

        return [
            'days' => null,
            'source' => 'legacy',
            'due_date' => null,
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
            Str::uuid()->toString().'.'.$extension,
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
                COALESCE(i.payment_terms_days, cc.payment_terms_days, 30) AS payment_terms_days,
                COALESCE(NULLIF(i.payment_terms_source, ''), CASE WHEN cc.payment_terms_days IS NULL THEN 'system_default' ELSE 'client' END) AS payment_terms_source,
                i.due_date AS due_date,
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

        $this->applySearchFilter($query, [
            'i.invoice_ref_no',
            'i.invoice_client_name',
            'cc.company_name',
            'i.invoice_pic_name',
            'i.invoice_purpose',
            'pm.project_name',
            'sg.name_code',
        ], $search);

        $records = $query->get();
        $summaries = $this->paymentService->summariesFor(
            'invoice',
            $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $asOfDate,
        );

        return $this->filterNormalizedRows(
            $records->map(fn ($row) => $this->normalizeInvoiceRecord(
                $row,
                $asOfDate,
                $summaries[(int) $row->id] ?? null,
            ))->all(),
            $status,
        );
    }

    private function manualRows(string $asOfDate, string $status, string $search): array
    {
        $query = DB::table('manual_debtors')->whereDate('invoice_date', '<=', $asOfDate);

        $this->applySearchFilter($query, [
            'invoice_ref_no',
            'client_name',
            'pic_name',
            'service_type',
            'purpose',
            'created_by_code',
        ], $search);

        $records = $query->get();
        $summaries = $this->paymentService->summariesFor(
            'manual',
            $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $asOfDate,
        );

        return $this->filterNormalizedRows(
            $records->map(fn ($row) => $this->normalizeManualRecord(
                $row,
                $asOfDate,
                $summaries[(int) $row->id] ?? null,
            ))->all(),
            $status,
        );
    }

    private function normalizeInvoiceRecord(object $row, string $asOfDate, ?array $ledgerSummary = null): array
    {
        $invoiceDate = (string) ($row->invoice_date ?? '');
        $paymentTermsDays = (int) ($row->payment_terms_days ?? 30);
        $dueDate = (string) ($row->due_date ?? '');
        if ($dueDate === '' && $invoiceDate !== '') {
            try {
                $dueDate = Carbon::parse($invoiceDate)->startOfDay()->addDays($paymentTermsDays)->format('Y-m-d');
            } catch (\Throwable) {
                $dueDate = '';
            }
        }

        $paymentSummary = $this->paymentService->calculateSummary(
            $row->grand_total ?? 0,
            $row->paid_amount ?? null,
            $row->paid_date ?? null,
            $ledgerSummary,
            $asOfDate,
        );
        $recordStatus = (string) ($row->status ?? '');
        $displayStatus = $this->isCancelledStatus($recordStatus)
            ? $recordStatus
            : $paymentSummary['paymentStatus'];

        return [
            'sourceType' => 'invoice',
            'sourceId' => (int) $row->id,
            'invoiceRef' => (string) ($row->invoice_ref_no ?? "Invoice #{$row->id}"),
            'invoice_ref_no' => (string) ($row->invoice_ref_no ?? "Invoice #{$row->id}"),
            'client' => (string) ($row->client_name ?? '') ?: 'Client #'.(string) ($row->client_id ?? ''),
            'client_name' => (string) ($row->client_name ?? '') ?: 'Client #'.(string) ($row->client_id ?? ''),
            'pic' => (string) ($row->pic_name ?? '-'),
            'picPhone' => (string) ($row->pic_phone ?? ''),
            'picEmail' => (string) ($row->pic_email ?? ''),
            'serviceType' => (string) ($row->service_type ?? ''),
            'servicePeriod' => '',
            'purpose' => (string) ($row->purpose ?? '') ?: 'Project #'.(string) ($row->project_id ?? ''),
            'invoiceDate' => $invoiceDate,
            'invoice_date' => $invoiceDate,
            'paymentTermsDays' => $paymentTermsDays,
            'payment_terms_days' => $paymentTermsDays,
            'paymentTermsSource' => (string) ($row->payment_terms_source ?? 'legacy'),
            'payment_terms_source' => (string) ($row->payment_terms_source ?? 'legacy'),
            'dueDate' => $dueDate,
            'due_date' => $dueDate,
            'ageDays' => $this->ageDays($invoiceDate, $asOfDate),
            'overdueDays' => $this->ageDays($dueDate, $asOfDate),
            'grandTotal' => (float) ($row->grand_total ?? 0),
            'grand_total' => (float) ($row->grand_total ?? 0),
            'status' => $displayStatus,
            'paymentStatus' => $paymentSummary['paymentStatus'],
            'paidTotal' => $paymentSummary['paidTotal'],
            'outstandingAmount' => $paymentSummary['outstandingAmount'],
            'paymentCount' => $paymentSummary['paymentCount'],
            'lastPaymentDate' => $paymentSummary['lastPaymentDate'],
            'hasPaymentHistory' => $paymentSummary['hasPaymentHistory'],
            'paymentMethod' => (string) ($row->payment_method ?? ''),
            'paidDate' => $paymentSummary['lastPaymentDate'],
            'paidAmount' => $paymentSummary['paidTotal'],
            'paidRemarks' => (string) ($row->paid_remarks ?? ''),
            'internalPicCode' => (string) ($row->internal_pic_code ?? '-'),
            'attachmentUrl' => '',
            'canEdit' => false,
            'canDelete' => false,
            'canMarkPaid' => ! $this->isCancelledStatus($recordStatus)
                && $paymentSummary['outstandingAmount'] > 0,
        ];
    }

    private function normalizeManualRecord(object $row, string $asOfDate, ?array $ledgerSummary = null): array
    {
        $invoiceDate = (string) ($row->invoice_date ?? '');
        $hasPaymentTerms = property_exists($row, 'payment_terms_days') && $row->payment_terms_days !== null;
        $paymentTermsSource = property_exists($row, 'payment_terms_source')
            ? (string) ($row->payment_terms_source ?? 'legacy')
            : 'legacy';
        $dueDate = property_exists($row, 'due_date') ? (string) ($row->due_date ?? '') : '';
        $id = (int) $row->id;

        $paymentSummary = $this->paymentService->calculateSummary(
            $row->grand_total ?? 0,
            $row->paid_amount ?? null,
            $row->paid_date ?? null,
            $ledgerSummary,
            $asOfDate,
        );
        $recordStatus = (string) ($row->status ?? self::OPEN_STATUS);
        $displayStatus = $this->isCancelledStatus($recordStatus)
            ? $recordStatus
            : $paymentSummary['paymentStatus'];

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
            'paymentTermsDays' => $hasPaymentTerms ? (int) $row->payment_terms_days : null,
            'payment_terms_days' => $hasPaymentTerms ? (int) $row->payment_terms_days : null,
            'paymentTermsSource' => $paymentTermsSource,
            'payment_terms_source' => $paymentTermsSource,
            'dueDate' => $dueDate,
            'due_date' => $dueDate,
            'ageDays' => $this->ageDays($invoiceDate, $asOfDate),
            'overdueDays' => $dueDate !== '' ? $this->ageDays($dueDate, $asOfDate) : null,
            'grandTotal' => (float) ($row->grand_total ?? 0),
            'grand_total' => (float) ($row->grand_total ?? 0),
            'status' => $displayStatus,
            'paymentStatus' => $paymentSummary['paymentStatus'],
            'paidTotal' => $paymentSummary['paidTotal'],
            'outstandingAmount' => $paymentSummary['outstandingAmount'],
            'paymentCount' => $paymentSummary['paymentCount'],
            'lastPaymentDate' => $paymentSummary['lastPaymentDate'],
            'hasPaymentHistory' => $paymentSummary['hasPaymentHistory'],
            'paymentMethod' => (string) ($row->payment_method ?? ''),
            'paidDate' => $paymentSummary['lastPaymentDate'],
            'paidAmount' => $paymentSummary['paidTotal'],
            'paidRemarks' => (string) ($row->paid_remarks ?? ''),
            'internalPicCode' => (string) ($row->created_by_code ?? ''),
            'attachmentUrl' => ! empty($row->attachment_path) ? url("debtors/manual/{$id}/attachment") : '',
            'attachmentOriginalName' => (string) ($row->attachment_original_name ?? ''),
            'attachmentMimeType' => (string) ($row->attachment_mime_type ?? ''),
            'canEdit' => true,
            'canDelete' => true,
            'canMarkPaid' => ! $this->isCancelledStatus($recordStatus)
                && $paymentSummary['outstandingAmount'] > 0,
        ];
    }

    private function filterNormalizedRows(array $rows, string $status): array
    {
        $status = strtolower(trim($status));
        if ($status === '' || $status === 'open') {
            return array_values(array_filter($rows, fn (array $row): bool => ! $this->isClosedStatus((string) ($row['status'] ?? ''))
                && (float) ($row['outstandingAmount'] ?? 0) > 0
            ));
        }
        if ($status === 'all') {
            return array_values($rows);
        }
        if ($status === 'cancelled') {
            return array_values(array_filter($rows, fn (array $row): bool => in_array(strtolower(trim((string) ($row['status'] ?? ''))), ['cancelled', 'canceled', 'void'], true)
            ));
        }

        return array_values(array_filter($rows, fn (array $row): bool => strtolower(trim((string) ($row['status'] ?? ''))) === $status
        ));
    }

    private function applySearchFilter($query, array $columns, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function ($nested) use ($columns, $search): void {
            foreach ($columns as $column) {
                $nested->orWhere($column, 'like', '%'.$search.'%');
            }
        });
    }

    private function isClosedStatus(string $status): bool
    {
        $normalized = strtolower(trim($status));

        return in_array($normalized, ['paid', 'cancelled', 'canceled', 'void'], true);
    }

    private function isCancelledStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['cancelled', 'canceled', 'void'], true);
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
