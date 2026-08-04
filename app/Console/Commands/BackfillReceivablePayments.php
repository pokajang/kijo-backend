<?php

namespace App\Console\Commands;

use App\Services\Receivables\ReceivablePaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BackfillReceivablePayments extends Command
{
    protected $signature = 'app:backfill-receivable-payments
        {--commit : Insert legacy paid fields into the receivable payment ledger.}';

    protected $description = 'Audit and backfill invoice and manual-debtor legacy payment fields.';

    public function handle(): int
    {
        if (! Schema::hasTable('receivable_payments')) {
            $this->error('The receivable_payments table does not exist. Run migrations first.');

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        $totals = [
            'candidates' => 0,
            'inserted' => 0,
            'existing' => 0,
            'invalid' => 0,
            'overpaid' => 0,
            'cancelled' => 0,
            'mismatched' => 0,
        ];
        $paymentService = app(ReceivablePaymentService::class);

        foreach ([
            'invoice' => 'invoices',
            'manual' => 'manual_debtors',
        ] as $source => $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'paid_amount')) {
                continue;
            }

            DB::table($table)
                ->whereNotNull('paid_amount')
                ->where('paid_amount', '>', 0)
                ->orderBy('id')
                ->chunkById(250, function ($records) use ($source, $table, $commit, $paymentService, &$totals): void {
                    foreach ($records as $record) {
                        $totals['candidates']++;
                        if (
                            DB::table('receivable_payments')
                                ->where('source_type', $source)
                                ->where('source_id', (int) $record->id)
                                ->exists()
                        ) {
                            $totals['existing']++;

                            continue;
                        }

                        $paymentDate = trim((string) ($record->paid_date ?? ''));
                        $amount = (string) ($record->paid_amount ?? '');
                        $invoiceDate = trim((string) ($record->invoice_date ?? ''));
                        if (
                            ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)
                            || ! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)
                            || ($invoiceDate !== '' && $paymentDate < $invoiceDate)
                            || $paymentDate > now()->format('Y-m-d')
                        ) {
                            $totals['invalid']++;
                            $this->warn("{$source} #{$record->id}: paid fields contain an invalid amount or date.");

                            continue;
                        }

                        $status = strtolower(trim((string) ($record->status ?? '')));
                        if (in_array($status, ['cancelled', 'canceled', 'void'], true)) {
                            $totals['cancelled']++;
                            $this->warn("{$source} #{$record->id}: cancelled receivable has paid fields; skipped.");

                            continue;
                        }

                        $amountCents = (int) round((float) $amount * 100);
                        $grandTotalCents = (int) round((float) ($record->grand_total ?? 0) * 100);
                        if ($grandTotalCents <= 0 || $amountCents > $grandTotalCents) {
                            $totals['overpaid']++;
                            $this->warn("{$source} #{$record->id}: paid amount exceeds the receivable total; skipped.");

                            continue;
                        }

                        if ($amountCents !== $grandTotalCents) {
                            $totals['mismatched']++;
                            $this->warn("{$source} #{$record->id}: partial legacy payment will be backfilled.");
                        }

                        if (! $commit) {
                            continue;
                        }

                        $inserted = DB::transaction(function () use ($source, $table, $record, $amount, $paymentDate, $paymentService): bool {
                            DB::table($table)->where('id', (int) $record->id)->lockForUpdate()->first();
                            if (
                                DB::table('receivable_payments')
                                    ->where('source_type', $source)
                                    ->where('source_id', (int) $record->id)
                                    ->exists()
                            ) {
                                return false;
                            }

                            DB::table('receivable_payments')->insert([
                                'source_type' => $source,
                                'source_id' => (int) $record->id,
                                'amount' => $amount,
                                'payment_date' => $paymentDate,
                                'payment_method' => $source === 'manual' ? ($record->payment_method ?? null) : null,
                                'transaction_reference' => null,
                                'remarks' => $record->paid_remarks ?? 'Legacy payment backfill',
                                'request_token' => (string) Str::uuid(),
                                'recorded_by_staff_id' => null,
                                'recorded_by_code' => 'BACKFILL',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $paymentService->synchronizeProjection($source, (int) $record->id);

                            return true;
                        });
                        $totals[$inserted ? 'inserted' : 'existing']++;
                    }
                });
        }

        $this->table(['Metric', 'Count'], collect($totals)->map(
            fn (int $count, string $label): array => [$label, $count],
        )->values()->all());
        $this->info($commit
            ? 'Legacy payment backfill completed.'
            : 'Dry run only. Re-run with --commit after reviewing anomalies.');

        $unsafeCount = $totals['invalid'] + $totals['overpaid'] + $totals['cancelled'];

        return $unsafeCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
