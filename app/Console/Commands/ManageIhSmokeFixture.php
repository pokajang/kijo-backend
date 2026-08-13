<?php

namespace App\Console\Commands;

use App\Services\Quotes\Pricing\IhPricingCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManageIhSmokeFixture extends Command
{
    protected $signature = 'quotes:ih-smoke-fixture
        {action : prepare, touch, or cleanup}
        {--source-id= : Existing IH quote to clone for prepare}
        {--quote-id= : Disposable legacy quote to remove for cleanup}
        {--rule=legacy : legacy or intermediate pricing fixture}
        {--complexity=4 : Legacy complexity rating for prepare}
        {--unit-price=500 : Legacy unit price for prepare}
        {--discount=0 : Legacy discount for prepare}
        {--sst-percent=0 : Legacy SST percentage for prepare}
        {--legacy-gross-subtotal : Store the legacy subtotal before discount}';

    protected $description = 'Create, modify, or remove a disposable historical IH quote for local browser smoke tests';

    public function handle(IhPricingCalculator $calculator): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('IH smoke fixtures are restricted to local and testing environments.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('quotes_ih')) {
            $this->error('The quotes_ih table is unavailable.');

            return self::FAILURE;
        }

        return match (strtolower((string) $this->argument('action'))) {
            'prepare' => $this->prepare($calculator),
            'touch' => $this->touch(),
            'cleanup' => $this->cleanup(),
            default => $this->invalidAction(),
        };
    }

    private function prepare(IhPricingCalculator $calculator): int
    {
        $sourceId = (int) $this->option('source-id');
        $source = DB::table('quotes_ih')->where('id', $sourceId)->first();
        if (! $source) {
            $this->error("Source IH quote #{$sourceId} was not found.");

            return self::FAILURE;
        }

        $fixtureRule = strtolower(trim((string) $this->option('rule')));
        if (! in_array($fixtureRule, ['legacy', 'intermediate'], true)) {
            $this->error('Fixture rule must be legacy or intermediate.');

            return self::INVALID;
        }

        $isIntermediate = $fixtureRule === 'intermediate';
        $rating = max(1, min(5, (int) $this->option('complexity')));
        $pricingRule = $isIntermediate
            ? IhPricingCalculator::INTERMEDIATE_RULE
            : IhPricingCalculator::LEGACY_RULE;
        $pricingInput = [
            'sample_counts' => $isIntermediate ? 120 : 2,
            'num_work_units' => 1,
            'unit_price' => $isIntermediate ? 79.17 : max(0, (float) $this->option('unit-price')),
            'travel_charge' => 0,
            'discount' => $isIntermediate ? 200 : max(0, (float) $this->option('discount')),
            'sst_percent' => $isIntermediate ? 0 : max(0, (float) $this->option('sst-percent')),
        ];
        $totals = $calculator->calculate(
            $pricingInput,
            [],
            $pricingRule,
            $rating,
        );
        if ($isIntermediate) {
            $totals['sub_total'] = 9300;
            $totals['grand_total'] = 9300;
        } elseif ($this->option('legacy-gross-subtotal')) {
            $totals['sub_total'] = $totals['gross_subtotal'];
        }
        $columns = array_flip(Schema::getColumnListing('quotes_ih'));
        $row = array_intersect_key((array) $source, $columns);
        unset($row['id']);

        $stamp = now()->format('YmdHisv');
        $row = array_replace($row, array_intersect_key([
            'service_group' => 'ih',
            'quote_running_no' => ((int) DB::table('quotes_ih')->max('quote_running_no')) + 1,
            'quote_ref_no' => "SMOKE-IH-V1-{$stamp}",
            'revision_no' => 0,
            'status' => 'Open',
            'sample_counts' => $pricingInput['sample_counts'],
            'num_work_units' => $pricingInput['num_work_units'],
            'unit_price' => $pricingInput['unit_price'],
            'travel_charge' => $pricingInput['travel_charge'],
            'discount' => $totals['discount'],
            'sst_percent' => $totals['sst_percent'],
            'sst_amount' => $totals['sst_amount'],
            'sub_total' => $totals['sub_total'],
            'grand_total' => $totals['grand_total'],
            'estimated_total_cost' => null,
            'pricing_rule_version' => $pricingRule,
            'complexity_rating' => $rating,
            'complexity_markup' => 0,
            'inquiry_remarks' => 'Disposable IH historical browser smoke fixture',
            'attach_proposal' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $columns));

        $quoteId = DB::transaction(function () use ($row): int {
            $quoteId = (int) DB::table('quotes_ih')->insertGetId($row);
            if (Schema::hasTable('quotes_ih_items')) {
                DB::table('quotes_ih_items')->where('quote_id', $quoteId)->delete();
            }

            return $quoteId;
        });

        $this->line(json_encode([
            'status' => 'success',
            'action' => 'prepare',
            'quote_id' => $quoteId,
            'quote_ref_no' => $row['quote_ref_no'],
            'pricing_rule_version' => $pricingRule,
            'complexity_rating' => $rating,
            'grand_total' => $totals['grand_total'],
            'subtotal_convention' => $isIntermediate
                ? 'net-after-discount'
                : ($this->option('legacy-gross-subtotal')
                    ? 'gross-before-discount'
                    : 'net-after-discount'),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function touch(): int
    {
        $quoteId = (int) $this->option('quote-id');
        $quote = DB::table('quotes_ih')->where('id', $quoteId)->first();
        if (! $quote || ! str_starts_with((string) ($quote->quote_ref_no ?? ''), 'SMOKE-IH-V1-')) {
            $this->error("Quote #{$quoteId} is not a disposable IH smoke fixture.");

            return self::FAILURE;
        }

        DB::table('quotes_ih')->where('id', $quoteId)->update([
            'inquiry_remarks' => 'Concurrent smoke update',
            'updated_at' => now()->addSecond(),
        ]);

        $this->line(json_encode([
            'status' => 'success',
            'action' => 'touch',
            'quote_id' => $quoteId,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function cleanup(): int
    {
        $quoteId = (int) $this->option('quote-id');
        $quote = DB::table('quotes_ih')->where('id', $quoteId)->first();
        if (! $quote) {
            $this->line(json_encode([
                'status' => 'success',
                'action' => 'cleanup',
                'quote_id' => $quoteId,
                'deleted' => false,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if (! str_starts_with((string) ($quote->quote_ref_no ?? ''), 'SMOKE-IH-V1-')) {
            $this->error("Quote #{$quoteId} is not a disposable IH smoke fixture.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($quoteId): void {
            $this->deleteRelatedRows($quoteId);
            DB::table('quotes_ih')->where('id', $quoteId)->delete();
        });

        $this->line(json_encode([
            'status' => 'success',
            'action' => 'cleanup',
            'quote_id' => $quoteId,
            'deleted' => true,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function deleteRelatedRows(int $quoteId): void
    {
        foreach ([
            ['quotes_ih_items', ['quote_id' => $quoteId]],
            ['quote_followups', ['quote_id' => $quoteId]],
            ['quote_inquiry_sources', ['quote_id' => $quoteId]],
            ['quote_price_exception_requests', ['quote_id' => $quoteId]],
            ['quote_approval_requests', ['quote_id' => $quoteId]],
        ] as [$table, $conditions]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'quote_id')) {
                continue;
            }

            $query = DB::table($table);
            foreach ($conditions as $column => $value) {
                if (Schema::hasColumn($table, $column)) {
                    $query->where($column, $value);
                }
            }
            $query->delete();
        }
    }

    private function invalidAction(): int
    {
        $this->error('Action must be prepare, touch, or cleanup.');

        return self::INVALID;
    }
}
