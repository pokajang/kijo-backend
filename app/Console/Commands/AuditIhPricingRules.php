<?php

namespace App\Console\Commands;

use App\Services\Quotes\Pricing\IhPricingCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditIhPricingRules extends Command
{
    protected $signature = 'quotes:audit-ih-pricing-rules
        {--tolerance=0.01 : Maximum unexplained monetary difference}
        {--format=table : Output format: table or json}';

    protected $description = 'Audit every IH quotation against its assigned and historical pricing rules';

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
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('The --format option must be table or json.');

            return self::FAILURE;
        }

        $manifest = collect(config('ih_standard_v1_repair.quotes', []))->keyBy('id');
        $rows = DB::table('quotes_ih')->orderBy('id')->get();
        $report = [];
        $unresolved = 0;

        foreach ($rows as $quote) {
            try {
                $assignedRule = $calculator->normalizeRule($quote->pricing_rule_version ?? null);
            } catch (\InvalidArgumentException) {
                $report[] = $this->reportRow(
                    $quote,
                    'unknown',
                    null,
                    'unresolved-rule',
                    'The stored pricing rule is unsupported.',
                );
                $unresolved++;

                continue;
            }

            $items = $this->itemsFor((int) $quote->id);
            $assigned = $calculator->calculate(
                (array) $quote,
                $items,
                $assignedRule,
                (int) ($quote->complexity_rating ?? 1),
            );
            $assignedDifference = round(
                (float) $quote->grand_total - $assigned['grand_total'],
                2,
            );
            $status = 'unresolved';
            $reason = $this->unresolvedReason($quote, $assigned, $tolerance);
            $expected = $assigned;

            if ($this->matchesStored($quote, $assigned, $tolerance)) {
                $status = 'assigned-rule-match';
                $reason = 'Stored subtotal, SST, and grand total match the assigned rule.';
            } elseif (
                $assignedRule === IhPricingCalculator::LEGACY_RULE
                && $this->matchesLegacyGrossSubtotal($quote, $assigned, $tolerance)
            ) {
                $status = 'assigned-rule-match-legacy-gross-subtotal';
                $reason = 'Legacy quote stores sub_total before discount; SST and grand total match.';
            }

            if (
                $assignedRule !== IhPricingCalculator::STANDARD_RULE
                && $items !== []
            ) {
                $status = 'historical-fees-present';
                $reason = 'Additional-fee rows are not valid for this historical pricing rule.';
            }

            if ($status === 'unresolved' && $assignedRule === IhPricingCalculator::LEGACY_RULE) {
                $intermediate = $calculator->calculate(
                    (array) $quote,
                    [],
                    IhPricingCalculator::INTERMEDIATE_RULE,
                    1,
                );
                $candidateDifference = round(
                    (float) $quote->grand_total - $intermediate['grand_total'],
                    2,
                );
                $manifestRow = $manifest->get((int) $quote->id);
                $expectedVariance = (float) ($manifestRow['expected_variance'] ?? 0);
                $isManifestCandidate = $manifestRow
                    && $this->manifestMatches($quote, $manifestRow, $tolerance)
                    && $items === []
                    && abs($candidateDifference - $expectedVariance) <= $tolerance;

                if ($isManifestCandidate) {
                    $status = abs($expectedVariance) <= $tolerance
                        ? 'intermediate-candidate'
                        : 'documented-variance';
                    $reason = $status === 'intermediate-candidate'
                        ? 'Stored totals match the unversioned intermediate standard rule.'
                        : 'Stored totals match the documented intermediate-rule precision variance.';
                    $expected = $intermediate;
                }
            } elseif (
                $status === 'unresolved'
                && $assignedRule === IhPricingCalculator::INTERMEDIATE_RULE
            ) {
                $manifestRow = $manifest->get((int) $quote->id);
                $expectedVariance = (float) ($manifestRow['expected_variance'] ?? 0);
                if (
                    $manifestRow
                    && $this->manifestMatches($quote, $manifestRow, $tolerance)
                    && $items === []
                    && abs($assignedDifference - $expectedVariance) <= $tolerance
                ) {
                    $status = 'documented-variance';
                    $reason = 'Stored totals match the documented intermediate-rule precision variance.';
                }
            }

            if (
                in_array(
                    $status,
                    ['unresolved', 'intermediate-candidate', 'historical-fees-present'],
                    true,
                )
                || (
                    $status === 'documented-variance'
                    && $assignedRule === IhPricingCalculator::LEGACY_RULE
                )
            ) {
                $unresolved++;
            }

            $report[] = $this->reportRow(
                $quote,
                $assignedRule,
                $expected,
                $status,
                $reason,
            );
        }

        $summary = [
            'audited' => $rows->count(),
            'require_action' => $unresolved,
            'status_counts' => collect($report)->countBy('status')->all(),
            'rule_counts' => collect($report)->countBy('assigned_rule')->all(),
        ];

        if ($format === 'json') {
            $this->line((string) json_encode(
                [
                    'generated_at' => now()->toIso8601String(),
                    'tolerance' => $tolerance,
                    'summary' => $summary,
                    'quotes' => $report,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->table(
                ['ID', 'Reference', 'Assigned Rule', 'Stored', 'Expected', 'Difference', 'Result'],
                array_map(
                    fn (array $row): array => [
                        $row['id'],
                        $row['reference'],
                        $row['assigned_rule'],
                        number_format($row['stored_total'], 2, '.', ''),
                        $row['expected_total'] === null
                            ? '-'
                            : number_format($row['expected_total'], 2, '.', ''),
                        $row['difference'] === null
                            ? '-'
                            : number_format($row['difference'], 2, '.', ''),
                        $row['status'],
                    ],
                    $report,
                ),
            );
            $this->info(sprintf(
                'Audited %d IH quote(s): %d require action.',
                $summary['audited'],
                $summary['require_action'],
            ));
        }

        return $unresolved > 0 ? self::INVALID : self::SUCCESS;
    }

    private function itemsFor(int $quoteId): array
    {
        if (! Schema::hasTable('quotes_ih_items')) {
            return [];
        }

        return DB::table('quotes_ih_items')
            ->where('quote_id', $quoteId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $item): array => (array) $item)
            ->all();
    }

    private function matchesStored(object $quote, array $totals, float $tolerance): bool
    {
        return abs((float) $quote->sub_total - $totals['sub_total']) <= $tolerance
            && abs((float) $quote->sst_amount - $totals['sst_amount']) <= $tolerance
            && abs((float) $quote->grand_total - $totals['grand_total']) <= $tolerance;
    }

    private function matchesLegacyGrossSubtotal(
        object $quote,
        array $totals,
        float $tolerance,
    ): bool {
        return abs((float) $quote->sub_total - $totals['gross_subtotal']) <= $tolerance
            && abs((float) $quote->sst_amount - $totals['sst_amount']) <= $tolerance
            && abs((float) $quote->grand_total - $totals['grand_total']) <= $tolerance;
    }

    private function unresolvedReason(object $quote, array $totals, float $tolerance): string
    {
        $components = collect([
            'sub_total' => abs((float) $quote->sub_total - $totals['sub_total']),
            'sst_amount' => abs((float) $quote->sst_amount - $totals['sst_amount']),
            'grand_total' => abs((float) $quote->grand_total - $totals['grand_total']),
        ])->filter(fn (float $difference): bool => $difference > $tolerance)->keys()->all();

        return $components === []
            ? 'Stored financial components require review.'
            : 'Mismatch in: '.implode(', ', $components).'.';
    }

    private function manifestMatches(object $quote, array $manifest, float $tolerance): bool
    {
        return ($quote->quote_ref_no ?? null) === $manifest['reference']
            && abs((float) $quote->sub_total - (float) $manifest['sub_total']) <= $tolerance
            && abs((float) $quote->grand_total - (float) $manifest['grand_total']) <= $tolerance;
    }

    private function reportRow(
        object $quote,
        string $rule,
        ?array $expected,
        string $status,
        string $reason,
    ): array {
        $storedSubTotal = round((float) ($quote->sub_total ?? 0), 2);
        $storedSst = round((float) ($quote->sst_amount ?? 0), 2);
        $storedGrandTotal = round((float) ($quote->grand_total ?? 0), 2);

        return [
            'id' => (int) $quote->id,
            'reference' => $quote->quote_ref_no ?? '-',
            'assigned_rule' => $rule,
            'stored_total' => $storedGrandTotal,
            'expected_total' => $expected === null
                ? null
                : round($expected['grand_total'], 2),
            'difference' => $expected === null
                ? null
                : round($storedGrandTotal - $expected['grand_total'], 2),
            'status' => $status,
            'reason' => $reason,
            'components' => [
                'sub_total' => [
                    'stored' => $storedSubTotal,
                    'expected_for_rule' => $expected === null
                        ? null
                        : round($expected['sub_total'], 2),
                    'expected_gross' => $expected === null
                        ? null
                        : round($expected['gross_subtotal'], 2),
                    'difference_from_rule' => $expected === null
                        ? null
                        : round($storedSubTotal - $expected['sub_total'], 2),
                    'difference_from_gross' => $expected === null
                        ? null
                        : round($storedSubTotal - $expected['gross_subtotal'], 2),
                ],
                'sst_amount' => [
                    'stored' => $storedSst,
                    'expected' => $expected === null
                        ? null
                        : round($expected['sst_amount'], 2),
                    'difference' => $expected === null
                        ? null
                        : round($storedSst - $expected['sst_amount'], 2),
                ],
                'grand_total' => [
                    'stored' => $storedGrandTotal,
                    'expected' => $expected === null
                        ? null
                        : round($expected['grand_total'], 2),
                    'difference' => $expected === null
                        ? null
                        : round($storedGrandTotal - $expected['grand_total'], 2),
                ],
            ],
        ];
    }
}
