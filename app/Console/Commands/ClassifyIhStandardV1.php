<?php

namespace App\Console\Commands;

use App\Services\Quotes\Pricing\IhPricingCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClassifyIhStandardV1 extends Command
{
    protected $signature = 'quotes:classify-ih-standard-v1
        {--commit : Apply the verified classification changes}
        {--rollback : Preview restoring manifest rows to ih_complexity_v1}
        {--confirm= : Fingerprint printed by a fresh dry run; required with --commit}';

    protected $description = 'Dry-run or apply the fingerprinted IH standard-v1 classification repair';

    public function handle(IhPricingCalculator $calculator): int
    {
        if ($this->option('commit') && trim((string) $this->option('confirm')) === '') {
            $this->error('--commit requires --confirm with the fingerprint from a fresh dry run.');

            return self::FAILURE;
        }

        if (
            ! Schema::hasTable('quotes_ih')
            || ! Schema::hasColumn('quotes_ih', 'pricing_rule_version')
        ) {
            $this->error('The IH pricing-rule migration has not been applied.');

            return self::FAILURE;
        }

        $manifest = collect(config('ih_standard_v1_repair.quotes', []));
        if ($manifest->count() !== 28 || $manifest->pluck('id')->unique()->count() !== 28) {
            $this->error('The repair manifest must contain exactly 28 unique quote IDs.');

            return self::FAILURE;
        }

        $rollback = (bool) $this->option('rollback');
        $sourceRule = $rollback
            ? IhPricingCalculator::INTERMEDIATE_RULE
            : IhPricingCalculator::LEGACY_RULE;
        $targetRule = $rollback
            ? IhPricingCalculator::LEGACY_RULE
            : IhPricingCalculator::INTERMEDIATE_RULE;
        $rows = [];
        $verified = [];

        try {
            DB::beginTransaction();

            foreach ($manifest as $entry) {
                $query = DB::table('quotes_ih')->where('id', (int) $entry['id']);
                $quote = $this->option('commit') ? $query->lockForUpdate()->first() : $query->first();
                if (! $quote) {
                    throw new \RuntimeException("Quote ID {$entry['id']} is missing.");
                }

                $this->assertFingerprint($quote, $entry);
                $itemCount = Schema::hasTable('quotes_ih_items')
                    ? DB::table('quotes_ih_items')->where('quote_id', $quote->id)->count()
                    : 0;
                if ($itemCount !== 0) {
                    throw new \RuntimeException("Quote ID {$quote->id} unexpectedly has additional items.");
                }

                $currentRule = $calculator->normalizeRule($quote->pricing_rule_version ?? null);
                if (! in_array($currentRule, [$sourceRule, $targetRule], true)) {
                    throw new \RuntimeException(
                        "Quote ID {$quote->id} has unexpected rule {$currentRule}.",
                    );
                }

                if (! $rollback) {
                    $totals = $calculator->calculate(
                        (array) $quote,
                        [],
                        IhPricingCalculator::INTERMEDIATE_RULE,
                        1,
                    );
                    $actualVariance = round(
                        (float) $quote->grand_total - $totals['grand_total'],
                        2,
                    );
                    $expectedVariance = (float) ($entry['expected_variance'] ?? 0);
                    if (abs($actualVariance - $expectedVariance) > 0.01) {
                        throw new \RuntimeException(sprintf(
                            'Quote ID %d variance changed: expected %.2f, found %.2f.',
                            $quote->id,
                            $expectedVariance,
                            $actualVariance,
                        ));
                    }
                }

                $action = $currentRule === $targetRule ? 'unchanged' : 'change';
                $rows[] = [
                    $quote->id,
                    $quote->quote_ref_no,
                    $currentRule,
                    $targetRule,
                    number_format((float) $quote->grand_total, 2, '.', ''),
                    $action,
                ];
                $verified[] = [
                    'quote' => $quote,
                    'item_count' => $itemCount,
                    'action' => $action,
                ];
            }

            $fingerprint = $this->fingerprint($verified);
            if (
                $this->option('commit')
                && ! hash_equals($fingerprint, trim((string) $this->option('confirm')))
            ) {
                throw new \RuntimeException(
                    'Confirmation fingerprint does not match current production data. Run a new dry run.',
                );
            }

            if ($this->option('commit')) {
                foreach ($verified as $entry) {
                    if ($entry['action'] !== 'change') {
                        continue;
                    }

                    DB::table('quotes_ih')
                        ->where('id', $entry['quote']->id)
                        ->update([
                            'pricing_rule_version' => $targetRule,
                            'updated_at' => DB::raw('updated_at'),
                        ]);
                }
            }

            if ($this->option('commit')) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['ID', 'Reference', 'Current Rule', 'Target Rule', 'Grand Total', 'Action'],
            $rows,
        );
        $this->line("Confirmation fingerprint: {$fingerprint}");
        $this->info($this->option('commit')
            ? sprintf('Committed %s classification for 28 verified quotes.', $rollback ? 'rollback' : 'standard-v1')
            : 'Dry run complete. No database changes were committed.');

        return self::SUCCESS;
    }

    private function assertFingerprint(object $quote, array $entry): void
    {
        if (($quote->quote_ref_no ?? null) !== $entry['reference']) {
            throw new \RuntimeException("Quote ID {$entry['id']} reference does not match the manifest.");
        }

        foreach (['sub_total', 'grand_total'] as $field) {
            if (abs((float) $quote->{$field} - (float) $entry[$field]) > 0.01) {
                throw new \RuntimeException(
                    "Quote ID {$entry['id']} {$field} does not match the manifest.",
                );
            }
        }
    }

    private function fingerprint(array $verified): string
    {
        $fields = [
            'id',
            'quote_ref_no',
            'sample_counts',
            'num_work_units',
            'unit_price',
            'travel_charge',
            'complexity_rating',
            'complexity_markup',
            'discount',
            'sst_percent',
            'sst_amount',
            'sub_total',
            'grand_total',
            'pricing_rule_version',
            'updated_at',
        ];
        $payload = array_map(function (array $entry) use ($fields): array {
            $quote = $entry['quote'];
            $row = [];
            foreach ($fields as $field) {
                $row[$field] = $quote->{$field} ?? null;
            }
            $row['item_count'] = $entry['item_count'];

            return $row;
        }, $verified);

        return hash('sha256', json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
