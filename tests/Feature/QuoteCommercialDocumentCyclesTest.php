<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommercialCyclePayloads;
use Tests\Support\CommercialCycleQuoteSchemas;
use Tests\Support\IhCommercialCycleDatabase;
use Tests\TestCase;

class QuoteCommercialDocumentCyclesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        IhCommercialCycleDatabase::create();
    }

    public function test_equipment_remarks_are_optional_validated_and_pricing_neutral(): void
    {
        CommercialCycleQuoteSchemas::replace('equipment');

        $create = $this->authenticated()->postJson(
            '/quotes/equipment',
            CommercialCyclePayloads::quote('equipment'),
        );

        $create->assertOk()->assertJsonPath('status', 'success');
        $quoteId = (int) $create->json('quote_id');
        $this->assertDatabaseHas('quotes_equipment', [
            'id' => $quoteId,
            'grand_total' => 1000,
        ]);

        $this->authenticated()->postJson('/quotes/equipment', CommercialCyclePayloads::quote(
            'equipment',
            ['quotation_remarks' => str_repeat('x', 2001)],
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('quotation_remarks');

        $this->authenticated()->postJson('/quotes/equipment', CommercialCyclePayloads::quote(
            'equipment',
            ['items' => [['item_remarks' => str_repeat('x', 2001)]]],
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.item_remarks');
    }

    #[DataProvider('quoteServices')]
    public function test_quote_runs_through_edit_revision_award_project_and_commercial_documents(
        string $service,
        string $projectType,
        float $invoiceAmount,
    ): void {
        CommercialCycleQuoteSchemas::replace($service);

        $create = $this->authenticated()->postJson("/quotes/{$service}", CommercialCyclePayloads::quote($service));
        $create->assertOk()->assertJsonPath('status', 'success');
        $quoteId = (int) $create->json('quote_id');

        if ($service === 'equipment') {
            $this->assertDatabaseHas('quotes_equipment', [
                'id' => $quoteId,
                'quotation_remarks' => 'All supplied equipment must use the approved client colour scheme.',
            ]);
            $this->assertDatabaseHas('quotes_equipment_items', [
                'quote_id' => $quoteId,
                'item_remarks' => 'Colour: navy blue; enclosure size: compact.',
            ]);
            $this->authenticated()->getJson("/quotes/equipment/{$quoteId}")
                ->assertOk()
                ->assertJsonPath('data.quotation_remarks', 'All supplied equipment must use the approved client colour scheme.')
                ->assertJsonPath('data.items.0.item_remarks', 'Colour: navy blue; enclosure size: compact.');
        }

        $this->authenticated()->putJson(
            "/quotes/{$service}/{$quoteId}",
            CommercialCyclePayloads::quote($service, $this->quoteChange($service, false)),
        )
            ->assertOk()
            ->assertJsonPath('data.revision_no', 0);

        $this->authenticated()->putJson(
            "/quotes/{$service}/{$quoteId}",
            CommercialCyclePayloads::quote($service, $this->quoteChange($service, true)),
        )
            ->assertOk()
            ->assertJsonPath('data.revision_no', 1);

        if ($service === 'special') {
            $this->approveCurrentSpecialQuote($quoteId);
        }

        $award = $this->authenticated()->postJson(
            "/quote-records/{$service}/{$quoteId}/award",
            ['quote_id' => $quoteId] + CommercialCyclePayloads::award($service),
        );
        $award->assertOk()->assertJsonPath('status', 'success');
        $projectId = (int) $award->json('project_id');

        $this->assertDatabaseHas('projects_main', [
            'id' => $projectId,
            'quote_id' => $quoteId,
            'quote_type' => $service,
            'project_type' => $projectType,
            'status' => 'Active',
        ]);
        $projectResponse = $this->authenticated()->getJson("/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $projectId)
            ->assertJsonPath('data.project_type', $projectType);
        $invoiceQuoteResponse = $this->authenticated()->getJson("/invoices/quote/{$service}/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('id', $quoteId);
        if ($service === 'equipment') {
            $projectResponse->assertJsonPath(
                'data.equipment_items.0.item_remarks',
                'Client-approved colour scheme for this revision.',
            );
            $invoiceQuoteResponse
                ->assertJsonPath('quotation_remarks', 'Formal revision requirements.')
                ->assertJsonPath(
                    'equipment_items.0.item_remarks',
                    'Client-approved colour scheme for this revision.',
                );
        }

        $jd14Id = null;
        if ($service === 'training') {
            $jd14 = $this->authenticated()->postJson('/jd14-forms', CommercialCyclePayloads::jd14($projectId));
            $jd14->assertOk()->assertJsonPath('status', 'success');
            $jd14Id = (int) $jd14->json('form_number');
        } else {
            $this->authenticated()->postJson('/jd14-forms', CommercialCyclePayloads::jd14($projectId))
                ->assertStatus(422)
                ->assertJsonPath('message', 'JD14 forms can only be generated for Training projects.');
        }

        $invoice = $this->authenticated()->postJson(
            '/invoices',
            CommercialCyclePayloads::invoice($projectId, $quoteId, $projectType, $invoiceAmount),
        );
        $invoice->assertOk()->assertJsonPath('status', 'success');
        $invoiceId = (int) $invoice->json('invoice_id');

        $deliveryOrder = $this->authenticated()->postJson(
            '/delivery-orders',
            CommercialCyclePayloads::deliveryOrder($projectId, $projectType),
        );
        $deliveryOrder->assertOk()->assertJsonPath('status', 'success');
        $deliveryOrderId = (int) $deliveryOrder->json('do_id');

        $vendorPayload = CommercialCyclePayloads::vendorLoa();
        if ($service === 'equipment') {
            unset($vendorPayload['remarks'], $vendorPayload['services_description']);
        }
        $this->authenticated()->postJson(
            "/projects/{$projectId}/vendors",
            $vendorPayload,
        )
            ->assertOk()
            ->assertJsonPath('status', 'success');
        $vendorLoaId = (int) DB::table('project_vendors')->where('project_id', $projectId)->value('id');

        $supplierPo = $this->authenticated()->postJson(
            '/catalog/purchase-orders',
            CommercialCyclePayloads::supplierPo($projectId),
        );
        $supplierPo->assertOk()->assertJsonPath('status', 'success');
        $supplierPoId = (int) $supplierPo->json('po_id');

        if ($service === 'equipment') {
            $expectedQuotationRemarks = 'Formal revision requirements.';
            $expectedItemRemarks = 'Client-approved colour scheme for this revision.';

            $this->assertDatabaseHas('invoices', [
                'id' => $invoiceId,
                'quotation_remarks' => $expectedQuotationRemarks,
            ]);
            $this->assertDatabaseHas('invoice_breakdown', [
                'invoice_id' => $invoiceId,
                'item_description' => 'Gas detector',
                'item_remarks' => $expectedItemRemarks,
            ]);
            $this->assertDatabaseHas('do_details', [
                'id' => $deliveryOrderId,
                'quotation_remarks' => $expectedQuotationRemarks,
            ]);
            $this->assertDatabaseHas('do_breakdown', [
                'do_id' => $deliveryOrderId,
                'item_name' => 'Gas detector',
                'item_remarks' => $expectedItemRemarks,
            ]);
            $this->assertDatabaseHas('supplier_po_main', [
                'po_id' => $supplierPoId,
                'quotation_remarks' => $expectedQuotationRemarks,
            ]);
            $this->assertDatabaseHas('supplier_po_items', [
                'po_id' => $supplierPoId,
                'item_id' => 701,
                'item_remarks' => $expectedItemRemarks,
            ]);
            $this->assertDatabaseHas('project_vendors', [
                'id' => $vendorLoaId,
                'remarks' => $expectedQuotationRemarks,
            ]);
            $loaServices = (string) DB::table('project_vendors')
                ->where('id', $vendorLoaId)
                ->value('services_description');
            $this->assertStringContainsString('Portable calibrated gas detector.', $loaServices);
            $this->assertStringContainsString($expectedItemRemarks, $loaServices);
        }

        $related = $this->authenticated()->getJson("/quote-records/{$service}/{$quoteId}/related-docs");
        $related->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.projects.0.id', $projectId)
            ->assertJsonPath('data.invoices.0.id', $invoiceId)
            ->assertJsonPath('data.delivery_orders.0.id', $deliveryOrderId)
            ->assertJsonPath('data.vendor_loas.0.id', $vendorLoaId)
            ->assertJsonPath('data.supplier_pos.0.po_id', $supplierPoId);
        if ($jd14Id) {
            $related->assertJsonPath('data.jd14.0.id', $jd14Id);
        }

        $this->authenticated()->putJson(
            "/quotes/{$service}/{$quoteId}",
            CommercialCyclePayloads::quote($service, $this->postAwardRevision($service)),
        )
            ->assertOk()
            ->assertJsonPath('data.revision_no', 2);

        if ($service === 'equipment') {
            $this->assertSame(
                'Formal revision requirements.',
                DB::table('invoices')->where('id', $invoiceId)->value('quotation_remarks'),
            );
            $this->assertSame(
                'Client-approved colour scheme for this revision.',
                DB::table('do_breakdown')->where('do_id', $deliveryOrderId)->value('item_remarks'),
            );
            $this->assertSame(
                'Client-approved colour scheme for this revision.',
                DB::table('supplier_po_items')->where('po_id', $supplierPoId)->value('item_remarks'),
            );
        }

        $this->removeCommercialDocumentsExceptSupplierPo(
            $invoiceId,
            $deliveryOrderId,
            $vendorLoaId,
            $jd14Id,
        );

        $unaward = $this->authenticated()->postJson("/quote-records/{$service}/{$quoteId}/un-award", [
            'quote_id' => $quoteId,
        ]);
        $unaward->assertClientError()
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

    public static function quoteServices(): array
    {
        return [
            'training' => ['training', 'Training', 5000],
            'equipment' => ['equipment', 'Equipment Supply', 1000],
            'manpower' => ['manpower', 'Manpower Supply', 4000],
            'special' => ['special', 'Special Service', 2000],
        ];
    }

    private function quoteChange(string $service, bool $revision): array
    {
        $change = match ($service) {
            'training' => ['remarks' => $revision ? 'Formal revision.' : 'Edited quote.'],
            'equipment' => [
                'delivery_charge' => $revision ? 25 : 10,
                'quotation_remarks' => $revision
                    ? 'Formal revision requirements.'
                    : 'Edited quotation requirements.',
                'items' => [[
                    'item_remarks' => $revision
                        ? 'Client-approved colour scheme for this revision.'
                        : 'Draft colour specification.',
                ]],
            ],
            'manpower' => ['inquiry_remarks' => $revision ? 'Formal revision.' : 'Edited quote.'],
            'special' => ['general_remarks' => $revision ? 'Formal revision.' : 'Edited quote.'],
        };

        return $revision ? ['isRevision' => true] + $change : $change;
    }

    private function postAwardRevision(string $service): array
    {
        return ['isRevision' => true, 'project_value_sync_decision' => 'keep'] + match ($service) {
            'training' => ['unit_price' => 5100],
            'equipment' => ['items' => [[
                'catalog_item_id' => 701,
                'item_name' => 'Gas detector',
                'unit_price' => 700,
                'marked_up_price' => 1100,
                'quantity' => 1,
                'total_price' => 1100,
            ]]],
            'manpower' => ['unit_cost' => 4100],
            'special' => ['line_items' => [[
                'item_name' => 'Compliance review',
                'description' => 'Revised custom site compliance review.',
                'unit' => 'Lot',
                'unit_price' => 2100,
                'quantity' => 1,
                'total_price' => 2100,
            ]]],
        };
    }

    private function approveCurrentSpecialQuote(int $quoteId): void
    {
        $approvalId = (int) DB::table('quote_approval_requests')
            ->where('service', 'special')
            ->where('quote_id', $quoteId)
            ->where('is_current', true)
            ->value('id');

        $this->assertGreaterThan(0, $approvalId);
        $this->authenticated()->patchJson("/quote-approvals/{$approvalId}/approve", [
            'remarks' => 'Approved for the full-cycle test.',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    private function removeCommercialDocumentsExceptSupplierPo(
        int $invoiceId,
        int $deliveryOrderId,
        int $vendorLoaId,
        ?int $jd14Id,
    ): void {
        DB::table('invoice_breakdown')->where('invoice_id', $invoiceId)->delete();
        DB::table('invoices')->where('id', $invoiceId)->delete();
        DB::table('do_breakdown')->where('do_id', $deliveryOrderId)->delete();
        DB::table('do_details')->where('id', $deliveryOrderId)->delete();
        DB::table('project_vendors')->where('id', $vendorLoaId)->delete();
        if ($jd14Id) {
            DB::table('invoices_jd14form')->where('id', $jd14Id)->delete();
        }
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
