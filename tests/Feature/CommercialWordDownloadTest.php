<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Support\IhCommercialCycleDatabase;
use Tests\TestCase;
use ZipArchive;

class CommercialWordDownloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RequireAuth::class, ValidateCsrfToken::class, VerifyCsrfToken::class]);
        IhCommercialCycleDatabase::create();
        $this->seedDocuments();
    }

    public function test_supplier_po_word_download_contains_commercial_values(): void
    {
        $this->assertDocx('/catalog/purchase-orders/501/word', ['POES26-0501AZA', 'Safety Shoes', 'Vendor Acknowledgement']);
    }

    public function test_delivery_order_word_download_contains_commercial_values(): void
    {
        $this->assertDocx('/delivery-orders/601/word', ['DO26-601AZA', 'Equipment Project', 'Customer Acceptance']);
    }

    public function test_invoice_and_receipt_word_download_share_persisted_receipt_number(): void
    {
        $this->assertDocx('/invoices/701/word', ['INV26-0701AZA', 'Attention To', 'Client PIC', 'Dear Valued Customer', 'Safety Shoes', 'Equipment - Supply', 'LOA/PO Number: PO-701', 'Subtotal after Discount', '8% SST (RM)', 'CIMB BANK BERHAD', 'UNIKEB Bandar Baru Bangi', 'Terms and Conditions', 'Payment is due within 30 days']);
        $this->assertDocx('/invoices/701/receipt-word', ['RCPT'.date('Y').'-0001', 'INV26-0701AZA', 'Thank you for your payment']);
        $this->assertDatabaseHas('invoices', ['id' => 701, 'receipt_no' => 'RCPT'.date('Y').'-0001']);
        $this->assertDocx('/invoices/701/receipt-word', ['RCPT'.date('Y').'-0001']);
        self::assertSame(1, DB::table('invoices')->whereNotNull('receipt_no')->count());
    }

    public function test_invoice_word_exports_support_training_while_receipts_remain_equipment_only(): void
    {
        $this->assertDocx('/invoices/702/word', ['INV26-0702AZA', 'Training Client', 'Training', 'Terms and Conditions']);

        $this->withSession(['staff_id' => 51, 'name_code' => 'AZA'])
            ->get('/invoices/702/receipt-word')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Word export is not available for this invoice service type.');

        $this->assertDatabaseHas('invoices', ['id' => 702, 'receipt_no' => null]);
    }

    public function test_invoice_word_exports_cover_standard_manpower_and_hrd_training_layouts(): void
    {
        $this->assertDocx('/invoices/703/word', ['INV26-0703AZA', 'Industrial Hygiene - Noise Monitoring', 'Noise survey', 'Subtotal (RM)']);
        $this->assertDocx('/invoices/704/word', ['INV26-0704AZA', 'Manpower Supply - Site Support', 'For Month: August 2026', 'Remarks: August claim']);
        $this->assertDocx('/invoices/705/word', ['INV26-0705AZA', 'Special - Emergency Drill', 'Drill facilitation']);
        $this->assertDocx('/invoices/706/word', ['INV26-0706AZA', 'Human Resource Development Corporation', 'Dear Respected HRD Officer', 'Grant ID: HRD-706', 'Provider Name: AMIOSH RESOURCES SDN. BHD.', 'SST 8% (RM)']);
    }

    public function test_invoice_word_exports_preserve_malay_standard_and_training_labels(): void
    {
        $this->assertDocx('/invoices/707/word', ['INV26-0707AZA', 'No. Invois', 'Untuk Perhatian', 'Jumlah Kecil (RM)', 'Terma dan Syarat']);
        $this->assertDocx('/invoices/708/word', ['INV26-0708AZA', 'No. Invois', 'Kepada Pegawai HRD Yang Dihormati', 'Nama Penyedia', 'SST 8% (RM)']);
    }

    private function assertDocx(string $url, array $expected): void
    {
        $response = $this->withSession(['staff_id' => 51, 'name_code' => 'AZA'])->get($url);
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $path = tempnam(sys_get_temp_dir(), 'commercial-response-');
        self::assertNotFalse($path);
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path) === true);
        $text = html_entity_decode(strip_tags((string) $zip->getFromName('word/document.xml')));
        foreach ($expected as $value) {
            self::assertStringContainsString($value, $text);
        }
        $zip->close();
        unlink($path);
    }

    private function seedDocuments(): void
    {
        DB::table('supplier_po_main')->insert(['po_id' => 501, 'supplier_name' => 'Safe Vendor', 'supplier_address' => 'Kajang', 'supplier_contact_name' => 'Vendor PIC', 'supplier_contact_number' => '0123', 'discount' => 0, 'delivery_charge' => 0, 'sst_percent' => 8, 'sst_amount' => 16, 'grand_total' => 216, 'po_ref_no' => 'POES26-0501AZA', 'created_by' => 51, 'created_at' => '2026-08-13 10:00:00']);
        DB::table('supplier_po_items')->insert(['po_id' => 501, 'item_name' => 'Safety Shoes', 'description' => 'S3 rated', 'item_remarks' => 'Black', 'unit' => 'pair', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]);
        DB::table('do_details')->insert(['id' => 601, 'do_number' => 'DO26-601AZA', 'client_name' => 'Client Sdn Bhd', 'client_address' => 'Kajang', 'client_contact_name' => 'Client PIC', 'client_contact_position' => 'Manager', 'client_contact_email' => 'client@example.test', 'client_contact_phone' => '0123', 'company_contact_name' => 'Aza', 'project_name' => 'Equipment Project', 'project_code' => 'EQ-1', 'project_award_date' => '2026-08-01', 'project_description' => 'Supply', 'project_service_period' => 'August 2026', 'created_by' => 51, 'document_language' => 'en', 'created_at' => '2026-08-13 10:00:00', 'updated_at' => '2026-08-13 10:00:00']);
        DB::table('do_breakdown')->insert(['do_id' => 601, 'item_name' => 'Safety Shoes', 'description' => 'S3 rated', 'quantity' => 2, 'unit' => 'pair']);
        DB::table('invoices')->insert(['id' => 701, 'project_id' => 1, 'created_by' => 51, 'invoice_ref_no' => 'INV26-0701AZA', 'invoice_client_name' => 'Client Sdn Bhd', 'invoice_client_address' => 'Kajang', 'invoice_pic_name' => 'Client PIC', 'invoice_pic_email' => 'client@example.test', 'invoice_pic_phone' => '0123', 'invoice_loa_no' => 'PO-701', 'service_type' => 'Equipment', 'invoice_purpose' => 'Supply', 'invoice_date' => '2026-08-13', 'amount' => 150, 'sst_amount' => 12, 'grand_total' => 162, 'status' => 'Paid', 'paid_date' => '2026-08-13', 'paid_amount' => 162, 'document_language' => 'en', 'created_at' => '2026-08-13 10:00:00', 'updated_at' => '2026-08-13 10:00:00']);
        DB::table('invoices')->insert(['id' => 702, 'project_id' => 2, 'created_by' => 51, 'invoice_ref_no' => 'INV26-0702AZA', 'invoice_client_name' => 'Training Client', 'invoice_client_address' => 'Bangi', 'invoice_pic_email' => 'training@example.test', 'service_type' => 'Training', 'invoice_purpose' => 'OSH Training', 'invoice_date' => '2026-08-13', 'amount' => 200, 'sst_amount' => 16, 'grand_total' => 216, 'status' => 'Paid', 'paid_date' => '2026-08-13', 'paid_amount' => 216, 'document_language' => 'en', 'created_at' => '2026-08-13 10:00:00', 'updated_at' => '2026-08-13 10:00:00']);
        $extraInvoices = [
            ['id' => 703, 'project_id' => 3, 'created_by' => 51, 'invoice_ref_no' => 'INV26-0703AZA', 'invoice_client_name' => 'IH Client', 'service_type' => 'Industrial Hygiene', 'invoice_purpose' => 'Noise Monitoring', 'invoice_date' => '2026-08-13', 'amount' => 100, 'grand_total' => 100, 'document_language' => 'en', 'created_at' => '2026-08-13 10:00:00', 'updated_at' => '2026-08-13 10:00:00'],
            ['id' => 704, 'project_id' => 4, 'created_by' => 51, 'invoice_ref_no' => 'INV26-0704AZA', 'invoice_client_name' => 'Manpower Client', 'service_type' => 'Manpower Supply', 'invoice_purpose' => 'Site Support - For Month: 2026-08', 'remarks' => 'August claim', 'invoice_date' => '2026-08-13', 'amount' => 100, 'grand_total' => 100, 'document_language' => 'en', 'created_at' => '2026-08-13 10:00:00', 'updated_at' => '2026-08-13 10:00:00'],
            ['id' => 705, 'project_id' => 5, 'created_by' => 51, 'invoice_ref_no' => 'INV26-0705AZA', 'invoice_client_name' => 'Special Client', 'service_type' => 'Special', 'invoice_purpose' => 'Emergency Drill', 'invoice_date' => '2026-08-13', 'amount' => 100, 'grand_total' => 100, 'document_language' => 'en', 'created_at' => '2026-08-13 10:00:00', 'updated_at' => '2026-08-13 10:00:00'],
            ['id' => 706, 'project_id' => 6, 'created_by' => 51, 'invoice_ref_no' => 'INV26-0706AZA', 'invoice_client_name' => 'Training Client', 'invoice_client_ssm' => 'HRD-SSM', 'invoice_client_tin' => 'HRD-TIN', 'service_type' => 'Training', 'payment_method' => 'HRD Grant', 'grant_approval_no' => 'HRD-706', 'invoice_purpose' => 'Safety Training', 'invoice_date' => '2026-08-13', 'amount' => 100, 'sst_amount' => 8, 'grand_total' => 108, 'document_language' => 'en', 'created_at' => '2026-08-13 10:00:00', 'updated_at' => '2026-08-13 10:00:00'],
            ['id' => 707, 'project_id' => 7, 'created_by' => 51, 'invoice_ref_no' => 'INV26-0707AZA', 'invoice_client_name' => 'Pelanggan', 'service_type' => 'Special', 'invoice_purpose' => 'Latihan Kecemasan', 'invoice_date' => '2026-08-13', 'amount' => 100, 'grand_total' => 100, 'document_language' => 'ms-MY', 'created_at' => '2026-08-13 10:00:00', 'updated_at' => '2026-08-13 10:00:00'],
            ['id' => 708, 'project_id' => 8, 'created_by' => 51, 'invoice_ref_no' => 'INV26-0708AZA', 'invoice_client_name' => 'Pelanggan Latihan', 'service_type' => 'Training', 'payment_method' => 'HRD Grant', 'invoice_purpose' => 'Latihan Keselamatan', 'invoice_date' => '2026-08-13', 'amount' => 100, 'sst_amount' => 8, 'grand_total' => 108, 'document_language' => 'ms-MY', 'created_at' => '2026-08-13 10:00:00', 'updated_at' => '2026-08-13 10:00:00'],
        ];
        foreach ($extraInvoices as $extraInvoice) {
            DB::table('invoices')->insert($extraInvoice);
        }
        DB::table('invoice_breakdown')->insert(['invoice_id' => 701, 'item_description' => 'Safety Shoes', 'description' => 'S3 rated', 'unit' => 'pair', 'quantity' => 2, 'unit_price' => 100, 'subtotal' => 200, 'sort_order' => 1]);
        DB::table('invoice_breakdown')->insert(['invoice_id' => 701, 'item_description' => 'Discount', 'unit' => 'lot', 'quantity' => 1, 'unit_price' => 50, 'subtotal' => 50, 'sort_order' => 2]);
        $extraBreakdowns = [
            ['invoice_id' => 703, 'item_description' => 'Noise survey', 'description' => '10 pax x 2 months', 'unit' => 'lot', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'sort_order' => 1],
            ['invoice_id' => 704, 'item_description' => 'Site Support', 'unit' => 'month', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'sort_order' => 1],
            ['invoice_id' => 705, 'item_description' => 'Drill facilitation', 'unit' => 'lot', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'sort_order' => 1],
            ['invoice_id' => 706, 'item_description' => 'Training Fee', 'unit' => 'pax', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'sort_order' => 1],
            ['invoice_id' => 707, 'item_description' => 'Latihan Kecemasan', 'unit' => 'lot', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'sort_order' => 1],
            ['invoice_id' => 708, 'item_description' => 'Training Fee', 'unit' => 'pax', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'sort_order' => 1],
        ];
        foreach ($extraBreakdowns as $extraBreakdown) {
            DB::table('invoice_breakdown')->insert($extraBreakdown);
        }
    }
}
