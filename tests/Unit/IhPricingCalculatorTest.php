<?php

namespace Tests\Unit;

use App\Services\Quotes\Pricing\IhPricingCalculator;
use PHPUnit\Framework\TestCase;

class IhPricingCalculatorTest extends TestCase
{
    public function test_legacy_rule_applies_complexity_and_uses_net_subtotal_semantics(): void
    {
        $totals = (new IhPricingCalculator)->calculate([
            'sample_counts' => 10,
            'num_work_units' => 2,
            'unit_price' => 500,
            'travel_charge' => 200,
            'discount' => 300,
            'sst_percent' => 8,
        ], [
            ['line_total' => 999],
        ], IhPricingCalculator::LEGACY_RULE, 4);

        $this->assertSame(1.3, $totals['complexity_multiplier']);
        $this->assertSame(13000.0, $totals['service_total']);
        $this->assertSame(0.0, $totals['additional_fees_total']);
        $this->assertSame(13200.0, $totals['gross_subtotal']);
        $this->assertSame(12900.0, $totals['sub_total']);
        $this->assertSame(1032.0, $totals['sst_amount']);
        $this->assertSame(13932.0, $totals['grand_total']);
    }

    public function test_standard_rule_remains_unchanged_and_ignores_complexity(): void
    {
        $totals = (new IhPricingCalculator)->calculate([
            'sample_counts' => 2,
            'num_work_units' => 1,
            'unit_price' => 500,
            'travel_charge' => 100,
            'discount' => 50,
            'sst_percent' => 8,
        ], [
            ['line_total' => 400],
        ], IhPricingCalculator::STANDARD_RULE, 5);

        $this->assertSame(1.0, $totals['complexity_multiplier']);
        $this->assertSame(1000.0, $totals['service_total']);
        $this->assertSame(400.0, $totals['additional_fees_total']);
        $this->assertSame(1500.0, $totals['sub_total']);
        $this->assertSame(116.0, $totals['sst_amount']);
        $this->assertSame(1566.0, $totals['grand_total']);
    }

    public function test_intermediate_rule_ignores_complexity_items_and_uses_net_subtotal(): void
    {
        $totals = (new IhPricingCalculator)->calculate([
            'sample_counts' => 2,
            'num_work_units' => 1,
            'unit_price' => 500,
            'travel_charge' => 100,
            'discount' => 50,
            'sst_percent' => 8,
        ], [
            ['line_total' => 400],
        ], IhPricingCalculator::INTERMEDIATE_RULE, 5);

        $this->assertSame(1.0, $totals['complexity_multiplier']);
        $this->assertSame(1000.0, $totals['service_total']);
        $this->assertSame(0.0, $totals['additional_fees_total']);
        $this->assertSame(1050.0, $totals['sub_total']);
        $this->assertSame(84.0, $totals['sst_amount']);
        $this->assertSame(1134.0, $totals['grand_total']);
    }

    public function test_blank_work_units_still_default_to_one_for_all_rules(): void
    {
        $calculator = new IhPricingCalculator;
        $data = [
            'sample_counts' => 2,
            'num_work_units' => '',
            'unit_price' => 100,
        ];

        $standard = $calculator->calculate($data);
        $legacy = $calculator->calculate($data, [], IhPricingCalculator::LEGACY_RULE, 2);
        $intermediate = $calculator->calculate(
            $data,
            [],
            IhPricingCalculator::INTERMEDIATE_RULE,
            2,
        );

        $this->assertSame(200.0, $standard['service_total']);
        $this->assertSame(220.0, $legacy['service_total']);
        $this->assertSame(200.0, $intermediate['service_total']);
    }

    public function test_unknown_rule_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new IhPricingCalculator)->calculate([], [], 'unknown-rule');
    }

    public function test_resolves_the_legacy_gross_subtotal_storage_convention(): void
    {
        $totals = (new IhPricingCalculator)->resolveStoredHistoricalTotals([
            'sample_counts' => 2,
            'num_work_units' => 1,
            'unit_price' => 1500,
            'travel_charge' => 0,
            'discount' => 50,
            'sst_percent' => 0,
            'sst_amount' => 0,
            'sub_total' => 3000,
            'grand_total' => 2950,
        ], IhPricingCalculator::LEGACY_RULE, 1);

        $this->assertSame('gross-before-discount', $totals['subtotal_convention']);
        $this->assertSame(3000.0, $totals['service_total']);
        $this->assertSame(3000.0, $totals['gross_subtotal']);
        $this->assertSame(2950.0, $totals['taxable_total']);
        $this->assertSame(3000.0, $totals['sub_total']);
        $this->assertSame(2950.0, $totals['grand_total']);
        $this->assertTrue($totals['is_reconciled']);
    }

    public function test_preserves_the_historical_net_subtotal_storage_convention(): void
    {
        $totals = (new IhPricingCalculator)->resolveStoredHistoricalTotals([
            'travel_charge' => 0,
            'discount' => 200,
            'sst_percent' => 0,
            'sst_amount' => 0,
            'sub_total' => 9300,
            'grand_total' => 9300,
        ], IhPricingCalculator::INTERMEDIATE_RULE, 4);

        $this->assertSame('net-after-discount', $totals['subtotal_convention']);
        $this->assertSame(9500.0, $totals['gross_subtotal']);
        $this->assertSame(9300.0, $totals['taxable_total']);
        $this->assertSame(9300.0, $totals['sub_total']);
        $this->assertSame(9300.0, $totals['grand_total']);
        $this->assertTrue($totals['is_reconciled']);
    }
}
