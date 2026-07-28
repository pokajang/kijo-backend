<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrainingQuotePdfViewTest extends TestCase
{
    public function test_pdf_omits_hrd_row_when_saved_amount_is_zero(): void
    {
        $html = view('pdf.training-quote', $this->viewData())->render();

        $this->assertStringNotContainsString('% HRD Charge (RM)', $html);
        $this->assertStringNotContainsString('1 session x 1 day x 4,500.00/day', $html);
        $this->assertStringContainsString('4,500.00', $html);
    }

    public function test_pdf_displays_the_saved_hrd_rate_and_amount(): void
    {
        $html = view('pdf.training-quote', $this->viewData([
            'hasHrdCharge' => true,
            'hrdRateLabel' => '4',
            'hrdAmount' => 168,
            'grandTotal' => 4368,
        ]))->render();
        $plain = preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html)));

        $this->assertStringContainsString('4% HRD Charge (RM)', $plain);
        $this->assertStringContainsString('168.00', $plain);
        $this->assertStringContainsString('4,368.00', $plain);
    }

    public function test_per_pax_pdf_shows_scope_and_unit_price_without_a_pricing_equation(): void
    {
        $html = view('pdf.training-quote', $this->viewData([
            'trainingDetailsLine' => 'Mode: Virtual',
            'unitPriceLine' => '180.00 per pax',
            'grossSubtotal' => 4500,
        ]))->render();
        $plain = preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html)));

        $this->assertStringContainsString('Mode: Virtual', $plain);
        $this->assertStringContainsString('Safety Training for 25 pax', $plain);
        $this->assertStringContainsString('180.00 per pax', $plain);
        $this->assertStringNotContainsString('Pricing Basis', $plain);
        $this->assertStringNotContainsString('25 pax x RM', $plain);
    }

    private function viewData(array $overrides = []): array
    {
        return array_replace([
            'pdfLanguage' => 'en',
            'quoteRefNo' => 'QTR26-0001TST',
            'revisionNo' => 0,
            'createdDate' => '2026-07-27',
            'updatedDate' => '2026-07-27',
            'clientName' => 'Test Client',
            'clientAddressBlock' => 'Test Address',
            'picName' => 'Test PIC',
            'picEmail' => 'pic@example.test',
            'picPhone' => '60123456789',
            'trainingTitle' => 'Safety Training',
            'trainingType' => 'Physical',
            'trainingDetailsLine' => 'Duration: 1 day x 1 session',
            'targetGroups' => 'Employees',
            'venue' => 'Client Site',
            'trainingDateDisplayHtml' => 'To be Confirmed',
            'remarksLineHtml' => '',
            'unitPriceLine' => '4,500.00 per day',
            'travelCharge' => 0,
            'showMealsRow' => false,
            'mealPrice' => 0,
            'grossSubtotal' => 4500,
            'discountAmount' => 300,
            'discountType' => 'Introductory',
            'showNetSubtotal' => false,
            'netSubtotal' => 4200,
            'hasHrdCharge' => false,
            'hasSstCharge' => false,
            'hrdRateLabel' => '0',
            'sstRateLabel' => '0',
            'hrdAmount' => 0,
            'sstAmount' => 0,
            'grandTotal' => 4200,
            'preparedByName' => 'Test Staff',
            'signOffTitle' => 'Consultant',
            'paxCount' => 25,
            'logoDataUri' => null,
        ], $overrides);
    }
}
