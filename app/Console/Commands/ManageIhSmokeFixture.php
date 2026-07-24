<?php

namespace App\Console\Commands;

use App\Services\Quotes\Pricing\IhPricingCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManageIhSmokeFixture extends Command
{
    protected $signature = 'quotes:ih-smoke-fixture
        {action : prepare or cleanup}
        {--source-id= : Existing IH quote to clone for prepare}
        {--quote-id= : Disposable legacy quote to remove for cleanup}
        {--complexity=4 : Legacy complexity rating for prepare}';

    protected $description = 'Create or remove a disposable legacy IH quote for local browser smoke tests';

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

        $rating = max(1, min(5, (int) $this->option('complexity')));
        $pricingInput = [
            'sample_counts' => 2,
            'num_work_units' => 1,
            'unit_price' => 500,
            'travel_charge' => 0,
            'discount' => 0,
            'sst_percent' => 0,
        ];
        $totals = $calculator->calculate(
            $pricingInput,
            [],
            IhPricingCalculator::LEGACY_RULE,
            $rating,
        );
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
            'pricing_rule_version' => IhPricingCalculator::LEGACY_RULE,
            'complexity_rating' => $rating,
            'complexity_markup' => 0,
            'inquiry_remarks' => 'Disposable IH legacy browser smoke fixture',
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
            'pricing_rule_version' => IhPricingCalculator::LEGACY_RULE,
            'complexity_rating' => $rating,
            'grand_total' => $totals['grand_total'],
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
        $this->error('Action must be prepare or cleanup.');

        return self::INVALID;
    }
}
