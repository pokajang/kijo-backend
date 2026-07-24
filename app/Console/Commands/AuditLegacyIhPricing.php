<?php

namespace App\Console\Commands;

use App\Services\Quotes\Pricing\IhPricingCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditLegacyIhPricing extends Command
{
    protected $signature = 'quotes:audit-ih-legacy-pricing
        {--tolerance=0.01 : Maximum difference treated as a rounding match}';

    protected $description = 'Compare stored legacy IH quote totals with the archived complexity formula';

    public function handle(IhPricingCalculator $calculator): int
    {
        if (
            ! Schema::hasTable('quotes_ih')
            || ! Schema::hasColumn('quotes_ih', 'pricing_rule_version')
        ) {
            $this->error('The IH pricing-rule migration has not been applied.');

            return self::FAILURE;
        }

        $tolerance = max(0, (float) $this->option('tolerance'));
        $rows = DB::table('quotes_ih')
            ->where('pricing_rule_version', IhPricingCalculator::LEGACY_RULE)
            ->orderBy('id')
            ->get();

        $report = [];
        $mismatches = 0;
        foreach ($rows as $quote) {
            $totals = $calculator->calculate(
                (array) $quote,
                [],
                IhPricingCalculator::LEGACY_RULE,
                (int) ($quote->complexity_rating ?? 1),
            );
            $stored = (float) ($quote->grand_total ?? 0);
            $difference = round($stored - $totals['grand_total'], 2);
            $matches = abs($difference) <= $tolerance;
            if (! $matches) {
                $mismatches++;
            }

            $report[] = [
                $quote->id,
                $quote->quote_ref_no ?? '-',
                (int) ($quote->complexity_rating ?? 1),
                number_format($stored, 2, '.', ''),
                number_format($totals['grand_total'], 2, '.', ''),
                number_format($difference, 2, '.', ''),
                $matches ? 'match' : 'review',
            ];
        }

        $this->table(
            ['ID', 'Reference', 'Complexity', 'Stored', 'Expected', 'Difference', 'Result'],
            $report,
        );
        $this->info(sprintf(
            'Audited %d legacy IH quote(s): %d match, %d require review.',
            $rows->count(),
            $rows->count() - $mismatches,
            $mismatches,
        ));

        return $mismatches > 0 ? self::INVALID : self::SUCCESS;
    }
}
