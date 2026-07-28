<?php

namespace Tests\Feature;

use Tests\TestCase;

class QuotationPdfCalculationVisibilityTest extends TestCase
{
    public function test_manpower_pdf_hides_the_amount_formula(): void
    {
        $html = view('pdf.manpower-quote', $this->manpowerViewData())->render();
        $plain = $this->plainText($html);

        $this->assertStringContainsString('RM 6,000.00', $plain);
        $this->assertStringNotContainsString('3 pax x 2 month(s) x RM 1,000.00', $plain);
    }

    public function test_special_service_pdf_hides_line_item_formulas_but_keeps_notes(): void
    {
        $html = view('pdf.special-quote', $this->specialViewData())->render();
        $plain = $this->plainText($html);

        $this->assertStringContainsString('RM 200.00', $plain);
        $this->assertStringContainsString('Notes: Client-facing note', $plain);
        $this->assertStringNotContainsString('2.00 day x RM 100.00', $plain);
    }

    public function test_equipment_pdf_keeps_commercial_columns_without_an_inline_formula(): void
    {
        $html = view('pdf.equipment-quote', $this->equipmentViewData())->render();
        $plain = $this->plainText($html);

        $this->assertStringContainsString('Unit Price (RM)', $plain);
        $this->assertStringContainsString('Portable detector', $plain);
        $this->assertStringContainsString('RM 200.00', $plain);
        $this->assertStringNotContainsString('2 unit x RM 100.00', $plain);
    }

    private function manpowerViewData(): array
    {
        return [
            'pdfLanguage' => 'en',
            'quoteRefNo' => 'QMP26-0001TST',
            'revisionNo' => 0,
            'createdDateLegacy' => '28 Jul 2026',
            'createdDateIso' => '2026-07-28',
            'updatedDateIso' => '2026-07-28',
            'picName' => 'Test PIC',
            'clientName' => 'Test Client',
            'clientAddressBlock' => 'Test Address',
            'picEmail' => 'pic@example.test',
            'picPhone' => '60123456789',
            'serviceTitle' => 'Site Supervisor',
            'serviceCode' => 'MP',
            'natureOfWork' => 'Site supervision',
            'siteLocation' => 'Test Site',
            'durationMonths' => 2,
            'durationDisplay' => '2',
            'durationUnitLabel' => 'month(s)',
            'noOfPax' => 3,
            'unitCost' => 1000,
            'unitCostLabel' => 'per pax per month',
            'grossAmount' => 6000,
            'discountAmount' => 0,
            'showSubtotal' => false,
            'subTotalNet' => 6000,
            'sstAmount' => 0,
            'sstPercentLabel' => '0',
            'grandTotal' => 6000,
            'inquiryRemarks' => '',
            'preparedByName' => 'Test Staff',
            'signOffTitle' => 'Consultant',
            'logoDataUri' => null,
        ];
    }

    private function specialViewData(): array
    {
        return [
            'pdfLanguage' => 'en',
            'quoteRefNo' => 'QSP26-0001TST',
            'revisionNo' => 0,
            'createdDateLegacy' => '28 Jul 2026',
            'createdDateIso' => '2026-07-28',
            'updatedDateIso' => '2026-07-28',
            'picName' => 'Test PIC',
            'clientName' => 'Test Client',
            'clientAddressBlock' => 'Test Address',
            'picEmail' => 'pic@example.test',
            'picPhone' => '60123456789',
            'serviceTitle' => 'Inspection',
            'serviceCode' => 'SP',
            'remarksHtml' => '',
            'items' => collect([
                (object) [
                    'title' => 'Inspection service',
                    'description' => 'Client-facing note',
                    'quantity' => 2,
                    'unit' => 'day',
                    'unit_price' => 100,
                    'line_total' => 200,
                ],
            ]),
            'grossAmount' => 200,
            'discountAmount' => 0,
            'showSubtotal' => false,
            'subTotalNet' => 200,
            'sstAmount' => 0,
            'sstPercentLabel' => '0',
            'grandTotal' => 200,
            'preparedByName' => 'Test Staff',
            'signOffTitle' => 'Consultant',
            'logoDataUri' => null,
        ];
    }

    private function equipmentViewData(): array
    {
        return [
            'quoteRefNo' => 'QEQ26-0001TST',
            'revisionNo' => 0,
            'createdDateLegacy' => '28 Jul 2026',
            'createdDateIso' => '2026-07-28',
            'updatedDateIso' => '2026-07-28',
            'picName' => 'Test PIC',
            'clientName' => 'Test Client',
            'clientAddressBlock' => 'Test Address',
            'picEmail' => 'pic@example.test',
            'picPhone' => '60123456789',
            'items' => [[
                'title' => 'Gas Detector',
                'description' => 'Portable detector',
                'quantity' => 2,
                'marked_up_price' => 100,
                'line_total' => 200,
            ]],
            'lineItemsTotal' => 200,
            'deliveryCharge' => 0,
            'miscCharge' => 0,
            'discountAmount' => 0,
            'subTotalNet' => 200,
            'sstAmount' => 0,
            'sstPercentLabel' => '0',
            'grandTotal' => 200,
            'preparedByName' => 'Test Staff',
            'signOffTitle' => 'Consultant',
            'logoDataUri' => null,
        ];
    }

    private function plainText(string $html): string
    {
        return preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html)));
    }
}
