<?php

namespace Tests\Feature;

use Tests\TestCase;

class EquipmentCommercialPdfLongTextTest extends TestCase
{
    private const DESCRIPTION_END = 'CATALOGUE-DESCRIPTION-END-SENTINEL';

    private const ITEM_REMARK_END = 'ITEM-SPECIFICATION-END-SENTINEL';

    private const QUOTATION_REMARK_END = 'QUOTATION-REMARK-END-SENTINEL';

    public function test_equipment_quotation_keeps_the_end_of_long_equipment_wording(): void
    {
        $html = view('pdf.equipment-quote', [
            'quoteRefNo' => 'QEQ26-0001TST',
            'revisionNo' => 0,
            'createdDateLegacy' => '06 Aug 2026',
            'createdDateIso' => '2026-08-06',
            'updatedDateIso' => '2026-08-06',
            'picName' => 'Test PIC',
            'clientName' => 'Test Client',
            'clientAddressBlock' => 'Test Address',
            'picEmail' => 'pic@example.test',
            'picPhone' => '60123456789',
            'items' => [[
                'title' => 'Gas detector',
                'description' => $this->description(),
                'item_remarks' => $this->itemRemarks(),
                'quantity' => 1,
                'marked_up_price' => 100,
                'line_total' => 100,
            ]],
            'quotationRemarks' => $this->quotationRemarks(),
            'lineItemsTotal' => 100,
            'deliveryCharge' => 0,
            'miscCharge' => 0,
            'discountAmount' => 0,
            'subTotalNet' => 100,
            'sstAmount' => 0,
            'sstPercentLabel' => '0',
            'grandTotal' => 100,
            'preparedByName' => 'Test Staff',
            'signOffTitle' => 'Consultant',
            'logoDataUri' => null,
        ])->render();

        $this->assertSentinels($html);
        $this->assertStringNotContainsString('mb_strimwidth', $html);
    }

    public function test_invoice_and_receipt_keep_the_end_of_long_equipment_wording(): void
    {
        $invoice = $this->invoice();
        $item = $this->invoiceItem();

        $invoiceHtml = view('pdf.invoice', [
            'pdfLanguage' => 'en',
            'inv' => $invoice,
            'preTax' => [$item],
            'taxItems' => [],
            'creator' => (object) ['full_name' => 'Test Staff', 'signOffTitle' => 'Consultant'],
            'project' => (object) ['project_name' => 'Equipment Project'],
            'logoDataUri' => null,
            'signDataUri' => null,
            'stampDataUri' => null,
        ])->render();
        $receiptHtml = view('pdf.receipt', [
            'pdfLanguage' => 'en',
            'inv' => $invoice,
            'items' => [$item],
            'logoDataUri' => null,
        ])->render();

        $this->assertSentinels($invoiceHtml);
        $this->assertSentinels($receiptHtml);
    }

    public function test_delivery_order_keeps_the_end_of_long_equipment_wording(): void
    {
        $html = view('pdf.delivery-order', [
            'order' => (object) [
                'document_language' => 'en',
                'do_number' => 'DO26-001TST',
                'client_name' => 'Test Client',
                'client_address' => 'Test Address',
                'client_contact_name' => 'Client PIC',
                'client_contact_position' => 'Manager',
                'client_contact_email' => 'pic@example.test',
                'client_contact_phone' => '60123456789',
                'company_contact_name' => 'Test Staff',
                'project_name' => 'Equipment Project',
                'project_description' => 'Equipment supply',
                'project_service_period' => 'August 2026',
                'quotation_remarks' => $this->quotationRemarks(),
            ],
            'items' => collect([(object) [
                'item_name' => 'Gas detector',
                'description' => $this->description(),
                'item_remarks' => $this->itemRemarks(),
                'quantity' => 1,
                'unit' => 'unit',
            ]]),
            'documentType' => 'DELIVERY ORDER',
            'createdDate' => '06 Aug 2026',
            'logoDataUri' => null,
        ])->render();

        $this->assertSentinels($html);
    }

