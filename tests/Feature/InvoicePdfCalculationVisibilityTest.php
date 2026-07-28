<?php

namespace Tests\Feature;

use Tests\TestCase;

class InvoicePdfCalculationVisibilityTest extends TestCase
{
    public function test_standard_invoice_hides_internal_calculations_but_keeps_client_notes(): void
    {
        $html = view('pdf.invoice', $this->viewData([
            $this->line(
                'Industrial Hygiene',
                '10 sample(s) x 2 work unit(s) x RM 650.00/unit x complexity 4 (1.3x)',
                20,
                650,
            ),
            $this->line('Laboratory analysis', 'Analysis of collected samples.', 1, 300),
        ]))->render();
        $plain = $this->plainText($html);

        $this->assertStringNotContainsString('10 sample(s) x 2 work units', $plain);
        $this->assertStringContainsString('Analysis of collected samples.', $plain);
        $this->assertStringContainsString('U/P (RM)', $plain);
        $this->assertStringContainsString('13,000.00', $plain);
    }

    public function test_training_invoice_uses_the_same_description_filter(): void
    {
        $html = view('pdf.invoice-training', $this->viewData([
            $this->line('Training Fee', '2 pax x 1 month(s) x RM 500.00/pax/month', 2, 500),
            $this->line('Workbook', 'Participant workbook.', 1, 50),
        ], 'Training'))->render();
        $plain = $this->plainText($html);

        $this->assertStringNotContainsString('2 pax x 1 month(s)', $plain);
        $this->assertStringContainsString('Participant workbook.', $plain);
        $this->assertStringContainsString('Unit Price (RM)', $plain);
    }

    private function viewData(array $items, string $serviceType = 'Industrial Hygiene'): array
    {
        return [
            'pdfLanguage' => 'en',
            'inv' => (object) [
                'invoice_ref_no' => 'INV26-0001TST',
                'invoice_date' => '2026-07-28',
                'invoice_client_name' => 'Test Client',
                'invoice_client_ssm' => '123456-A',
                'invoice_client_tin' => 'C1234567890',
                'invoice_client_address' => 'Test Address',
                'invoice_client_city' => 'Kajang',
                'invoice_client_state' => 'Selangor',
                'invoice_client_zip' => '43000',
                'invoice_pic_name' => 'Test PIC',
                'invoice_pic_email' => 'pic@example.test',
                'invoice_pic_phone' => '60123456789',
                'service_type' => $serviceType,
                'invoice_purpose' => $serviceType,
                'invoice_loa_no' => '',
                'amount' => 13300,
                'sst_amount' => 0,
                'grand_total' => 13300,
                'remarks' => '',
                'payment_method' => 'Direct Payment',
                'grant_approval_no' => '',
            ],
            'preTax' => $items,
            'taxItems' => [],
            'creator' => (object) [
                'full_name' => 'Test Staff',
                'signOffTitle' => 'Consultant',
            ],
            'project' => (object) [
                'project_name' => 'Test Project',
                'company_name' => 'Test Client',
                'ssm_number' => '123456-A',
                'service_start_date' => '2026-07-01',
                'service_end_date' => '2026-07-02',
            ],
            'logoDataUri' => null,
            'signDataUri' => null,
            'stampDataUri' => null,
        ];
    }

    private function line(
        string $label,
        string $description,
        float $quantity,
        float $unitPrice,
    ): object {
        return (object) [
            'item_description' => $label,
            'description' => $description,
            'quantity' => $quantity,
            'unit' => 'Lot',
            'unit_price' => $unitPrice,
            'subtotal' => $quantity * $unitPrice,
        ];
    }

    private function plainText(string $html): string
    {
        return preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html)));
    }
}
