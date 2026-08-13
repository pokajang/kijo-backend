<?php

namespace Tests\Unit;

use App\Services\Invoices\InvoiceTotalsCalculator;
use PHPUnit\Framework\TestCase;

class InvoiceTotalsCalculatorTest extends TestCase
{
    public function test_calculates_ih_discount_and_sst_from_typed_lines(): void
    {
        $totals = (new InvoiceTotalsCalculator)->calculateIndustrialHygiene([
            ['line_type' => 'service', 'quantity' => 2, 'unit_price' => 1500],
            ['line_type' => 'travel', 'quantity' => 1, 'unit_price' => 0],
            ['line_type' => 'discount', 'item_description' => 'Commercial adjustment', 'quantity' => 1, 'unit_price' => -50],
        ], 8);

        $this->assertSame(3000.0, $totals['amount']);
        $this->assertSame(50.0, $totals['discount_total']);
        $this->assertSame(2950.0, $totals['taxable_subtotal']);
        $this->assertSame(236.0, $totals['sst_amount']);
        $this->assertSame(3186.0, $totals['grand_total']);
        $this->assertSame([], $totals['field_errors']);
    }

    public function test_reports_the_discount_field_when_discount_exceeds_gross(): void
    {
        $totals = (new InvoiceTotalsCalculator)->calculateIndustrialHygiene([
            ['line_type' => 'service', 'quantity' => 1, 'unit_price' => 100],
            ['line_type' => 'discount', 'quantity' => 1, 'unit_price' => -120],
        ], 0);

        $this->assertArrayHasKey('breakdown.1.unit_price', $totals['field_errors']);
        $this->assertStringContainsString('RM 100.00', $totals['field_errors']['breakdown.1.unit_price'][0]);
    }

    public function test_derives_the_sst_rate_from_a_consistent_legacy_payload(): void
    {
        $totals = (new InvoiceTotalsCalculator)->calculateIndustrialHygienePayload([
            ['item_description' => 'Industrial hygiene services', 'quantity' => 1, 'unit_price' => 3000],
            ['item_description' => 'Discount', 'quantity' => 1, 'unit_price' => -50],
        ], null, 236, 3186);

        $this->assertSame(8.0, $totals['sst_percent']);
        $this->assertSame(236.0, $totals['sst_amount']);
        $this->assertSame(3186.0, $totals['grand_total']);
        $this->assertSame([], $totals['field_errors']);
    }

    public function test_rejects_inconsistent_legacy_sst_totals(): void
    {
        $totals = (new InvoiceTotalsCalculator)->calculateIndustrialHygienePayload([
            ['item_description' => 'Industrial hygiene services', 'quantity' => 1, 'unit_price' => 1000],
        ], null, 80, 1000);

        $this->assertArrayHasKey('sst_amount', $totals['field_errors']);
    }
}
