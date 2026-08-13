<?php

namespace App\Services\Invoices;

use App\Services\Equipment\EquipmentCommercialSnapshotService;
use App\Services\Projects\ProjectValueService;
use App\Services\Receivables\ReceivablePaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class InvoiceMutationService extends InvoiceBaseService
{
    private const MONEY_TOLERANCE = 0.01;

    private function projectValueService(): ProjectValueService
    {
        return app(ProjectValueService::class);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'project_id' => 'required|integer|min:1',
            'service_type' => 'required|string',
            'breakdown' => 'required|array|min:1',
            'breakdown.*.item_description' => 'required|string|max:5000',
            'breakdown.*.description' => 'nullable|string|max:5000',
            'breakdown.*.item_remarks' => 'nullable|string|max:2000',
            'breakdown.*.line_type' => 'nullable|string|max:40',
            'breakdown.*.source_line_key' => 'nullable|string|max:120',
            'amount' => 'required|numeric|min:0',
            'sst_percent' => 'nullable|numeric|min:0|max:100',
            'sst_amount' => 'required|numeric|min:0',
            'grand_total' => 'required|numeric|min:0',
            'calculation_version' => 'nullable|string|max:80',
            'source_snapshot' => 'nullable|array',
            'deviation_reason' => 'nullable|string|max:2000',
            'deviation_acknowledged' => 'nullable|boolean',
            'quotation_remarks' => 'nullable|string|max:2000',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'override_payment_terms' => 'nullable|boolean',
            'close_project' => 'nullable|boolean',
        ]);

        $staffId = (int) $request->session()->get('staff_id', 0);
        $creatorCode = (string) $request->session()->get('name_code', '');

        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $projectId = (int) $request->input('project_id');
        $serviceType = trim((string) $request->input('service_type'));
        $serviceTypeLower = strtolower($serviceType);
        $isTrainingInvoice = $serviceTypeLower === 'training';
        $quoteIdRaw = $request->input('quote_id');
        $quoteId = ($quoteIdRaw !== null && $quoteIdRaw !== '') ? (int) $quoteIdRaw : null;
        $invoicePurpose = trim((string) $request->input('invoice_purpose', ''));
        $paymentMethod = trim((string) $request->input('payment_method', ''));
        $isHrdPayment = strcasecmp($paymentMethod, 'hrd grant') === 0;
        $grantNoInput = trim((string) $request->input('grant_approval_no', ''));
        $grantNo = $isTrainingInvoice && $isHrdPayment && $grantNoInput !== '' ? $grantNoInput : null;
        $breakdownInput = (array) $request->input('breakdown', []);
        $equipmentSnapshot = ['quotation_remarks' => null, 'items' => []];
        if (strcasecmp($serviceType, 'Equipment Supply') === 0) {
            $snapshotService = app(EquipmentCommercialSnapshotService::class);
            $equipmentSnapshot = $quoteId
                ? $snapshotService->forQuote($quoteId)
                : $snapshotService->forProject($projectId);
            $breakdownInput = $snapshotService->enrichItems($breakdownInput, $equipmentSnapshot);
        }
        $closeProject = filter_var($request->input('close_project', false), FILTER_VALIDATE_BOOLEAN);

        $isHrdLine = static fn (array $line): bool => (bool) preg_match(
            '/^\s*(\d+(?:\.\d+)?\s*%\s*)?hrd\s*charge\b/i',
            (string) ($line['item_description'] ?? '')
        );
        if ($isTrainingInvoice && ! $isHrdPayment) {
            $breakdownInput = array_values(
                array_filter($breakdownInput, fn ($line) => ! $isHrdLine((array) $line))
            );
        }

        // Duplicate grant_no check
        if ($grantNo) {
            $existing = DB::table('invoices')->where('grant_approval_no', $grantNo)->first(['id']);
            if ($existing) {
                return response()->json([
                    'status' => 'exists',
                    'invoice_id' => $existing->id,
                    'message' => 'This HRD Grant Approval No. is already used.',
                ]);
            }
        }

        // Duplicate invoice check (NULL-safe for quote_id)
        if (strtolower($serviceType) !== 'manpower supply') {
            $existing = DB::table('invoices')
                ->where('project_id', $projectId)
                ->where('service_type', $serviceType)
                ->where(function ($query) use ($quoteId): void {
                    if ($quoteId === null) {
                        $query->whereNull('quote_id');
                    } else {
                        $query->where('quote_id', $quoteId);
                    }
                })
                ->first(['id', 'grant_approval_no']);
        } else {
            $existing = DB::table('invoices')
                ->where('project_id', $projectId)
                ->where('service_type', $serviceType)
                ->where('invoice_purpose', $invoicePurpose)
                ->first(['id', 'grant_approval_no']);
        }

        if ($existing) {
            $existingGrant = trim((string) ($existing->grant_approval_no ?? ''));
            if ($grantNo !== '' && $existingGrant === '') {
                return response()->json([
                    'status' => 'exists',
                    'invoice_id' => $existing->id,
                    'message' => 'Invoice exists; cannot add HRD grant retrospectively.',
                ]);
            }

            return response()->json([
                'status' => 'exists',
                'invoice_id' => $existing->id,
                'message' => 'An invoice for this project & service already exists.',
            ]);
        }

        $resolvedAmount = (float) $request->input('amount', 0);
        $resolvedSstPercent = $this->submittedSstPercent($request);
        $resolvedSstAmount = (float) $request->input('sst_amount', 0);
        $resolvedGrandTotal = (float) $request->input('grand_total', 0);
        if ($this->isIndustrialHygieneService($serviceType)) {
            $calculated = $this->totalsCalculator()->calculateIndustrialHygienePayload(
                $breakdownInput,
                $resolvedSstPercent,
                $resolvedSstAmount,
                $resolvedGrandTotal,
            );
            if ($calculated['field_errors'] !== []) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'invoice_validation_failed',
                    'message' => 'Some invoice fields require attention.',
                    'field_errors' => $calculated['field_errors'],
                ], 422);
            }
            $resolvedAmount = $calculated['amount'];
            $resolvedSstPercent = $calculated['sst_percent'];
            $resolvedSstAmount = $calculated['sst_amount'];
            $resolvedGrandTotal = $calculated['grand_total'];
        }

        $totalError = $this->invoiceTotalValidationMessage(
            $serviceType,
            $breakdownInput,
            $resolvedAmount,
            $resolvedSstAmount,
            $resolvedGrandTotal
        );
        if ($totalError !== null) {
            return response()->json(['status' => 'error', 'message' => $totalError], 422);
        }

        $deviation = $this->invoiceDeviation($projectId, $resolvedGrandTotal);
        $deviationReason = trim((string) $request->input('deviation_reason', ''));
        $deviationAcknowledged = filter_var(
            $request->input('deviation_acknowledged', false),
            FILTER_VALIDATE_BOOLEAN,
        );
        if ($deviation['overage'] > self::MONEY_TOLERANCE && $this->usesLegacyInvoiceContract($request)) {
            $deviationReason = $this->legacyDeviationReason($deviation);
            $deviationAcknowledged = true;
        }
        if ($deviationResponse = $this->deviationErrorResponse(
            $deviation,
            $deviationReason,
            $deviationAcknowledged,
        )) {
            return $deviationResponse;
        }
        $sourceSnapshot = array_merge((array) $request->input('source_snapshot', []), [
            'project_id' => $projectId,
            'project_value' => $deviation['project_value'],
            'previously_invoiced' => $deviation['previously_invoiced'],
            'compatibility_mode' => $this->usesLegacyInvoiceContract($request)
                ? 'legacy_invoice_payload_v1'
                : null,
        ]);

        $yearFull = date('Y');
        $yearTwo = date('y');
        $lockName = "invoices_{$yearFull}";

        $lockAcquired = false;

        try {
            $lockAcquired = $this->acquireInvoiceYearLock($lockName);
            DB::beginTransaction();

            $projectColumns = ['client_id'];
            if (Schema::hasColumn('projects_main', 'proposal_language')) {
                $projectColumns[] = 'proposal_language';
            }
            $projectRow = DB::table('projects_main')->where('id', $projectId)->first($projectColumns);
            $clientId = $projectRow->client_id ?? null;
            $documentLanguage = $this->normalizeDocumentLanguage($projectRow->proposal_language ?? 'en');
            $invoiceDate = $request->input('invoice_date', date('Y-m-d'));
            $paymentTerms = $this->resolvePaymentTerms($request, $clientId);
            $paymentTermsDays = $paymentTerms['days'];
            $dueDate = $this->dueDateFor($invoiceDate, $paymentTermsDays);

            $maxRun = (int) DB::table('invoices')
                ->whereYear('created_at', $yearFull)
                ->where('invoice_ref_no', 'like', "INV{$yearTwo}-%")
                ->whereBetween('invoice_running_no', [1, 9999])
                ->max('invoice_running_no');
            $runningNo = $maxRun + 1;
            $padded = str_pad((string) $runningNo, 4, '0', STR_PAD_LEFT);
            $refNo = "INV{$yearTwo}-{$padded}{$creatorCode}";

            $insert = [
                'project_id' => $projectId,
                'client_id' => $clientId,
                'invoice_loa_no' => $request->input('client_award_ref_no'),
                'invoice_client_name' => $request->input('invoice_client_name'),
                'invoice_client_ssm' => $request->input('invoice_client_ssm'),
                'invoice_client_tin' => $request->input('invoice_client_tin'),
                'invoice_client_address' => $request->input('invoice_client_address'),
                'invoice_client_city' => $request->input('invoice_client_city'),
                'invoice_client_state' => $request->input('invoice_client_state'),
                'invoice_client_zip' => $request->input('invoice_client_zip'),
                'invoice_pic_name' => $request->input('invoice_pic_name'),
                'invoice_pic_phone' => $request->input('invoice_pic_phone'),
                'invoice_pic_email' => $request->input('invoice_pic_email'),
                'invoice_pic_position' => $request->input('invoice_pic_position'),
                'service_type' => $serviceType,
                'quote_id' => $quoteId,
                'created_by' => $staffId,
                'invoice_ref_no' => $refNo,
                'invoice_running_no' => $runningNo,
                'invoice_purpose' => $invoicePurpose,
                'invoice_date' => $invoiceDate,
                'payment_terms_days' => $paymentTermsDays,
                'payment_terms_source' => $paymentTerms['source'],
                'due_date' => $dueDate,
                'amount' => $resolvedAmount,
                'sst_amount' => $resolvedSstAmount,
                'grand_total' => $resolvedGrandTotal,
                'payment_method' => $paymentMethod,
                'grant_approval_no' => $grantNo,
                'remarks' => $request->input('remarks', ''),
                'status' => 'Pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('invoices', 'document_language')) {
                $insert['document_language'] = $documentLanguage;
            }
            if (Schema::hasColumn('invoices', 'sst_percent')) {
                $insert['sst_percent'] = $resolvedSstPercent;
            }
            if (Schema::hasColumn('invoices', 'calculation_version')) {
                $insert['calculation_version'] = $this->isIndustrialHygieneService($serviceType)
                    ? InvoiceTotalsCalculator::VERSION
                    : 'legacy_service_v1';
            }
            if (Schema::hasColumn('invoices', 'source_snapshot')) {
                $insert['source_snapshot'] = json_encode($sourceSnapshot, JSON_THROW_ON_ERROR);
            }
            if (Schema::hasColumn('invoices', 'deviation_reason') && $deviation['overage'] > self::MONEY_TOLERANCE) {
                $insert['deviation_reason'] = $deviationReason;
                $insert['deviation_acknowledged_by'] = $staffId;
                $insert['deviation_acknowledged_at'] = now();
            }
            if (Schema::hasColumn('invoices', 'quotation_remarks')) {
                $insert['quotation_remarks'] = $request->exists('quotation_remarks')
                    ? $request->input('quotation_remarks')
                    : ($equipmentSnapshot['quotation_remarks'] ?? null);
            }

            $invoiceId = DB::table('invoices')->insertGetId($insert);
            $this->markClientOldIfEligible($clientId);

            foreach ($breakdownInput as $i => $line) {
                $qty = (float) ($line['quantity'] ?? 1);
                $uprice = (float) ($line['unit_price'] ?? 0);
                $breakdownInsert = [
                    'invoice_id' => $invoiceId,
                    'item_description' => $line['item_description'] ?? '',
                    'description' => $line['description'] ?? null,
                    'unit' => $line['unit'] ?? 'Lot',
                    'quantity' => $qty,
                    'unit_price' => $uprice,
                    'subtotal' => $qty * $uprice,
                    'sort_order' => $i + 1,
                ];
                if (Schema::hasColumn('invoice_breakdown', 'item_remarks')) {
                    $breakdownInsert['item_remarks'] = $line['item_remarks'] ?? null;
                }
                if (Schema::hasColumn('invoice_breakdown', 'line_type')) {
                    $breakdownInsert['line_type'] = $this->totalsCalculator()->lineType((array) $line);
                }
                if (Schema::hasColumn('invoice_breakdown', 'source_line_key')) {
                    $breakdownInsert['source_line_key'] = $line['source_line_key'] ?? null;
                }
                DB::table('invoice_breakdown')->insert($breakdownInsert);
            }

            $this->insertProjectProgress($projectId, "Invoice {$refNo} created.", $request);
            $projectClosed = $closeProject
                ? $this->closeProjectAfterInvoice($projectId, $refNo, $invoiceDate, $staffId, $request)
                : false;
            $this->auditLog->log($request, "Created invoice {$refNo} (service: {$serviceType}) for project {$projectId}");

            DB::commit();
            $this->releaseInvoiceYearLock($lockName, $lockAcquired);

            return response()->json([
                'status' => 'success',
                'invoice_id' => $invoiceId,
                'invoice_ref_no' => $refNo,
                'project_closed' => $projectClosed,
            ]);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->releaseInvoiceYearLock($lockName, $lockAcquired);
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $invoiceRef = trim((string) $request->input('invoice_ref_no', ''));
        $dateIssued = $request->input('invoice_date');
        $status = trim((string) $request->input('status', ''));
        $paymentMethod = trim((string) $request->input('payment_method', ''));
        $isHrdPayment = strcasecmp($paymentMethod, 'hrd grant') === 0;
        $grantNoInput = trim((string) $request->input('grant_approval_no', ''));
        $isHrdLine = static fn (array $line): bool => (bool) preg_match(
            '/^\s*(\d+(?:\.\d+)?\s*%\s*)?hrd\s*charge\b/i',
            (string) ($line['item_description'] ?? '')
        );
        $breakdownInput = (array) $request->input('breakdown', []);
        $grantNo = null;

        $request->validate([
            'breakdown' => 'required|array|min:1',
            'breakdown.*.item_description' => 'required|string|max:5000',
            'breakdown.*.description' => 'nullable|string|max:5000',
            'breakdown.*.item_remarks' => 'nullable|string|max:2000',
            'breakdown.*.line_type' => 'nullable|string|max:40',
            'breakdown.*.source_line_key' => 'nullable|string|max:120',
            'amount' => 'required|numeric|min:0',
            'sst_percent' => 'nullable|numeric|min:0|max:100',
            'sst_amount' => 'required|numeric|min:0',
            'grand_total' => 'required|numeric|min:0',
            'calculation_version' => 'nullable|string|max:80',
            'quotation_remarks' => 'nullable|string|max:2000',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'override_payment_terms' => 'nullable|boolean',
            'deviation_reason' => 'nullable|string|max:2000',
            'deviation_acknowledged' => 'nullable|boolean',
        ]);

        if ($invoiceRef === '' || ! $dateIssued || $status === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing required fields.'], 422);
        }

        $existingInvoice = DB::table('invoices')->where('invoice_ref_no', $invoiceRef)->first([
            'id',
            'project_id',
            'service_type',
            'client_id',
            'payment_terms_days',
            'payment_terms_source',
            'amount',
            'sst_amount',
            'grand_total',
            'invoice_date',
            'status',
            'paid_date',
            'paid_amount',
            'paid_remarks',
        ]);
        if (! $existingInvoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found.'], 404);
        }

        $isTrainingInvoice = strcasecmp($existingInvoice->service_type ?? '', 'training') === 0;
        if ($isTrainingInvoice && $isHrdPayment) {
            if ($grantNoInput === '') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'HRD Grant Approval No. is required for HRD payment.',
                ], 422);
            }
            $grantNo = $grantNoInput !== '' ? $grantNoInput : null;
        }

        if ($isTrainingInvoice && $grantNo !== null) {
            $existingGrant = DB::table('invoices')
                ->where('id', '!=', $existingInvoice->id)
                ->where('grant_approval_no', $grantNo)
                ->first(['id']);
            if ($existingGrant) {
                return response()->json([
                    'status' => 'exists',
                    'invoice_id' => $existingGrant->id,
                    'message' => 'This HRD Grant Approval No. is already used.',
                ]);
            }
        }

        if ($isTrainingInvoice && ! $isHrdPayment) {
            $breakdownInput = array_values(
                array_filter($breakdownInput, fn ($line) => ! $isHrdLine((array) $line))
            );
        }

        $resolvedAmount = (float) $request->input('amount', 0);
        $resolvedSstPercent = $this->submittedSstPercent($request);
        $resolvedSstAmount = (float) $request->input('sst_amount', 0);
        $resolvedGrandTotal = (float) $request->input('grand_total', 0);
        if ($this->isIndustrialHygieneService((string) $existingInvoice->service_type)) {
            $calculated = $this->totalsCalculator()->calculateIndustrialHygienePayload(
                $breakdownInput,
                $resolvedSstPercent,
                $resolvedSstAmount,
                $resolvedGrandTotal,
            );
            if ($calculated['field_errors'] !== []) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'invoice_validation_failed',
                    'message' => 'Some invoice fields require attention.',
                    'field_errors' => $calculated['field_errors'],
                ], 422);
            }
            $resolvedAmount = $calculated['amount'];
            $resolvedSstPercent = $calculated['sst_percent'];
            $resolvedSstAmount = $calculated['sst_amount'];
            $resolvedGrandTotal = $calculated['grand_total'];
        }

        $totalError = $this->invoiceTotalValidationMessage(
            (string) $existingInvoice->service_type,
            $breakdownInput,
            $resolvedAmount,
            $resolvedSstAmount,
            $resolvedGrandTotal
        );
        if ($totalError !== null) {
            return response()->json(['status' => 'error', 'message' => $totalError], 422);
        }

        $financialInputsChanged = $this->financialInputsChanged(
            $existingInvoice,
            $breakdownInput,
            $resolvedAmount,
            $resolvedSstAmount,
            $resolvedGrandTotal,
            (string) $dateIssued,
        );
        $deviation = $financialInputsChanged
            ? $this->invoiceDeviation(
                (int) $existingInvoice->project_id,
                $resolvedGrandTotal,
                (int) $existingInvoice->id,
            )
            : null;
        $deviationReason = trim((string) $request->input('deviation_reason', ''));
        $deviationAcknowledged = filter_var(
            $request->input('deviation_acknowledged', false),
            FILTER_VALIDATE_BOOLEAN,
        );
        if (($deviation['overage'] ?? 0) > self::MONEY_TOLERANCE
            && $this->usesLegacyInvoiceContract($request)) {
            $deviationReason = $this->legacyDeviationReason($deviation);
            $deviationAcknowledged = true;
        }
        $paymentService = app(ReceivablePaymentService::class);
        $ledgerSummary = $paymentService->summariesFor('invoice', [(int) $existingInvoice->id]);
        $paymentSummary = $paymentService->calculateSummary(
            $existingInvoice->grand_total ?? 0,
            $existingInvoice->paid_amount ?? null,
            $existingInvoice->paid_date ?? null,
            $ledgerSummary[(int) $existingInvoice->id] ?? null,
        );
        $statusLower = strtolower(trim((string) ($existingInvoice->status ?? '')));
        $financiallyLocked = $paymentSummary['paidTotal'] > self::MONEY_TOLERANCE
            || in_array($statusLower, ['paid', 'cancelled', 'canceled', 'void'], true);
        if ($financiallyLocked && $financialInputsChanged) {
            return response()->json([
                'status' => 'error',
                'code' => 'invoice_financials_locked',
                'message' => $paymentSummary['paidTotal'] > self::MONEY_TOLERANCE
                    ? 'Financial values cannot be changed because payment has already been recorded.'
                    : 'Financial values cannot be changed for a cancelled or void invoice.',
                'allowed_actions' => ['view_invoice', 'view_payment'],
            ], 422);
        }
        if ($deviation !== null
            && ($deviationResponse = $this->deviationErrorResponse(
                $deviation,
                $deviationReason,
                $deviationAcknowledged,
            ))) {
            return $deviationResponse;
        }
        if ($resolvedGrandTotal + self::MONEY_TOLERANCE < $paymentSummary['paidTotal']) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invoice total cannot be less than the amount already paid.',
            ], 422);
        }
        $hasPaymentLedger = Schema::hasTable('receivable_payments')
            && DB::table('receivable_payments')
                ->where('source_type', 'invoice')
                ->where('source_id', (int) $existingInvoice->id)
                ->exists();
        $earliestPaymentDate = $hasPaymentLedger
            ? DB::table('receivable_payments')
                ->where('source_type', 'invoice')
                ->where('source_id', (int) $existingInvoice->id)
                ->whereNull('reversed_at')
                ->min('payment_date')
            : ($existingInvoice->paid_date ?? null);
        if ($earliestPaymentDate && (string) $dateIssued > (string) $earliestPaymentDate) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invoice date cannot be after an existing payment date.',
            ], 422);
        }

        $paymentTerms = $this->resolvePaymentTerms($request, $existingInvoice->client_id ?? null, $existingInvoice);
        $paymentTermsDays = $paymentTerms['days'];

        try {
            DB::beginTransaction();
            $existingInvoice = DB::table('invoices')
                ->where('id', (int) $existingInvoice->id)
                ->lockForUpdate()
                ->first();
            if (! $existingInvoice) {
                abort(404, 'Invoice not found.');
            }
            $lockedLedgerSummary = $paymentService->summariesFor('invoice', [(int) $existingInvoice->id]);
            $lockedPaymentSummary = $paymentService->calculateSummary(
                $existingInvoice->grand_total ?? 0,
                $existingInvoice->paid_amount ?? null,
                $existingInvoice->paid_date ?? null,
                $lockedLedgerSummary[(int) $existingInvoice->id] ?? null,
            );
            $lockedStatus = strtolower(trim((string) ($existingInvoice->status ?? '')));
            $lockedFinancials = $lockedPaymentSummary['paidTotal'] > self::MONEY_TOLERANCE
                || in_array($lockedStatus, ['paid', 'cancelled', 'canceled', 'void'], true);
            if ($lockedFinancials && $this->financialInputsChanged(
                $existingInvoice,
                $breakdownInput,
                $resolvedAmount,
                $resolvedSstAmount,
                $resolvedGrandTotal,
                (string) $dateIssued,
            )) {
                DB::rollBack();

                return response()->json([
                    'status' => 'error',
                    'code' => 'invoice_financials_locked',
                    'message' => $lockedPaymentSummary['paidTotal'] > self::MONEY_TOLERANCE
                        ? 'Financial values cannot be changed because payment has already been recorded.'
                        : 'Financial values cannot be changed for a cancelled or void invoice.',
                    'allowed_actions' => ['view_invoice', 'view_payment'],
                ], 422);
            }
            if ($resolvedGrandTotal + self::MONEY_TOLERANCE < $lockedPaymentSummary['paidTotal']) {
                abort(422, 'Invoice total cannot be less than the amount already paid.');
            }
            $lockedHasPaymentLedger = Schema::hasTable('receivable_payments')
                && DB::table('receivable_payments')
                    ->where('source_type', 'invoice')
                    ->where('source_id', (int) $existingInvoice->id)
                    ->exists();
            $lockedEarliestPaymentDate = $lockedHasPaymentLedger
                ? DB::table('receivable_payments')
                    ->where('source_type', 'invoice')
                    ->where('source_id', (int) $existingInvoice->id)
                    ->whereNull('reversed_at')
                    ->min('payment_date')
                : ($existingInvoice->paid_date ?? null);
            if ($lockedEarliestPaymentDate && (string) $dateIssued > (string) $lockedEarliestPaymentDate) {
                abort(422, 'Invoice date cannot be after an existing payment date.');
            }

            $invoiceUpdates = [
                'invoice_loa_no' => $request->input('invoice_loa_no'),
                'invoice_client_name' => $request->input('invoice_client_name'),
                'invoice_client_ssm' => $request->input('invoice_client_ssm'),
                'invoice_client_tin' => $request->input('invoice_client_tin'),
                'invoice_client_address' => $request->input('invoice_client_address'),
                'invoice_client_city' => $request->input('invoice_client_city'),
                'invoice_client_state' => $request->input('invoice_client_state'),
                'invoice_client_zip' => $request->input('invoice_client_zip'),
                'invoice_pic_name' => $request->input('invoice_pic_name'),
                'invoice_pic_phone' => $request->input('invoice_pic_phone'),
                'invoice_pic_email' => $request->input('invoice_pic_email'),
                'invoice_pic_position' => $request->input('invoice_pic_position'),
                'invoice_purpose' => $request->input('invoice_purpose', ''),
                'invoice_date' => $dateIssued,
                'payment_terms_days' => $paymentTermsDays,
                'payment_terms_source' => $paymentTerms['source'],
                'due_date' => $this->dueDateFor($dateIssued, $paymentTermsDays),
                'status' => $existingInvoice->status,
                'amount' => $resolvedAmount,
                'sst_amount' => $resolvedSstAmount,
                'grand_total' => $resolvedGrandTotal,
                'payment_method' => $request->input('payment_method', ''),
                'grant_approval_no' => $grantNo,
                'paid_date' => $existingInvoice->paid_date,
                'paid_amount' => $existingInvoice->paid_amount,
                'paid_remarks' => $existingInvoice->paid_remarks,
                'remarks' => $request->input('remarks', ''),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('invoices', 'quotation_remarks') && $request->exists('quotation_remarks')) {
                $invoiceUpdates['quotation_remarks'] = $request->input('quotation_remarks');
            }
            if (Schema::hasColumn('invoices', 'sst_percent')) {
                $invoiceUpdates['sst_percent'] = $resolvedSstPercent;
            }
            if (Schema::hasColumn('invoices', 'calculation_version')
                && $this->isIndustrialHygieneService((string) $existingInvoice->service_type)) {
                $invoiceUpdates['calculation_version'] = InvoiceTotalsCalculator::VERSION;
            }
            if ($financialInputsChanged && Schema::hasColumn('invoices', 'deviation_reason')) {
                $hasDeviation = ($deviation['overage'] ?? 0) > self::MONEY_TOLERANCE;
                $invoiceUpdates['deviation_reason'] = $hasDeviation ? $deviationReason : null;
                $invoiceUpdates['deviation_acknowledged_by'] = $hasDeviation
                    ? ((int) $request->session()->get('staff_id', 0) ?: null)
                    : null;
                $invoiceUpdates['deviation_acknowledged_at'] = $hasDeviation ? now() : null;
            }
            DB::table('invoices')->where('invoice_ref_no', $invoiceRef)->limit(1)->update($invoiceUpdates);

            $invId = $existingInvoice->id;
            if ($invId) {
                if (Schema::hasColumn('invoice_breakdown', 'item_remarks')) {
                    $existingItems = DB::table('invoice_breakdown')
                        ->where('invoice_id', $invId)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->get(['item_description', 'item_remarks']);
                    $breakdownInput = app(EquipmentCommercialSnapshotService::class)
                        ->preserveMissingItemRemarks($breakdownInput, $existingItems);
                }
                DB::table('invoice_breakdown')->where('invoice_id', $invId)->delete();

                foreach ($breakdownInput as $i => $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    $qty = (float) ($line['quantity'] ?? 0);
                    $price = (float) ($line['unit_price'] ?? 0);
                    $breakdownInsert = [
                        'invoice_id' => $invId,
                        'item_description' => $line['item_description'] ?? '',
                        'description' => $line['description'] ?? null,
                        'unit' => $line['unit'] ?? 'Lot',
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'subtotal' => round($qty * $price, 2),
                        'sort_order' => $i + 1,
                    ];
                    if (Schema::hasColumn('invoice_breakdown', 'item_remarks')) {
                        $breakdownInsert['item_remarks'] = $line['item_remarks'] ?? null;
                    }
                    if (Schema::hasColumn('invoice_breakdown', 'line_type')) {
                        $breakdownInsert['line_type'] = $this->totalsCalculator()->lineType($line);
                    }
                    if (Schema::hasColumn('invoice_breakdown', 'source_line_key')) {
                        $breakdownInsert['source_line_key'] = $line['source_line_key'] ?? null;
                    }
                    DB::table('invoice_breakdown')->insert($breakdownInsert);
                }
            }

            $isCancelled = in_array(
                strtolower(trim((string) ($existingInvoice->status ?? ''))),
                ['cancelled', 'canceled', 'void'],
                true,
            );
            if (! $isCancelled && ($lockedHasPaymentLedger || $lockedPaymentSummary['paidTotal'] > 0)) {
                $paymentService->synchronizeProjection('invoice', (int) $existingInvoice->id);
            }

            $oldGrandTotal = round((float) ($existingInvoice->grand_total ?? 0), 2);
            $auditMessage = "Updated invoice {$invoiceRef}";
            if (abs($oldGrandTotal - $resolvedGrandTotal) > self::MONEY_TOLERANCE) {
                $auditMessage .= sprintf(
                    ' (grand total RM %s to RM %s)',
                    number_format($oldGrandTotal, 2, '.', ','),
                    number_format($resolvedGrandTotal, 2, '.', ','),
                );
            }
            $this->auditLog->log($request, $auditMessage);
            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'Invoice updated successfully.']);
        } catch (HttpExceptionInterface $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    private function resolvePaymentTerms(Request $request, mixed $clientId, ?object $existingInvoice = null): array
    {
        $overrideRequested = filter_var($request->input('override_payment_terms', false), FILTER_VALIDATE_BOOLEAN);
        if ($overrideRequested && $request->has('payment_terms_days')) {
            return [
                'days' => $this->normalizePaymentTermsDays($request->input('payment_terms_days')),
                'source' => self::PAYMENT_TERMS_SOURCE_INVOICE_OVERRIDE,
            ];
        }

        if ($existingInvoice !== null && ! $request->has('override_payment_terms')) {
            return [
                'days' => $this->normalizePaymentTermsDays($existingInvoice->payment_terms_days ?? self::SYSTEM_DEFAULT_PAYMENT_TERMS_DAYS),
                'source' => $this->normalizePaymentTermsSource($existingInvoice->payment_terms_source ?? self::PAYMENT_TERMS_SOURCE_LEGACY),
            ];
        }

        $clientId = (int) ($clientId ?? 0);
        if ($clientId > 0) {
            $days = DB::table('client_company')
                ->where('company_id', $clientId)
                ->value('payment_terms_days');

            if ($days !== null && $days !== '') {
                return [
                    'days' => $this->normalizePaymentTermsDays($days),
                    'source' => self::PAYMENT_TERMS_SOURCE_CLIENT,
                ];
            }
        }

        return [
            'days' => self::SYSTEM_DEFAULT_PAYMENT_TERMS_DAYS,
            'source' => self::PAYMENT_TERMS_SOURCE_SYSTEM_DEFAULT,
        ];
    }

    private function submittedSstPercent(Request $request): ?float
    {
        if (! $request->exists('sst_percent') || $request->input('sst_percent') === '') {
            return null;
        }

        return (float) $request->input('sst_percent');
    }

    private function usesLegacyInvoiceContract(Request $request): bool
    {
        // The original invoice client supplied neither a calculation version nor
        // an SST rate. A short-lived current client supplied the SST rate before
        // it started sending the version marker, so treat that shape as current
        // to preserve its guided deviation validation during cached rollouts.
        return ! $request->filled('calculation_version') && ! $request->exists('sst_percent');
    }

    private function legacyDeviationReason(array $deviation): string
    {
        return sprintf(
            'Legacy invoice client compatibility: submitted total is RM %s above the remaining project value.',
            number_format((float) ($deviation['overage'] ?? 0), 2),
        );
    }

    private function financialInputsChanged(
        object $invoice,
        array $submittedBreakdown,
        float $amount,
        float $sstAmount,
        float $grandTotal,
        string $invoiceDate,
    ): bool {
        if (abs((float) ($invoice->amount ?? 0) - $amount) > self::MONEY_TOLERANCE
            || abs((float) ($invoice->sst_amount ?? 0) - $sstAmount) > self::MONEY_TOLERANCE
            || abs((float) ($invoice->grand_total ?? 0) - $grandTotal) > self::MONEY_TOLERANCE
            || (string) ($invoice->invoice_date ?? '') !== $invoiceDate) {
            return true;
        }

        if (! Schema::hasTable('invoice_breakdown')) {
            return false;
        }

        $stored = DB::table('invoice_breakdown')
            ->where('invoice_id', (int) $invoice->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['quantity', 'unit_price']);
        $submitted = collect($submittedBreakdown)
            ->filter(fn ($line): bool => is_array($line))
            ->values();

        if ($stored->count() !== $submitted->count()) {
            return true;
        }

        foreach ($stored as $index => $line) {
            $candidate = (array) $submitted->get($index, []);
            if (abs((float) $line->quantity - (float) ($candidate['quantity'] ?? 0)) > self::MONEY_TOLERANCE
                || abs((float) $line->unit_price - (float) ($candidate['unit_price'] ?? 0)) > self::MONEY_TOLERANCE) {
                return true;
            }
        }

        return false;
    }

    private function invoiceDeviation(int $projectId, float $invoiceTotal, ?int $excludeInvoiceId = null): array
    {
        $project = DB::table('projects_main')->where('id', $projectId)->first();
        $projectValue = $this->projectValueService()->resolvedValue($project);
        $invoiceQuery = DB::table('invoices')
            ->where('project_id', $projectId)
            ->whereRaw("LOWER(COALESCE(status, '')) NOT IN (?, ?, ?)", ['cancelled', 'canceled', 'void']);
        if ($excludeInvoiceId !== null) {
            $invoiceQuery->where('id', '!=', $excludeInvoiceId);
        }
        $alreadyInvoiced = (float) $invoiceQuery->sum('grand_total');
        $hasProjectValue = $projectValue > self::MONEY_TOLERANCE;
        $remaining = $hasProjectValue ? max(0, round($projectValue - $alreadyInvoiced, 2)) : 0.0;

        return [
            'project_value' => round($projectValue, 2),
            'previously_invoiced' => round($alreadyInvoiced, 2),
            'invoice_total' => round($invoiceTotal, 2),
            'remaining_value' => $remaining,
            'overage' => $hasProjectValue ? round(max(0, $invoiceTotal - $remaining), 2) : 0.0,
        ];
    }

    private function deviationErrorResponse(
        array $deviation,
        string $deviationReason,
        bool $deviationAcknowledged,
    ): ?JsonResponse {
        if ($deviation['overage'] <= self::MONEY_TOLERANCE
            || ($deviationReason !== '' && $deviationAcknowledged)) {
            return null;
        }

        $fieldErrors = [];
        if ($deviationReason === '') {
            $fieldErrors['deviation_reason'] = ['Briefly explain why this invoice exceeds the project value.'];
        }
        if (! $deviationAcknowledged) {
            $fieldErrors['deviation_acknowledged'] = ['Confirm the project-value difference to continue.'];
        }

        return response()->json([
            'status' => 'error',
            'code' => 'invoice_over_project_value',
            'message' => sprintf(
                'This invoice is RM %s above the remaining project value.',
                number_format($deviation['overage'], 2),
            ),
            'field_errors' => $fieldErrors,
            'context' => $deviation,
            'allowed_actions' => ['acknowledge_and_continue', 'return_to_pricing', 'view_project'],
        ], 422);
    }

    private function normalizePaymentTermsDays(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::SYSTEM_DEFAULT_PAYMENT_TERMS_DAYS;
        }

        return max(0, min(365, (int) $value));
    }

    private function normalizePaymentTermsSource(mixed $value): string
    {
        $source = trim((string) $value);

        return in_array($source, [
            self::PAYMENT_TERMS_SOURCE_SYSTEM_DEFAULT,
            self::PAYMENT_TERMS_SOURCE_CLIENT,
            self::PAYMENT_TERMS_SOURCE_INVOICE_OVERRIDE,
            self::PAYMENT_TERMS_SOURCE_LEGACY,
        ], true)
            ? $source
            : self::PAYMENT_TERMS_SOURCE_LEGACY;
    }

    private function dueDateFor(mixed $invoiceDate, int $paymentTermsDays): string
    {
        try {
            return CarbonImmutable::parse((string) $invoiceDate)
                ->addDays($paymentTermsDays)
                ->toDateString();
        } catch (\Throwable) {
            return CarbonImmutable::today()
                ->addDays($paymentTermsDays)
                ->toDateString();
        }
    }

    private function closeProjectAfterInvoice(
        int $projectId,
        string $invoiceRef,
        mixed $invoiceDate,
        int $staffId,
        Request $request
    ): bool {
        if (
            ! Schema::hasTable('projects_main') ||
            ! Schema::hasColumn('projects_main', 'status') ||
            ! Schema::hasColumn('projects_main', 'quote_value')
        ) {
            return false;
        }

        $projectColumns = ['id', 'status', 'quote_value'];
        if (Schema::hasColumn('projects_main', 'current_project_value')) {
            $projectColumns[] = 'current_project_value';
        }

        $project = DB::table('projects_main')
            ->where('id', $projectId)
            ->lockForUpdate()
            ->first($projectColumns);

        if (! $project || strtolower(trim((string) ($project->status ?? ''))) !== 'active') {
            return false;
        }

        $quoteValue = $this->projectValueService()->resolvedValue($project);
        if ($quoteValue <= 0 || ! $this->isProjectFullyInvoiced($projectId, $quoteValue)) {
            return false;
        }

        $closeDate = $this->dateForProjectClose($invoiceDate);
        $reason = "No further invoice expected after invoice {$invoiceRef}.";

        if (Schema::hasTable('project_closing_details')) {
            DB::table('project_closing_details')->insert([
                'project_id' => $projectId,
                'close_date' => $closeDate,
                'close_type' => 'Completed',
                'reason' => $reason,
                'claims_ok' => 0,
                'vendors_ok' => 0,
                'services_ok' => 0,
                'closed_by' => $staffId,
                'closed_at' => now(),
            ]);
        }

        $projectUpdates = ['status' => 'Completed'];
        if (Schema::hasColumn('projects_main', 'updated_at')) {
            $projectUpdates['updated_at'] = now();
        }

        DB::table('projects_main')->where('id', $projectId)->update($projectUpdates);

        $nameCode = Schema::hasTable('staff_general') && Schema::hasColumn('staff_general', 'name_code')
            ? (DB::table('staff_general')->where('staff_id', $staffId)->value('name_code') ?: "STAFF#{$staffId}")
            : "STAFF#{$staffId}";

        $this->insertProjectProgress(
            $projectId,
            "Project marked as Completed by {$nameCode}; no further invoice expected after invoice {$invoiceRef}.",
            $request
        );
        $this->auditLog->log($request, "Project ID #{$projectId} was marked as Completed after invoice {$invoiceRef}");

        return true;
    }

    private function isProjectFullyInvoiced(int $projectId, float $quoteValue): bool
    {
        $billedTotal = (float) DB::table('invoices')
            ->where('project_id', $projectId)
            ->whereRaw("LOWER(COALESCE(status, '')) NOT LIKE ?", ['%void%'])
            ->whereRaw("LOWER(COALESCE(status, '')) NOT LIKE ?", ['%cancel%'])
            ->sum('grand_total');

        return ($quoteValue - $billedTotal) <= self::MONEY_TOLERANCE;
    }

    private function dateForProjectClose(mixed $invoiceDate): string
    {
        try {
            return CarbonImmutable::parse((string) $invoiceDate)->toDateString();
        } catch (\Throwable) {
            return CarbonImmutable::today()->toDateString();
        }
    }

    private function markClientOldIfEligible(mixed $clientId): void
    {
        $clientId = (int) ($clientId ?? 0);
        if ($clientId <= 0) {
            return;
        }

        $query = DB::table('client_company')
            ->where('company_id', $clientId)
            ->where(function ($statusQuery): void {
                $statusQuery
                    ->whereNull('client_status')
                    ->orWhereRaw("TRIM(COALESCE(client_status, '')) = ''")
                    ->orWhereRaw("LOWER(TRIM(client_status)) = 'new'");
            });

        if (Schema::hasColumn('client_company', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $update = ['client_status' => 'Old'];
        if (Schema::hasColumn('client_company', 'updated_at')) {
            $update['updated_at'] = now();
        }

        $query->update($update);
    }

    private function acquireInvoiceYearLock(string $lockName): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        DB::statement('SELECT GET_LOCK(?, 10)', [$lockName]);

        return true;
    }

    private function releaseInvoiceYearLock(string $lockName, bool $lockAcquired): void
    {
        if (! $lockAcquired || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('DO RELEASE_LOCK(?)', [$lockName]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $invoiceRef = trim((string) $request->input('invoice_ref_no', ''));
        if ($invoiceRef === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing invoice_ref_no'], 422);
        }

        $invoice = DB::table('invoices')
            ->where('invoice_ref_no', $invoiceRef)
            ->first();

        if (! $invoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found'], 404);
        }

        if (strtolower($invoice->status) !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'Only invoices with status "Pending" can be deleted.'], 422);
        }

        $paymentService = app(ReceivablePaymentService::class);
        $ledgerSummary = $paymentService->summariesFor('invoice', [(int) $invoice->id]);
        $paymentSummary = $paymentService->calculateSummary(
            $invoice->grand_total ?? 0,
            $invoice->paid_amount ?? null,
            $invoice->paid_date ?? null,
            $ledgerSummary[(int) $invoice->id] ?? null,
        );
        if ($paymentSummary['paidTotal'] > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reverse all payments before deleting this invoice.',
            ], 422);
        }
        $reason = trim((string) ($validated['reason'] ?? '')) ?: 'Pending invoice deleted through invoice workflow.';

        try {
            DB::beginTransaction();
            $invoice = DB::table('invoices')
                ->where('invoice_ref_no', $invoiceRef)
                ->lockForUpdate()
                ->first();
            if (! $invoice) {
                abort(404, 'Invoice not found');
            }
            if (strtolower((string) $invoice->status) !== 'pending') {
                abort(422, 'Only invoices with status "Pending" can be deleted.');
            }
            $lockedLedgerSummary = $paymentService->summariesFor('invoice', [(int) $invoice->id]);
            $lockedPaymentSummary = $paymentService->calculateSummary(
                $invoice->grand_total ?? 0,
                $invoice->paid_amount ?? null,
                $invoice->paid_date ?? null,
                $lockedLedgerSummary[(int) $invoice->id] ?? null,
            );
            if ($lockedPaymentSummary['paidTotal'] > 0) {
                abort(422, 'Reverse all payments before deleting this invoice.');
            }

            $payments = $paymentService->deletePaymentsFor('invoice', (int) $invoice->id);
            $paymentService->writeDeletionAudit(
                $request,
                'invoice',
                $invoice,
                $reason,
                $payments,
            );
            DB::table('invoice_breakdown')->where('invoice_id', $invoice->id)->delete();
            DB::table('invoice_payment_reminder_logs')->where('invoice_id', $invoice->id)->delete();
            DB::table('invoices')->where('id', $invoice->id)->delete();

            if ($invoice->project_id) {
                $this->insertProjectProgress(
                    (int) $invoice->project_id,
                    "Invoice with reference no. {$invoiceRef} was deleted.",
                    $request
                );
            }

            $this->auditLog->log($request, "Deleted invoice {$invoiceRef}");
            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'Invoice deleted successfully.']);
        } catch (HttpExceptionInterface $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }
}