    public function test_supplier_po_keeps_the_end_of_long_equipment_wording(): void
    {
        $html = view('pdf.catalog-supplier-po', [
            'po' => (object) [
                'po_ref_no' => 'POES26-0001TST',
                'supplier_name' => 'Test Supplier',
                'supplier_address' => 'Supplier Address',
                'supplier_contact_name' => 'Supplier PIC',
                'supplier_contact_number' => '60111111111',
                'supplier_email' => 'supplier@example.test',
                'full_name' => 'Test Staff',
                'position' => 'Consultant',
                'department' => 'Commercial',
                'discount' => 0,
                'delivery_charge' => 0,
                'sst_percent' => 0,
                'sst_amount' => 0,
                'grand_total' => 100,
                'quotation_remarks' => $this->quotationRemarks(),
            ],
            'items' => collect([(object) [
                'item_name' => 'Gas detector',
                'description' => $this->description(),
                'item_remarks' => $this->itemRemarks(),
                'unit' => 'unit',
                'quantity' => 1,
                'unit_price' => 100,
                'line_total' => 100,
            ]]),
            'documentType' => 'PURCHASE ORDER',
            'createdDate' => '06 Aug 2026',
            'logoDataUri' => null,
        ])->render();

        $this->assertSentinels($html);
    }

    public function test_vendor_loa_keeps_multiline_services_and_remarks_without_collapsing_them(): void
    {
        $html = view('pdf.loa', [
            'data' => (object) [
                'vendor_name' => 'Test Vendor',
                'contact_person_name' => 'Vendor PIC',
                'email' => 'vendor@example.test',
                'mobile_number' => '60111111111',
                'address' => 'Vendor Address',
                'city' => 'Kajang',
                'state' => 'Selangor',
                'zip' => '43000',
                'position' => 'Equipment supplier',
                'payment_terms' => '30 days',
            ],
            'services' => $this->description()."\n".$this->itemRemarks(),
            'venue' => 'Client site',
            'breakdown' => 'Supply fee RM100.00',
            'remarks' => $this->quotationRemarks(),
            'formattedAward' => 'RM 100.00',
            'refNo' => 'LOA26-001TST',
            'printDate' => '06 Aug 2026',
            'logoDataUri' => null,
        ])->render();

        $this->assertSentinels($html);
        $this->assertStringContainsString('<br>', $html);
    }

    private function invoice(): object
    {
        return (object) [
            'document_language' => 'en',
            'invoice_ref_no' => 'INV26-0001TST',
            'receipt_no' => 'RCPT2026-0001',
            'invoice_date' => '2026-08-06',
            'paid_date' => '2026-08-06',
            'paid_amount' => 100,
            'invoice_client_name' => 'Test Client',
            'invoice_client_ssm' => 'SSM-1',
            'invoice_client_tin' => 'TIN-1',
            'invoice_client_address' => 'Test Address',
            'invoice_client_city' => 'Kajang',
            'invoice_client_state' => 'Selangor',
            'invoice_client_zip' => '43000',
            'invoice_pic_name' => 'Client PIC',
            'invoice_pic_email' => 'pic@example.test',
            'invoice_pic_phone' => '60123456789',
            'service_type' => 'Equipment Supply',
            'invoice_purpose' => 'Equipment Project',
            'invoice_loa_no' => 'CLIENT-PO-1',
            'amount' => 100,
            'sst_amount' => 0,
            'grand_total' => 100,
            'remarks' => '',
            'quotation_remarks' => $this->quotationRemarks(),
            'payment_terms_days' => 30,
        ];
    }

    private function invoiceItem(): object
    {
        return (object) [
            'item_description' => 'Gas detector',
            'description' => $this->description(),
            'item_remarks' => $this->itemRemarks(),
            'unit' => 'unit',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
        ];
    }

    private function description(): string
    {
        return 'CATALOGUE-DESCRIPTION-START '.str_repeat('complete catalogue detail ', 120).self::DESCRIPTION_END;
    }

    private function itemRemarks(): string
    {
        return 'ITEM-SPECIFICATION-START '.str_repeat('client specification detail ', 80).self::ITEM_REMARK_END;
    }

    private function quotationRemarks(): string
    {
        return 'QUOTATION-REMARK-START '.str_repeat('general quotation detail ', 80).self::QUOTATION_REMARK_END;
    }

    private function assertSentinels(string $html): void
    {
        $this->assertStringContainsString(self::DESCRIPTION_END, $html);
        $this->assertStringContainsString(self::ITEM_REMARK_END, $html);
        $this->assertStringContainsString(self::QUOTATION_REMARK_END, $html);
    }
}
