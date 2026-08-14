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
        $this->assertDocx('/invoices/701/word', ['INV26-0701AZA', 'Safety Shoes', 'CIMB BANK BERHAD', 'UNIKEB Bandar Baru Bangi', 'Terms and Conditions', 'Payment is due within 30 days']);
        $this->assertDocx('/invoices/701/receipt-word', ['RCPT'.date('Y').'-0001', 'INV26-0701AZA', 'Thank you for your payment']);
        $this->assertDatabaseHas('invoices', ['id' => 701, 'receipt_no' => 'RCPT'.date('Y').'-0001']);
        $this->assertDocx('/invoices/701/receipt-word', ['RCPT'.date('Y').'-0001']);
        self::assertSame(1, DB::table('invoices')->whereNotNull('receipt_no')->count());
    }

    public function test_invoice_word_exports_are_limited_to_the_equipment_commercial_cycle(): void
    {
        $this->withSession(['staff_id' => 51, 'name_code' => 'AZA'])
            ->get('/invoices/702/word')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Word export is currently available for Equipment invoices only.');

        $this->withSession(['staff_id' => 51, 'name_code' => 'AZA'])
            ->get('/invoices/702/receipt-word')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Word export is currently available for Equipment invoices only.');

        $this->assertDatabaseHas('invoices', ['id' => 702, 'receipt_no' => null]);
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
        DB::table('invoices')->insert(['id' => 701, 'project_id' => 1, 'created_by' => 51, 'invoice_ref_no' => 'INV26-0701AZA', 'invoice_client_name' => 'Client Sdn Bhd', 'invoice_client_address' => 'Kajang', 'invoice_pic_email' => 'client@example.test', 'service_type' => 'Equipment', 'invoice_purpose' => 'Supply', 'invoice_date' => '2026-08-13', 'amount' => 200, 'sst_amount' => 16, 'grand_total' => 216, 'status' => 'Paid', 'paid_date' => '2026-08-13', 'paid_amount' => 216, 'document_language' => 'en', 'created_at' => '2026-08-13 10:00:00', 'updated_at' => '2026-08-13 10:00:00']);
        DB::table('invoices')->insert(['id' => 702, 'project_id' => 2, 'created_by' => 51, 'invoice_ref_no' => 'INV26-0702AZA', 'invoice_client_name' => 'Training Client', 'invoice_client_address' => 'Bangi', 'invoice_pic_email' => 'training@example.test', 'service_type' => 'Training', 'invoice_purpose' => 'OSH Training', 'invoice_date' => '2026-08-13', 'amount' => 200, 'sst_amount' => 16, 'grand_total' => 216, 'status' => 'Paid', 'paid_date' => '2026-08-13', 'paid_amount' => 216, 'document_language' => 'en', 'created_at' => '2026-08-13 10:00:00', 'updated_at' => '2026-08-13 10:00:00']);
        DB::table('invoice_breakdown')->insert(['invoice_id' => 701, 'item_description' => 'Safety Shoes', 'description' => 'S3 rated', 'unit' => 'pair', 'quantity' => 2, 'unit_price' => 100, 'subtotal' => 200, 'sort_order' => 1]);
    }
}
