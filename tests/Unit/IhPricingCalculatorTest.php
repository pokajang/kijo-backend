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

    public function test_blank_work_units_still_default_to_one_for_both_rules(): void
    {
        $calculator = new IhPricingCalculator;
        $data = [
            'sample_counts' => 2,
            'num_work_units' => '',
            'unit_price' => 100,
        ];

        $standard = $calculator->calculate($data);
        $legacy = $calculator->calculate($data, [], IhPricingCalculator::LEGACY_RULE, 2);

        $this->assertSame(200.0, $standard['service_total']);
        $this->assertSame(220.0, $legacy['service_total']);
    }
}
