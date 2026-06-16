<?php

namespace App\Services\Invoices;

use App\Services\Projects\ProjectValueService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        $quoteIdRaw = $request->input('quote_id');
        $quoteId = ($quoteIdRaw !== null && $quoteIdRaw !== '') ? (int) $quoteIdRaw : null;
        $invoicePurpose = trim((string) $request->input('invoice_purpose', ''));
        $grantNo = trim((string) $request->input('grant_approval_no', ''));
        $closeProject = filter_var($request->input('close_project', false), FILTER_VALIDATE_BOOLEAN);

        // Duplicate grant_no check
        if ($grantNo !== '') {
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
            $existing = DB::selectOne(
                'SELECT id, grant_approval_no FROM invoices WHERE project_id = ? AND service_type = ? AND quote_id <=> ? LIMIT 1',
                [$projectId, $serviceType, $quoteId]
            );
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

        $totalError = $this->invoiceTotalValidationMessage(
            $serviceType,
            (array) $request->input('breakdown', []),
            (float) $request->input('amount', 0),
            (float) $request->input('sst_amount', 0),
            (float) $request->input('grand_total', 0)
        );
        if ($totalError !== null) {
            return response()->json(['status' => 'error', 'message' => $totalError], 422);
        }

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
                'amount' => $request->input('amount', 0),
                'sst_amount' => $request->input('sst_amount', 0),
                'grand_total' => $request->input('grand_total', 0),
                'payment_method' => $request->input('payment_method', ''),
                'grant_approval_no' => $grantNo,
                'remarks' => $request->input('remarks', ''),
                'status' => 'Pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('invoices', 'document_language')) {
                $insert['document_language'] = $documentLanguage;
            }

            $invoiceId = DB::table('invoices')->insertGetId($insert);
            $this->markClientOldIfEligible($clientId);

            foreach ((array) $request->input('breakdown') as $i => $line) {
                $qty = (float) ($line['quantity'] ?? 1);
                $uprice = (float) ($line['unit_price'] ?? 0);
                DB::table('invoice_breakdown')->insert([
                    'invoice_id' => $invoiceId,
                    'item_description' => $line['item_description'] ?? '',
                    'description' => $line['description'] ?? null,
                    'unit' => $line['unit'] ?? 'Lot',
                    'quantity' => $qty,
                    'unit_price' => $uprice,
                    'subtotal' => $qty * $uprice,
                    'sort_order' => $i + 1,
                ]);
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

        $request->validate([
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'override_payment_terms' => 'nullable|boolean',
        ]);

        if ($invoiceRef === '' || ! $dateIssued || $status === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing required fields.'], 422);
        }

        $existingInvoice = DB::table('invoices')->where('invoice_ref_no', $invoiceRef)->first(['id', 'service_type', 'client_id', 'payment_terms_days', 'payment_terms_source']);
        if (! $existingInvoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found.'], 404);
        }

        $totalError = $this->invoiceTotalValidationMessage(
            (string) $existingInvoice->service_type,
            (array) $request->input('breakdown', []),
            (float) $request->input('amount', 0),
            (float) $request->input('sst_amount', 0),
            (float) $request->input('grand_total', 0)
        );
        if ($totalError !== null) {
            return response()->json(['status' => 'error', 'message' => $totalError], 422);
        }

        $paymentTerms = $this->resolvePaymentTerms($request, $existingInvoice->client_id ?? null, $existingInvoice);
        $paymentTermsDays = $paymentTerms['days'];

        try {
            DB::table('invoices')->where('invoice_ref_no', $invoiceRef)->limit(1)->update([
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
                'status' => $status,
                'amount' => $request->input('amount', 0),
                'sst_amount' => $request->input('sst_amount', 0),
                'grand_total' => $request->input('grand_total', 0),
                'payment_method' => $request->input('payment_method', ''),
                'grant_approval_no' => $request->input('grant_approval_no'),
                'paid_date' => $request->input('paid_date'),
                'paid_amount' => $request->input('paid_amount'),
                'paid_remarks' => $request->input('paid_remarks', ''),
                'remarks' => $request->input('remarks', ''),
                'updated_at' => now(),
            ]);

            $invId = $existingInvoice->id;
            if ($invId) {
                DB::table('invoice_breakdown')->where('invoice_id', $invId)->delete();

                foreach ((array) $request->input('breakdown', []) as $i => $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    $qty = (float) ($line['quantity'] ?? 0);
                    $price = (float) ($line['unit_price'] ?? 0);
                    DB::table('invoice_breakdown')->insert([
                        'invoice_id' => $invId,
                        'item_description' => $line['item_description'] ?? '',
                        'description' => $line['description'] ?? null,
                        'unit' => $line['unit'] ?? 'Lot',
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'subtotal' => round($qty * $price, 2),
                        'sort_order' => $i + 1,
                    ]);
                }
            }

            $this->auditLog->log($request, "Updated invoice {$invoiceRef}");

            return response()->json(['status' => 'success', 'message' => 'Invoice updated successfully.']);
        } catch (\Throwable $e) {
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
        $invoiceRef = trim((string) $request->input('invoice_ref_no', ''));
        if ($invoiceRef === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing invoice_ref_no'], 422);
        }

        $invoice = DB::table('invoices')
            ->where('invoice_ref_no', $invoiceRef)
            ->first(['id', 'status', 'project_id']);

        if (! $invoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found'], 404);
        }

        if (strtolower($invoice->status) !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'Only invoices with status "Pending" can be deleted.'], 422);
        }

        try {
            DB::beginTransaction();
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
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }
}
