<?php

namespace Tests\Feature;

use Tests\TestCase;

class IhQuotePdfViewTest extends TestCase
{
    public function test_legacy_pdf_displays_the_preserved_complexity_basis(): void
    {
        $html = view('pdf.ih-quote', $this->viewData([
            'isLegacyPricing' => true,
            'complexityRating' => 4,
            'complexityMultiplier' => 1.3,
            'serviceTotal' => 13000,
        ]))->render();

        $plain = preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html)));

        $this->assertStringContainsString('Complexity Rating: 4 (1.3×)', $plain);
        $this->assertStringContainsString('x complexity 4 (1.3x)', $plain);
        $this->assertStringContainsString('RM 13,000.00', $plain);
    }

    public function test_standard_pdf_does_not_display_complexity(): void
    {
        $html = view('pdf.ih-quote', $this->viewData())->render();

        $this->assertStringNotContainsString('Complexity Rating', $html);
        $this->assertStringNotContainsString('x complexity', $html);
    }

    private function viewData(array $overrides = []): array
    {
        return array_replace([
            'pdfLanguage' => 'en',
            'quoteRefNo' => 'QIH26-0001TST',
            'revisionNo' => 1,
            'createdDateLegacy' => '24 Jul 2026',
            'createdDateIso' => '2026-07-24',
            'updatedDateIso' => '2026-07-24',
            'picName' => 'PIC',
            'clientName' => 'Client',
            'clientAddressBlock' => 'Address',
            'picEmail' => 'pic@example.test',
            'picPhone' => '60123456789',
            'serviceTitle' => 'Chemical Exposure Monitoring',
            'serviceCode' => 'CEM',
            'siteAddress' => 'Site',
            'sampleCount' => 10,
            'sampleUnit' => 'sample(s)',
            'workUnitsDisplay' => '2',
            'workUnitsForCalc' => 2,
            'remarksHtml' => '-',
            'serviceTotal' => 10000,
            'isLegacyPricing' => false,
            'complexityRating' => 1,
            'complexityMultiplier' => 1.0,
            'unitPrice' => 500,
            'travelCharge' => 200,
            'additionalItems' => collect(),
            'grossSubtotal' => 10200,
            'discountAmount' => 300,
            'subTotalNet' => 9900,
            'sstAmount' => 792,
            'sstPercentLabel' => '8',
            'grandTotal' => 10692,
            'preparedByName' => 'Staff',
            'signOffTitle' => 'Consultant',
            'logoDataUri' => null,
        ], $overrides);
    }
}
