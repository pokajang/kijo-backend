<?php

namespace App\Services\Receivables;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ReceivablePaymentService
{
    private const SOURCE_INVOICE = 'invoice';

    private const SOURCE_MANUAL = 'manual';

    public function __construct(private AuditLogService $auditLog) {}

    public function history(string $source, int $id, ?string $asOfDate = null): JsonResponse
    {
        try {
            $record = $this->findSourceRecord($source, $id);
            if (! $record) {
                return response()->json(['status' => 'error', 'message' => 'Receivable not found.'], 404);
            }

            $payments = $this->paymentsQuery($source, $id)
                ->orderByDesc('payment_date')
                ->orderByDesc('id')
                ->get()
                ->map(fn (object $payment): array => $this->normalizePayment($payment))
                ->values();

            return response()->json([
                'status' => 'success',
                'payments' => $payments,
                'summary' => $this->summaryForRecord($source, $record, $asOfDate),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function recordPayment(
        Request $request,
        string $source,
        int $id,
        bool $forceFullPayment = false,
    ): JsonResponse {
        $source = $this->normalizeSource($source);
        $rules = [
            'payment_type' => [$forceFullPayment ? 'nullable' : 'required', 'in:full,partial'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'paid_amount' => ['nullable', 'numeric', 'gt:0'],
            'payment_date' => ['nullable', 'date_format:Y-m-d'],
            'paid_date' => ['nullable', 'date_format:Y-m-d'],
            'payment_method' => ['nullable', 'string', 'max:120'],
            'transaction_reference' => ['nullable', 'string', 'max:191'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'paid_remarks' => ['nullable', 'string', 'max:2000'],
            'request_token' => ['nullable', 'uuid'],
        ];
        $validated = $request->validate($rules);

        $paymentType = $forceFullPayment ? 'full' : (string) $validated['payment_type'];
        $paymentDate = (string) ($validated['payment_date'] ?? $validated['paid_date'] ?? '');
        if ($paymentDate === '') {
            throw ValidationException::withMessages(['payment_date' => 'Payment date is required.']);
        }
        if ($paymentDate > now()->format('Y-m-d')) {
            throw ValidationException::withMessages(['payment_date' => 'Payment date cannot be in the future.']);
        }

        $requestToken = (string) ($validated['request_token'] ?? Str::uuid());
        $requestedAmount = $validated['amount'] ?? $validated['paid_amount'] ?? null;

        try {
            $result = DB::transaction(function () use (
                $request,
                $source,
                $id,
                $paymentType,
                $paymentDate,
                $requestedAmount,
                $validated,
                $requestToken,
            ): array {
                $record = $this->sourceQuery($source)->where('id', $id)->lockForUpdate()->first();
                if (! $record) {
                    abort(404, 'Receivable not found.');
                }

                $existingPayment = DB::table('receivable_payments')
                    ->where('request_token', $requestToken)
                    ->first();
                if ($existingPayment) {
                    if (
                        (string) $existingPayment->source_type !== $source
                        || (int) $existingPayment->source_id !== $id
                    ) {
                        throw ValidationException::withMessages([
                            'request_token' => 'This payment request token has already been used.',
                        ]);
                    }

                    return [
                        'payment' => $this->normalizePayment($existingPayment),
                        'summary' => $this->summaryForRecord($source, $record),
                        'duplicate' => true,
                    ];
                }

                $status = strtolower(trim((string) ($record->status ?? '')));
                if (in_array($status, ['cancelled', 'canceled', 'void'], true)) {
                    throw ValidationException::withMessages([
                        'payment_type' => 'Cancelled or void receivables cannot accept payments.',
                    ]);
                }

                $this->materializeLegacyPaymentIfNeeded($source, $record);

                $invoiceDate = trim((string) ($record->invoice_date ?? ''));
                if ($invoiceDate !== '' && $paymentDate < $invoiceDate) {
                    throw ValidationException::withMessages([
                        'payment_date' => 'Payment date cannot be before the invoice date.',
                    ]);
                }

                $before = $this->summaryForRecord($source, $record);
                $outstandingCents = $this->moneyToCents($before['outstandingAmount']);
                if ($outstandingCents <= 0) {
                    throw ValidationException::withMessages([
                        'payment_type' => 'This receivable is already paid in full.',
                    ]);
                }

                $amountCents = $paymentType === 'full'
                    ? $outstandingCents
                    : $this->moneyToCents($requestedAmount);
                if ($amountCents <= 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'Partial payment amount must be greater than zero.',
                    ]);
                }
                if ($amountCents > $outstandingCents) {
                    throw ValidationException::withMessages([
                        'amount' => 'Payment amount cannot exceed the outstanding balance.',
                    ]);
                }

                $paymentId = DB::table('receivable_payments')->insertGetId([
                    'source_type' => $source,
                    'source_id' => $id,
                    'amount' => $this->centsToDecimal($amountCents),
                    'payment_date' => $paymentDate,
                    'payment_method' => $this->nullableTrim($validated['payment_method'] ?? null),
                    'transaction_reference' => $this->nullableTrim($validated['transaction_reference'] ?? null),
                    'remarks' => $this->nullableTrim($validated['remarks'] ?? $validated['paid_remarks'] ?? null),
                    'request_token' => $requestToken,
                    'recorded_by_staff_id' => (int) $request->session()->get('staff_id', 0) ?: null,
                    'recorded_by_code' => $this->nullableTrim($request->session()->get('name_code')),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $after = $this->applyProjection($source, $record);
                $payment = DB::table('receivable_payments')->where('id', $paymentId)->first();
                $this->writeAuditEvent(
                    $request,
                    $source,
                    $id,
                    (string) ($record->invoice_ref_no ?? ''),
                    'payment_recorded',
                    null,
                    $before,
                    array_merge($after, ['payment' => $this->normalizePayment($payment)]),
                );

                return [
                    'payment' => $this->normalizePayment($payment),
                    'summary' => $after,
                    'duplicate' => false,
                ];
            });

            if (! $result['duplicate']) {
                $this->auditLog->log(
                    $request,
                    sprintf(
                        'Recorded %s payment of RM %s for %s ID %d',
                        $paymentType,
                        $result['payment']['amount'],
                        $source,
                        $id,
                    ),
                );
            }

            return response()->json(['status' => 'success', ...$result]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function reversePayment(Request $request, int $paymentId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $identity = DB::table('receivable_payments')
                ->where('id', $paymentId)
                ->first(['source_type', 'source_id']);
            if (! $identity) {
                return response()->json(['status' => 'error', 'message' => 'Payment not found.'], 404);
            }
            $source = $this->normalizeSource((string) $identity->source_type);
            $sourceId = (int) $identity->source_id;

            $result = DB::transaction(function () use ($request, $paymentId, $validated, $source, $sourceId): array {
                $record = $this->sourceQuery($source)
                    ->where('id', $sourceId)
                    ->lockForUpdate()
                    ->first();
                if (! $record) {
                    abort(404, 'Receivable not found.');
                }

                $payment = DB::table('receivable_payments')->where('id', $paymentId)->lockForUpdate()->first();
                if (! $payment) {
                    abort(404, 'Payment not found.');
                }
                if (
                    (string) $payment->source_type !== $source
                    || (int) $payment->source_id !== $sourceId
                ) {
                    abort(409, 'Payment source changed during reversal.');
                }
                if ($payment->reversed_at !== null) {
                    throw ValidationException::withMessages(['reason' => 'This payment has already been reversed.']);
                }

                $before = $this->summaryForRecord($source, $record);
                DB::table('receivable_payments')->where('id', $paymentId)->update([
                    'reversed_at' => now(),
                    'reversed_by_staff_id' => (int) $request->session()->get('staff_id', 0) ?: null,
                    'reversed_by_code' => $this->nullableTrim($request->session()->get('name_code')),
                    'reversal_reason' => trim((string) $validated['reason']),
                    'updated_at' => now(),
                ]);
                $after = $this->applyProjection($source, $record);
                $updatedPayment = DB::table('receivable_payments')->where('id', $paymentId)->first();

                $this->writeAuditEvent(
                    $request,
                    $source,
                    $sourceId,
                    (string) ($record->invoice_ref_no ?? ''),
                    'payment_reversed',
                    trim((string) $validated['reason']),
                    array_merge($before, ['payment' => $this->normalizePayment($payment)]),
                    array_merge($after, ['payment' => $this->normalizePayment($updatedPayment)]),
                );

                return ['payment' => $this->normalizePayment($updatedPayment), 'summary' => $after];
            });

            $this->auditLog->log($request, "Reversed receivable payment ID {$paymentId}");

            return response()->json(['status' => 'success', ...$result]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function reverseAllPayments(Request $request, string $source, int $id): JsonResponse
    {
        $source = $this->normalizeSource($source);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $result = DB::transaction(function () use ($request, $source, $id, $validated): array {
                $record = $this->sourceQuery($source)->where('id', $id)->lockForUpdate()->first();
                if (! $record) {
                    abort(404, 'Receivable not found.');
                }
                $this->materializeLegacyPaymentIfNeeded($source, $record);

                $payments = $this->paymentsQuery($source, $id)
                    ->whereNull('reversed_at')
                    ->lockForUpdate()
                    ->get();
                if ($payments->isEmpty()) {
                    throw ValidationException::withMessages([
                        'reason' => 'This receivable has no active payments.',
                    ]);
                }

                $before = $this->summaryForRecord($source, $record);
                $paymentIds = $payments->pluck('id')->map(fn ($paymentId): int => (int) $paymentId)->all();
                DB::table('receivable_payments')->whereIn('id', $paymentIds)->update([
                    'reversed_at' => now(),
                    'reversed_by_staff_id' => (int) $request->session()->get('staff_id', 0) ?: null,
                    'reversed_by_code' => $this->nullableTrim($request->session()->get('name_code')),
                    'reversal_reason' => trim((string) $validated['reason']),
                    'updated_at' => now(),
                ]);
                $after = $this->applyProjection($source, $record);
                $updatedPayments = DB::table('receivable_payments')
                    ->whereIn('id', $paymentIds)
                    ->orderBy('id')
                    ->get()
                    ->map(fn (object $payment): array => $this->normalizePayment($payment))
                    ->all();

                $this->writeAuditEvent(
                    $request,
                    $source,
                    $id,
                    (string) ($record->invoice_ref_no ?? ''),
                    'payments_reversed',
                    trim((string) $validated['reason']),
                    array_merge($before, [
                        'payments' => $payments
                            ->map(fn (object $payment): array => $this->normalizePayment($payment))
                            ->all(),
                    ]),
                    array_merge($after, ['payments' => $updatedPayments]),
                );

                return ['payments' => $updatedPayments, 'summary' => $after];
            });

            $this->auditLog->log($request, "Reversed all payments for {$source} ID {$id}");

            return response()->json(['status' => 'success', ...$result]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function synchronizeProjection(string $source, int $id): ?array
    {
        $source = $this->normalizeSource($source);

        return DB::transaction(function () use ($source, $id): ?array {
            $record = $this->sourceQuery($source)->where('id', $id)->lockForUpdate()->first();

            return $record ? $this->applyProjection($source, $record) : null;
        });
    }

    public function summariesFor(string $source, array $ids, ?string $asOfDate = null): array
    {
        if (! Schema::hasTable('receivable_payments') || $ids === []) {
            return [];
        }

        $source = $this->normalizeSource($source);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ledgerIds = DB::table('receivable_payments')
            ->where('source_type', $source)
            ->whereIn('source_id', $ids)
            ->distinct()
            ->pluck('source_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $query = DB::table('receivable_payments')
            ->where('source_type', $source)
            ->whereIn('source_id', $ids);
        if ($asOfDate) {
            $query
                ->whereDate('payment_date', '<=', $asOfDate)
                ->where(function ($nested) use ($asOfDate): void {
                    $nested->whereNull('reversed_at')
                        ->orWhereDate('reversed_at', '>', $asOfDate);
                });
        } else {
            $query->whereNull('reversed_at');
        }

        $summaries = $query
            ->selectRaw('source_id, SUM(amount) AS paid_total, COUNT(*) AS payment_count, MAX(payment_date) AS last_payment_date')
            ->groupBy('source_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->source_id => [
                    'paidTotal' => (string) ($row->paid_total ?? '0.00'),
                    'paymentCount' => (int) $row->payment_count,
                    'lastPaymentDate' => (string) ($row->last_payment_date ?? ''),
                    'hasLedger' => true,
                ],
            ])
            ->all();

        foreach ($ledgerIds as $ledgerId) {
            $summaries[$ledgerId] ??= [
                'paidTotal' => '0.00',
                'paymentCount' => 0,
                'lastPaymentDate' => '',
                'hasLedger' => true,
            ];
        }

        return $summaries;
    }

    public function calculateSummary(
        mixed $grandTotal,
        mixed $legacyPaidAmount = null,
        mixed $legacyPaidDate = null,
        ?array $ledgerSummary = null,
        ?string $asOfDate = null,
    ): array {
        $grandTotalCents = max(0, $this->moneyToCents($grandTotal));
        $legacyPaymentIsFuture = $ledgerSummary === null
            && $asOfDate
            && (string) $legacyPaidDate > $asOfDate;
        $paidTotalCents = $ledgerSummary
            ? max(0, $this->moneyToCents($ledgerSummary['paidTotal'] ?? 0))
            : ($legacyPaymentIsFuture ? 0 : max(0, $this->moneyToCents($legacyPaidAmount)));
        $paidTotalCents = min($grandTotalCents, $paidTotalCents);
        $outstandingCents = max(0, $grandTotalCents - $paidTotalCents);
        $hasLedgerHistory = (bool) ($ledgerSummary['hasLedger'] ?? false);

        return [
            'grandTotal' => (float) $this->centsToDecimal($grandTotalCents),
            'paidTotal' => (float) $this->centsToDecimal($paidTotalCents),
            'outstandingAmount' => (float) $this->centsToDecimal($outstandingCents),
            'paymentStatus' => $paidTotalCents <= 0
                ? 'Open'
                : ($outstandingCents <= 0 ? 'Paid' : 'Partially Paid'),
            'paymentCount' => (int) ($ledgerSummary['paymentCount'] ?? ($paidTotalCents > 0 ? 1 : 0)),
            'lastPaymentDate' => $legacyPaymentIsFuture
                ? ''
                : (string) ($ledgerSummary['lastPaymentDate'] ?? $legacyPaidDate ?? ''),
            'hasPaymentHistory' => $hasLedgerHistory
                || (int) ($ledgerSummary['paymentCount'] ?? ($paidTotalCents > 0 ? 1 : 0)) > 0,
        ];
    }

    public function deletePaymentsFor(string $source, int $id): array
    {
        if (! Schema::hasTable('receivable_payments')) {
            return [];
        }

        $payments = $this->paymentsQuery($source, $id)->get()->map(
            fn (object $payment): array => $this->normalizePayment($payment),
        )->all();
        $this->paymentsQuery($source, $id)->delete();

        return $payments;
    }

    public function writeDeletionAudit(
        Request $request,
        string $source,
        object $record,
        string $reason,
        array $payments,
    ): void {
        $this->writeAuditEvent(
            $request,
            $source,
            (int) $record->id,
            (string) ($record->invoice_ref_no ?? ''),
            'receivable_deleted',
            $reason,
            ['receivable' => (array) $record, 'payments' => $payments],
            null,
        );
    }

    private function summaryForRecord(string $source, object $record, ?string $asOfDate = null): array
    {
        $summaries = $this->summariesFor($source, [(int) $record->id], $asOfDate);

        return $this->calculateSummary(
            $record->grand_total ?? 0,
            $record->paid_amount ?? null,
            $record->paid_date ?? null,
            $summaries[(int) $record->id] ?? null,
            $asOfDate,
        );
    }

    private function applyProjection(string $source, object $record): array
    {
        $summary = $this->summaryForRecord($source, $record);
        $table = $this->sourceTable($source);
        $latestPayment = $this->paymentsQuery($source, (int) $record->id)
            ->whereNull('reversed_at')
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->first();
        $updates = [];

        if (Schema::hasColumn($table, 'paid_amount')) {
            $updates['paid_amount'] = $summary['paidTotal'] > 0 ? $summary['paidTotal'] : null;
        }
        if (Schema::hasColumn($table, 'paid_date')) {
            $updates['paid_date'] = $latestPayment?->payment_date;
        }
        if (Schema::hasColumn($table, 'paid_remarks')) {
            $updates['paid_remarks'] = $latestPayment?->remarks;
        }
        if ($source === self::SOURCE_MANUAL && Schema::hasColumn($table, 'payment_method')) {
            $updates['payment_method'] = $latestPayment?->payment_method;
        }
        if (Schema::hasColumn($table, 'status')) {
            $updates['status'] = match ($summary['paymentStatus']) {
                'Paid' => 'Paid',
                'Partially Paid' => 'Partially Paid',
                default => $source === self::SOURCE_MANUAL ? 'Open' : 'Pending',
            };
        }
        if (Schema::hasColumn($table, 'updated_at')) {
            $updates['updated_at'] = now();
        }

        if ($updates !== []) {
            DB::table($table)->where('id', (int) $record->id)->update($updates);
        }

        return $summary;
    }

    private function materializeLegacyPaymentIfNeeded(string $source, object $record): void
    {
        if ($this->paymentsQuery($source, (int) $record->id)->exists()) {
            return;
        }

        $legacyPaidCents = $this->moneyToCents($record->paid_amount ?? null);
        if ($legacyPaidCents <= 0) {
            return;
        }

        $grandTotalCents = $this->moneyToCents($record->grand_total ?? null);
        $paymentDate = trim((string) ($record->paid_date ?? ''));
        $invoiceDate = trim((string) ($record->invoice_date ?? ''));
        if (
            $legacyPaidCents > $grandTotalCents
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)
            || ($invoiceDate !== '' && $paymentDate < $invoiceDate)
            || $paymentDate > now()->format('Y-m-d')
        ) {
            throw ValidationException::withMessages([
                'payment_type' => 'Legacy payment data must be corrected before recording another payment.',
            ]);
        }

        DB::table('receivable_payments')->insert([
            'source_type' => $source,
            'source_id' => (int) $record->id,
            'amount' => $this->centsToDecimal($legacyPaidCents),
            'payment_date' => $paymentDate,
            'payment_method' => $source === self::SOURCE_MANUAL
                ? $this->nullableTrim($record->payment_method ?? null)
                : null,
            'transaction_reference' => null,
            'remarks' => $this->nullableTrim($record->paid_remarks ?? null) ?? 'Legacy payment materialized on first ledger update',
            'request_token' => (string) Str::uuid(),
            'recorded_by_staff_id' => null,
            'recorded_by_code' => 'BACKFILL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function normalizePayment(?object $payment): array
    {
        if (! $payment) {
            return [];
        }

        return [
            'id' => (int) $payment->id,
            'sourceType' => (string) $payment->source_type,
            'sourceId' => (int) $payment->source_id,
            'amount' => (float) $payment->amount,
            'paymentDate' => (string) $payment->payment_date,
            'paymentMethod' => (string) ($payment->payment_method ?? ''),
            'transactionReference' => (string) ($payment->transaction_reference ?? ''),
            'remarks' => (string) ($payment->remarks ?? ''),
            'recordedByCode' => (string) ($payment->recorded_by_code ?? ''),
            'reversedAt' => $payment->reversed_at ? (string) $payment->reversed_at : null,
            'reversedByCode' => (string) ($payment->reversed_by_code ?? ''),
            'reversalReason' => (string) ($payment->reversal_reason ?? ''),
            'createdAt' => (string) ($payment->created_at ?? ''),
        ];
    }

    private function writeAuditEvent(
        Request $request,
        string $source,
        ?int $id,
        string $invoiceRef,
        string $eventType,
        ?string $reason,
        mixed $before,
        mixed $after,
    ): void {
        if (! Schema::hasTable('receivable_audit_events')) {
            return;
        }

        DB::table('receivable_audit_events')->insert([
            'source_type' => $this->normalizeSource($source),
            'source_id' => $id,
            'invoice_ref_no' => $invoiceRef !== '' ? $invoiceRef : null,
            'event_type' => $eventType,
            'actor_staff_id' => (int) $request->session()->get('staff_id', 0) ?: null,
            'actor_code' => $this->nullableTrim($request->session()->get('name_code')),
            'reason' => $this->nullableTrim($reason),
            'before_state' => $before !== null ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_state' => $after !== null ? json_encode($after, JSON_THROW_ON_ERROR) : null,
            'created_at' => now(),
        ]);
    }

    private function findSourceRecord(string $source, int $id): ?object
    {
        return $this->sourceQuery($source)->where('id', $id)->first();
    }

    private function sourceQuery(string $source)
    {
        return DB::table($this->sourceTable($source));
    }

    private function sourceTable(string $source): string
    {
        return match ($this->normalizeSource($source)) {
            self::SOURCE_INVOICE => 'invoices',
            self::SOURCE_MANUAL => 'manual_debtors',
        };
    }

    private function paymentsQuery(string $source, int $id)
    {
        return DB::table('receivable_payments')
            ->where('source_type', $this->normalizeSource($source))
            ->where('source_id', $id);
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        if (! in_array($source, [self::SOURCE_INVOICE, self::SOURCE_MANUAL], true)) {
            throw ValidationException::withMessages(['source' => 'Invalid receivable source.']);
        }

        return $source;
    }

    private function moneyToCents(mixed $value): int
    {
        $raw = trim((string) ($value ?? '0'));
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $raw)) {
            return 0;
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        return ((int) $whole * 100) + (int) substr($fraction, 0, 2);
    }

    private function centsToDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed !== '' ? $trimmed : null;
    }
}
