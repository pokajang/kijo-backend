<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\IhCommercialCycleDatabase;
use Tests\TestCase;

class IhCommercialDocumentCycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        IhCommercialCycleDatabase::create();
    }

    public function test_ih_quote_runs_through_award_project_and_every_applicable_commercial_document(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->quotePayload());
        $create->assertOk()->assertJsonPath('status', 'success');
        $quoteId = (int) $create->json('quote_id');

        $this->authenticated()->putJson("/quotes/ih/{$quoteId}", $this->quotePayload([
            'inquiry_remarks' => 'Edited before revision.',
        ]))
            ->assertOk()
            ->assertJsonPath('data.revision_no', 0);

        $this->authenticated()->putJson("/quotes/ih/{$quoteId}", $this->quotePayload([
            'isRevision' => true,
            'inquiry_remarks' => 'Formal revision before award.',
        ]))
            ->assertOk()
            ->assertJsonPath('data.revision_no', 1);

        $award = $this->authenticated()->postJson("/quote-records/ih/{$quoteId}/award", [
            'quote_id' => $quoteId,
            'remarks' => 'Client awarded the work.',
            'award_date' => '2026-07-27',
            'description' => 'Industrial hygiene monitoring scope.',
            'client_award_ref_no' => 'CLIENT-LOA-001',
        ]);
        $award->assertOk()->assertJsonPath('status', 'success');
        $projectId = (int) $award->json('project_id');

        $this->assertDatabaseHas('projects_main', [
            'id' => $projectId,
            'quote_id' => $quoteId,
            'quote_type' => 'ih',
            'project_type' => 'Industrial Hygiene',
            'status' => 'Active',
            'quote_value' => 1200,
        ]);

        $this->authenticated()->getJson("/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $projectId)
            ->assertJsonPath('data.project_type', 'Industrial Hygiene')
            ->assertJsonPath('data.client_name', 'Client A')
            ->assertJsonPath('data.hygiene_items.0.item_description', 'Laboratory analysis');

        $this->authenticated()->getJson("/invoices/quote/ih/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.grand_total', 1200)
            ->assertJsonPath('data.hygiene_items.0.item_description', 'Laboratory analysis');

        $invoice = $this->authenticated()->postJson('/invoices', $this->invoicePayload($projectId, $quoteId));
        $invoice->assertOk()->assertJsonPath('status', 'success');
        $invoiceId = (int) $invoice->json('invoice_id');

        $this->seedExistingDeliveryOrderNumber();
        $deliveryOrder = $this->authenticated()->postJson(
            '/delivery-orders',
            $this->deliveryOrderPayload($projectId),
        );
        $deliveryOrder->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('do_number', 'DO'.date('y').'-010AZA');
        $deliveryOrderId = (int) $deliveryOrder->json('do_id');

        $vendorLoa = $this->authenticated()->postJson(
            "/projects/{$projectId}/vendors",
            $this->vendorLoaPayload(),
        );
        $vendorLoa->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('action', 'added');
        $vendorLoaId = (int) DB::table('project_vendors')
            ->where('project_id', $projectId)
            ->value('id');

        $supplierPo = $this->authenticated()->postJson(
            '/catalog/purchase-orders',
            $this->supplierPoPayload($projectId),
        );
        $supplierPo->assertOk()->assertJsonPath('status', 'success');
        $supplierPoId = (int) $supplierPo->json('po_id');

        $this->authenticated()->postJson('/jd14-forms', $this->jd14Payload($projectId))
            ->assertStatus(422)
            ->assertJsonPath('message', 'JD14 forms can only be generated for Training projects.');

        $related = $this->authenticated()->getJson("/quote-records/ih/{$quoteId}/related-docs");
        $related->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.projects.0.id', $projectId)
            ->assertJsonPath('data.invoices.0.id', $invoiceId)
            ->assertJsonPath('data.delivery_orders.0.id', $deliveryOrderId)
            ->assertJsonPath('data.vendor_loas.0.id', $vendorLoaId)
            ->assertJsonPath('data.supplier_pos.0.po_id', $supplierPoId);

        $this->authenticated()->putJson("/quotes/ih/{$quoteId}", $this->quotePayload([
            'isRevision' => true,
            'unit_price' => 600,
            'inquiry_remarks' => 'Post-award commercial revision.',
            'project_value_sync_decision' => 'keep',
        ]))
            ->assertOk()
            ->assertJsonPath('data.revision_no', 2);

        $this->assertDatabaseHas('quotes_ih', [
            'id' => $quoteId,
            'status' => 'Awarded',
            'grand_total' => 1400,
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'project_id' => $projectId,
            'quote_id' => $quoteId,
            'grand_total' => 1200,
        ]);

        // Isolate the Supplier PO safeguard after proving every document was created.
        DB::table('invoice_breakdown')->where('invoice_id', $invoiceId)->delete();
        DB::table('invoices')->where('id', $invoiceId)->delete();
        DB::table('do_breakdown')->where('do_id', $deliveryOrderId)->delete();
        DB::table('do_details')->where('id', $deliveryOrderId)->delete();
        DB::table('project_vendors')->where('id', $vendorLoaId)->delete();

        $this->authenticated()->postJson("/quote-records/ih/{$quoteId}/un-award", [
            'quote_id' => $quoteId,
        ])
            ->assertStatus(400)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath(
                'message',
                "Cannot un-award. Linked project #{$projectId} has supplier PO records.",
            );

        $this->assertDatabaseHas('projects_main', ['id' => $projectId]);
        $this->assertDatabaseHas('supplier_po_main', [
            'po_id' => $supplierPoId,
            'project_id' => $projectId,
        ]);
    }

    public function test_ih_invoice_overage_is_guided_and_can_be_acknowledged(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->quotePayload());
        $quoteId = (int) $create->json('quote_id');
        $award = $this->authenticated()->postJson("/quote-records/ih/{$quoteId}/award", [
            'quote_id' => $quoteId,
            'remarks' => 'Client awarded the work.',
            'award_date' => '2026-07-27',
            'description' => 'Industrial hygiene monitoring scope.',
            'client_award_ref_no' => 'CLIENT-LOA-OVER',
        ]);
        $projectId = (int) $award->json('project_id');
        $payload = $this->invoicePayload($projectId, $quoteId);
        $payload['amount'] = 1300;
        $payload['grand_total'] = 1300;
        $payload['breakdown'][0]['unit_price'] = 1300;
        $payload['breakdown'][0]['line_type'] = 'service';

        $this->authenticated()->postJson('/invoices', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invoice_over_project_value')
            ->assertJsonPath('context.overage', 100)
            ->assertJsonPath(
                'field_errors.deviation_reason.0',
                'Briefly explain why this invoice exceeds the project value.',
            );

        $payload['deviation_reason'] = 'Approved additional site attendance.';
        $payload['deviation_acknowledged'] = true;
        $this->authenticated()->postJson('/invoices', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_legacy_invoice_payload_without_sst_percent_preserves_its_sst(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->quotePayload([
            'sst_percent' => 8,
        ]));
        $quoteId = (int) $create->json('quote_id');
        $award = $this->authenticated()->postJson("/quote-records/ih/{$quoteId}/award", [
            'quote_id' => $quoteId,
            'remarks' => 'Client awarded the work.',
            'award_date' => '2026-07-27',
            'description' => 'Industrial hygiene monitoring scope.',
            'client_award_ref_no' => 'CLIENT-LOA-LEGACY-SST',
        ]);
        $projectId = (int) $award->json('project_id');
        $payload = $this->invoicePayload($projectId, $quoteId);
        unset($payload['sst_percent'], $payload['calculation_version']);
        $payload['sst_amount'] = 96;
        $payload['grand_total'] = 1296;

        $created = $this->authenticated()->postJson('/invoices', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('invoices', [
            'id' => (int) $created->json('invoice_id'),
            'sst_percent' => 8,
            'sst_amount' => 96,
            'grand_total' => 1296,
            'calculation_version' => 'typed_lines_v1',
        ]);
    }

    public function test_legacy_invoice_payload_records_overage_without_blocking_a_cached_tab(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->quotePayload());
        $quoteId = (int) $create->json('quote_id');
        $award = $this->authenticated()->postJson("/quote-records/ih/{$quoteId}/award", [
            'quote_id' => $quoteId,
            'remarks' => 'Client awarded the work.',
            'award_date' => '2026-07-27',
            'description' => 'Industrial hygiene monitoring scope.',
            'client_award_ref_no' => 'CLIENT-LOA-LEGACY-OVER',
        ]);
        $projectId = (int) $award->json('project_id');
        $payload = $this->invoicePayload($projectId, $quoteId);
        unset($payload['sst_percent'], $payload['calculation_version']);
        $payload['amount'] = 1300;
        $payload['grand_total'] = 1300;
        $payload['breakdown'][0]['unit_price'] = 1300;

        $created = $this->authenticated()->postJson('/invoices', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $invoice = DB::table('invoices')->where('id', (int) $created->json('invoice_id'))->first();
        $this->assertSame(10, (int) $invoice->deviation_acknowledged_by);
        $this->assertStringContainsString('Legacy invoice client compatibility', $invoice->deviation_reason);
    }

    public function test_cached_current_invoice_payload_without_version_keeps_guided_overage_validation(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->quotePayload());
        $quoteId = (int) $create->json('quote_id');
        $award = $this->authenticated()->postJson("/quote-records/ih/{$quoteId}/award", [
            'quote_id' => $quoteId,
            'remarks' => 'Client awarded the work.',
            'award_date' => '2026-07-27',
            'description' => 'Industrial hygiene monitoring scope.',
            'client_award_ref_no' => 'CLIENT-LOA-CACHED-CURRENT',
        ]);
        $projectId = (int) $award->json('project_id');
        $payload = $this->invoicePayload($projectId, $quoteId);
        unset($payload['calculation_version']);
        $payload['amount'] = 1300;
        $payload['grand_total'] = 1300;
        $payload['breakdown'][0]['unit_price'] = 1300;

        $this->authenticated()->postJson('/invoices', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invoice_over_project_value')
            ->assertJsonPath('context.overage', 100)
            ->assertJsonPath(
                'field_errors.deviation_reason.0',
                'Briefly explain why this invoice exceeds the project value.',
            );
    }

    public function test_legacy_invoice_edit_without_sst_percent_preserves_its_sst(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->quotePayload([
            'sst_percent' => 8,
        ]));
        $quoteId = (int) $create->json('quote_id');
        $award = $this->authenticated()->postJson("/quote-records/ih/{$quoteId}/award", [
            'quote_id' => $quoteId,
            'remarks' => 'Client awarded the work.',
            'award_date' => '2026-07-27',
            'description' => 'Industrial hygiene monitoring scope.',
            'client_award_ref_no' => 'CLIENT-LOA-LEGACY-EDIT',
        ]);
        $projectId = (int) $award->json('project_id');
        $currentPayload = $this->invoicePayload($projectId, $quoteId);
        $currentPayload['sst_percent'] = 8;
        $currentPayload['sst_amount'] = 96;
        $currentPayload['grand_total'] = 1296;
        $created = $this->authenticated()->postJson('/invoices', $currentPayload)->assertOk();
        $invoice = DB::table('invoices')->where('id', (int) $created->json('invoice_id'))->first();

        $legacyPayload = $currentPayload;
        unset($legacyPayload['sst_percent'], $legacyPayload['calculation_version']);
        $legacyPayload['invoice_ref_no'] = $invoice->invoice_ref_no;
        $legacyPayload['status'] = 'Pending';
        $legacyPayload['remarks'] = 'Saved from a cached invoice editor.';

        $this->authenticated()->putJson('/invoices', $legacyPayload)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'sst_percent' => 8,
            'sst_amount' => 96,
            'grand_total' => 1296,
        ]);
    }

    public function test_paid_ih_invoice_financials_are_locked_with_recovery_guidance(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->quotePayload());
        $quoteId = (int) $create->json('quote_id');
        $award = $this->authenticated()->postJson("/quote-records/ih/{$quoteId}/award", [
            'quote_id' => $quoteId,
            'remarks' => 'Client awarded the work.',
            'award_date' => '2026-07-27',
            'description' => 'Industrial hygiene monitoring scope.',
            'client_award_ref_no' => 'CLIENT-LOA-PAID',
        ]);
        $projectId = (int) $award->json('project_id');
        $created = $this->authenticated()->postJson('/invoices', $this->invoicePayload($projectId, $quoteId));
        $invoiceId = (int) $created->json('invoice_id');
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        DB::table('invoices')->where('id', $invoiceId)->update([
            'status' => 'Paid',
            'paid_amount' => 1200,
            'paid_date' => '2026-07-28',
        ]);

        $payload = $this->invoicePayload($projectId, $quoteId);
        $payload['invoice_ref_no'] = $invoice->invoice_ref_no;
        $payload['status'] = 'Paid';
        $payload['amount'] = 1100;
        $payload['grand_total'] = 1100;
        $payload['breakdown'][0]['unit_price'] = 1100;
        $payload['breakdown'][0]['line_type'] = 'service';

        $this->authenticated()->putJson('/invoices', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invoice_financials_locked')
            ->assertJsonPath(
                'message',
                'Financial values cannot be changed because payment has already been recorded.',
            )
            ->assertJsonPath('allowed_actions.0', 'view_invoice');
    }

    public function test_unpaid_ih_invoice_edit_overage_requires_a_guided_acknowledgement(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->quotePayload());
        $quoteId = (int) $create->json('quote_id');
        $award = $this->authenticated()->postJson("/quote-records/ih/{$quoteId}/award", [
            'quote_id' => $quoteId,
            'remarks' => 'Client awarded the work.',
            'award_date' => '2026-07-27',
            'description' => 'Industrial hygiene monitoring scope.',
            'client_award_ref_no' => 'CLIENT-LOA-EDIT-OVER',
        ]);
        $projectId = (int) $award->json('project_id');
        $created = $this->authenticated()->postJson('/invoices', $this->invoicePayload($projectId, $quoteId));
        $invoice = DB::table('invoices')->where('id', (int) $created->json('invoice_id'))->first();

        $payload = $this->invoicePayload($projectId, $quoteId);
        $payload['invoice_ref_no'] = $invoice->invoice_ref_no;
        $payload['status'] = 'Pending';
        $payload['amount'] = 1300;
        $payload['grand_total'] = 1300;
        $payload['breakdown'][0]['unit_price'] = 1300;
        $payload['breakdown'][0]['line_type'] = 'service';

        $this->authenticated()->putJson('/invoices', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invoice_over_project_value')
            ->assertJsonPath('context.overage', 100)
            ->assertJsonPath(
                'field_errors.deviation_acknowledged.0',
                'Confirm the project-value difference to continue.',
            );

        $payload['deviation_reason'] = 'Approved additional monitoring visit.';
        $payload['deviation_acknowledged'] = true;
        $this->authenticated()->putJson('/invoices', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'grand_total' => 1300,
        ]);
    }

    private function quotePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'client_id' => 1,
            'client_name' => 'Client A',
            'client_ssm' => 'SSM-1',
            'client_address' => '1 Test Road',
            'client_city' => 'Test City',
            'client_state' => 'Test State',
            'client_zip' => '12345',
            'pic_name' => 'Client PIC',
            'pic_email' => 'pic@example.test',
            'pic_phone' => '60123456789',
            'pic_position' => 'Manager',
            'service_id' => 201,
            'service_title' => 'IH Monitoring',
            'service_code' => 'IHM',
            'site_address' => 'Client Site',
            'travel_charge' => 0,
            'sample_counts' => 2,
            'sample_unit' => 'sample(s)',
            'num_work_units' => 1,
            'unit_price' => 500,
            'discount' => 0,
            'sst_percent' => 0,
            'estimated_total_cost' => 500,
            'inquiry_remarks' => '',
            'attach_proposal' => 1,
            'proposal_language' => 'en',
            'hygiene_items' => [[
                'item_description' => 'Laboratory analysis',
                'description' => 'Analysis of collected samples.',
                'quantity' => 1,
                'unit' => 'Lot',
                'unit_price' => 200,
            ]],
        ], $overrides);
    }

    private function invoicePayload(int $projectId, int $quoteId): array
    {
        return [
            'project_id' => $projectId,
            'quote_id' => $quoteId,
            'service_type' => 'Industrial Hygiene',
            'client_award_ref_no' => 'CLIENT-LOA-001',
            'invoice_client_name' => 'Client A',
            'invoice_client_ssm' => 'SSM-1',
            'invoice_client_tin' => 'TIN-1',
            'invoice_client_address' => '1 Test Road',
            'invoice_client_city' => 'Test City',
            'invoice_client_state' => 'Test State',
            'invoice_client_zip' => '12345',
            'invoice_pic_name' => 'Client PIC',
            'invoice_pic_phone' => '60123456789',
            'invoice_pic_email' => 'pic@example.test',
            'invoice_pic_position' => 'Manager',
            'invoice_date' => '2026-07-27',
            'payment_method' => 'Bank Transfer',
            'amount' => 1200,
            'sst_amount' => 0,
            'sst_percent' => 0,
            'grand_total' => 1200,
            'calculation_version' => 'typed_lines_v1',
            'breakdown' => [[
                'item_description' => 'Industrial hygiene services',
                'description' => 'Awarded IH monitoring scope.',
                'unit' => 'Lot',
                'quantity' => 1,
                'unit_price' => 1200,
            ]],
        ];
    }

    private function deliveryOrderPayload(int $projectId): array
    {
        return [
            'details' => [
                'client_name' => 'Client A',
                'client_address' => '1 Test Road',
                'client_contact_name' => 'Client PIC',
                'client_contact_position' => 'Manager',
                'client_contact_email' => 'pic@example.test',
                'client_contact_phone' => '60123456789',
                'company_contact_name' => 'System Admin',
                'company_contact_email' => 'sysadmin@example.test',
                'company_contact_phone' => '601100000000',
                'project_id' => $projectId,
                'project_name' => 'IH Monitoring',
                'project_code' => "IH-{$projectId}",
                'project_award_date' => '2026-07-27',
                'project_type' => 'Industrial Hygiene',
                'project_description' => 'Industrial hygiene monitoring scope.',
                'project_service_period' => 'July 2026',
            ],
            'breakdown' => [[
                'item_name' => 'Monitoring report',
                'description' => 'Final industrial hygiene report.',
                'quantity' => 1,
                'unit' => 'Lot',
            ]],
        ];
    }

    private function vendorLoaPayload(): array
    {
        return [
            'vendor_id' => 7,
            'award_value' => 350,
            'award_date' => '2026-07-27',
            'position' => 'Laboratory',
            'remarks' => 'Analyse collected samples.',
            'services_description' => 'Laboratory analysis services.',
            'venue_details' => 'Vendor laboratory.',
            'fee_breakdown' => 'Analysis fee: RM350.',
            'payment_terms' => '30 days',
        ];
    }

    private function seedExistingDeliveryOrderNumber(): void
    {
        DB::table('do_details')->insert([
            'do_number' => 'DO'.date('y').'-009XYZ',
            'client_name' => 'Other Client',
            'client_address' => 'Other Address',
            'client_contact_name' => 'Other PIC',
            'client_contact_position' => 'Manager',
            'client_contact_email' => 'other@example.test',
            'client_contact_phone' => '60000000000',
            'company_contact_name' => 'Other Staff',
            'project_name' => 'Unrelated Project',
            'project_code' => 'OTHER',
            'project_award_date' => '2026-07-01',
            'created_by' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function supplierPoPayload(int $projectId): array
    {
        return [
            'project_id' => $projectId,
            'supplier' => [
                'id' => 7,
                'company_name' => 'Laboratory Supplier',
                'full_address' => '7 Lab Road',
                'contact_name' => 'Vendor PIC',
                'contact_number' => '60112223333',
            ],
            'items' => [[
                'item_id' => 701,
                'item_name' => 'Sampling media',
                'description' => 'Industrial hygiene sampling media.',
                'unit' => 'box',
                'quantity' => 2,
                'unit_price' => 50,
                'line_total' => 100,
            ]],
            'discount' => 0,
            'delivery_charge' => 0,
            'sst_percent' => 0,
            'sst_amount' => 0,
            'grand_total' => 100,
        ];
    }

    private function jd14Payload(int $projectId): array
    {
        return [
            'project_id' => $projectId,
            'employer_name' => 'Client A',
            'employer_address' => '1 Test Road',
            'approval_no' => 'SHOULD-NOT-CREATE',
            'course_title' => 'Not applicable to IH',
            'training_venue' => 'Client Site',
            'commenced_date' => '2026-07-27',
            'end_date' => '2026-07-27',
        ];
    }

    private function authenticated(): self
    {
        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 10,
                'name_code' => 'AZA',
                'roles' => ['System Admin'],
                'full_name' => 'System Admin',
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }
}
