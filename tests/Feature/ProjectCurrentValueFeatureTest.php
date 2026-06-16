<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use App\Services\Projects\ProjectValueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectCurrentValueFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            RequireAuth::class,
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);

        $this->createTables();
    }

    public function test_project_value_endpoint_updates_current_value_only_and_writes_history(): void
    {
        DB::table('projects_main')->insert([
            'id' => 501,
            'project_name' => 'Variation Project',
            'project_type' => 'Training',
            'quote_id' => 44,
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);

        $this->actingSession()
            ->patchJson('/projects/501/value', [
                'current_project_value' => 1250,
                'reason' => 'Approved variation order.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.quote_value', 1000)
            ->assertJsonPath('data.current_project_value', 1250)
            ->assertJsonPath('data.resolved_project_value', 1250);

        $this->assertDatabaseHas('projects_main', [
            'id' => 501,
            'quote_value' => 1000,
            'current_project_value' => 1250,
        ]);
        $this->assertDatabaseHas('project_value_revisions', [
            'project_id' => 501,
            'source' => ProjectValueService::SOURCE_PROJECT_MANAGEMENT,
            'old_value' => 1000,
            'new_value' => 1250,
            'reason' => 'Approved variation order.',
        ]);
        $this->assertDatabaseHas('project_progress', [
            'project_id' => 501,
            'updated_by' => 10,
        ]);
    }

    public function test_project_value_impact_preview_classifies_related_commercial_documents(): void
    {
        DB::table('projects_main')->insert([
            'id' => 506,
            'project_name' => 'Commercial Impact Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 601,
            'project_id' => 506,
            'invoice_ref_no' => 'INV-EDIT',
            'status' => 'Pending',
            'amount' => 900,
            'sst_amount' => 0,
            'grand_total' => 900,
        ]);
        DB::table('invoices')->insert([
            'id' => 602,
            'project_id' => 506,
            'invoice_ref_no' => 'INV-PAID',
            'status' => 'Paid',
            'amount' => 1000,
            'sst_amount' => 0,
            'grand_total' => 1000,
            'paid_amount' => 1000,
            'paid_date' => '2026-06-01',
        ]);
        DB::table('invoices')->insert([
            'id' => 607,
            'project_id' => 506,
            'invoice_ref_no' => 'INV-VOID',
            'status' => 'Void',
            'amount' => 1000,
            'sst_amount' => 0,
            'grand_total' => 1000,
        ]);
        DB::table('do_details')->insert(['id' => 701, 'project_id' => 506, 'do_number' => 'DO-001']);
        DB::table('invoices_jd14form')->insert(['id' => 801, 'project_id' => 506, 'approval_no' => 'JD14-001']);

        $this->actingSession()
            ->postJson('/projects/506/value/impact-preview', [
                'current_project_value' => 1200,
                'reason' => 'Approved variation order.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.old_project_value', 1000)
            ->assertJsonPath('data.new_project_value', 1200)
            ->assertJsonPath('data.summary.invoice_count', 3)
            ->assertJsonPath('data.summary.payment_record_count', 1)
            ->assertJsonPath('data.summary.blocked_count', 1)
            ->assertJsonPath('data.summary.delivery_order_count', 1)
            ->assertJsonPath('data.summary.jd14_count', 1)
            ->assertJsonPath('data.documents.invoices.0.classification', 'editable')
            ->assertJsonPath('data.documents.invoices.1.classification', 'adjustment_required')
            ->assertJsonPath('data.documents.payment_adjustments.0.action', 'record_adjustment_required')
            ->assertJsonPath('data.documents.blocked_items.0.reference', 'INV-VOID')
            ->assertJsonPath('data.documents.blocked_items.0.action', 'none');
    }

    public function test_project_value_update_requires_acknowledgement_when_commercial_documents_exist(): void
    {
        DB::table('projects_main')->insert([
            'id' => 507,
            'project_name' => 'Acknowledgement Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 603,
            'project_id' => 507,
            'invoice_ref_no' => 'INV-ACK',
            'status' => 'Pending',
            'amount' => 1000,
            'sst_amount' => 0,
            'grand_total' => 1000,
        ]);

        $this->actingSession()
            ->patchJson('/projects/507/value', [
                'current_project_value' => 1300,
                'reason' => 'Approved variation order.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'commercial_impact_acknowledgement_required');

        $this->assertDatabaseHas('projects_main', [
            'id' => 507,
            'current_project_value' => null,
        ]);
    }

    public function test_project_value_noop_does_not_require_commercial_acknowledgement(): void
    {
        DB::table('projects_main')->insert([
            'id' => 510,
            'project_name' => 'No Change Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 606,
            'project_id' => 510,
            'invoice_ref_no' => 'INV-NOCHANGE',
            'status' => 'Pending',
            'amount' => 1000,
            'sst_amount' => 0,
            'grand_total' => 1000,
        ]);

        $this->actingSession()
            ->patchJson('/projects/510/value', [
                'current_project_value' => 1000,
                'reason' => 'No amount change.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.revision_id', null);

        $this->assertDatabaseMissing('project_value_revisions', [
            'project_id' => 510,
        ]);
    }

    public function test_project_value_update_syncs_selected_editable_invoice_with_variation_line(): void
    {
        DB::table('projects_main')->insert([
            'id' => 508,
            'project_name' => 'Invoice Sync Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 604,
            'project_id' => 508,
            'invoice_ref_no' => 'INV-SYNC',
            'status' => 'Pending',
            'amount' => 1000,
            'sst_amount' => 0,
            'grand_total' => 1000,
        ]);
        DB::table('invoice_breakdown')->insert([
            'invoice_id' => 604,
            'item_description' => 'Original Service',
            'description' => null,
            'quantity' => 1,
            'unit' => 'Lot',
            'unit_price' => 1000,
            'subtotal' => 1000,
            'sort_order' => 1,
        ]);

        $this->actingSession()
            ->patchJson('/projects/508/value', [
                'current_project_value' => 1250,
                'reason' => 'Approved variation order.',
                'acknowledgement' => true,
                'sync' => [
                    'invoices' => [604],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.resolved_project_value', 1250)
            ->assertJsonPath('data.commercial_sync.applied.invoices.0.id', 604);

        $this->assertDatabaseHas('invoices', [
            'id' => 604,
            'amount' => 1250,
            'grand_total' => 1250,
        ]);
        $this->assertDatabaseHas('invoice_breakdown', [
            'invoice_id' => 604,
            'item_description' => 'Project Value Variation',
            'unit_price' => 250,
            'subtotal' => 250,
            'system_adjustment_key' => 'project_value_adjustment',
        ]);
        $this->assertDatabaseHas('project_value_revision_documents', [
            'project_id' => 508,
            'document_type' => 'invoice',
            'document_id' => 604,
            'action' => 'updated_invoice_total',
            'old_amount' => 1000,
            'new_amount' => 1250,
        ]);
    }

    public function test_unchanged_project_value_can_resync_selected_editable_invoice(): void
    {
        DB::table('projects_main')->insert([
            'id' => 512,
            'project_name' => 'Commercial Resync Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => 1250,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 609,
            'project_id' => 512,
            'invoice_ref_no' => 'INV-RESYNC',
            'status' => 'Pending',
            'amount' => 1000,
            'sst_amount' => 0,
            'grand_total' => 1000,
        ]);
        DB::table('invoice_breakdown')->insert([
            'invoice_id' => 609,
            'item_description' => 'Original Service',
            'quantity' => 1,
            'unit' => 'Lot',
            'unit_price' => 1000,
            'subtotal' => 1000,
            'sort_order' => 1,
        ]);

        $this->actingSession()
            ->patchJson('/projects/512/value', [
                'current_project_value' => 1250,
                'reason' => 'Resync skipped invoice.',
                'acknowledgement' => true,
                'sync' => [
                    'invoices' => [609],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Commercial documents resynced successfully.')
            ->assertJsonPath('data.current_project_value', 1250)
            ->assertJsonPath('data.commercial_sync.applied.invoices.0.id', 609);

        $this->assertDatabaseHas('projects_main', [
            'id' => 512,
            'quote_value' => 1000,
            'current_project_value' => 1250,
        ]);
        $this->assertDatabaseHas('project_value_revisions', [
            'project_id' => 512,
            'source' => ProjectValueService::SOURCE_COMMERCIAL_RESYNC,
            'old_value' => 1250,
            'new_value' => 1250,
            'reason' => 'Resync skipped invoice.',
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => 609,
            'amount' => 1250,
            'grand_total' => 1250,
        ]);
        $this->assertDatabaseHas('invoice_breakdown', [
            'invoice_id' => 609,
            'item_description' => 'Project Value Variation',
            'unit_price' => 250,
            'subtotal' => 250,
            'system_adjustment_key' => 'project_value_adjustment',
        ]);
        $this->assertDatabaseHas('project_value_revision_documents', [
            'project_id' => 512,
            'document_type' => 'invoice',
            'document_id' => 609,
            'action' => 'resynced_invoice_total',
        ]);
    }

    public function test_unchanged_project_value_with_only_informational_delivery_order_sync_is_noop(): void
    {
        DB::table('projects_main')->insert([
            'id' => 523,
            'project_name' => 'Informational Resync Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => 1250,
            'status' => 'Active',
        ]);
        DB::table('do_details')->insert([
            'id' => 703,
            'project_id' => 523,
            'do_number' => 'DO-INFO',
        ]);

        $this->actingSession()
            ->patchJson('/projects/523/value', [
                'current_project_value' => 1250,
                'reason' => 'No value document sync required.',
                'acknowledgement' => true,
                'sync' => [
                    'delivery_orders' => [703],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Project current value is unchanged.')
            ->assertJsonPath('data.revision_id', null);

        $this->assertDatabaseMissing('project_value_revisions', [
            'project_id' => 523,
        ]);
        $this->assertDatabaseMissing('project_value_revision_documents', [
            'project_id' => 523,
        ]);
    }

    public function test_project_value_decrease_creates_reduction_line(): void
    {
        DB::table('projects_main')->insert([
            'id' => 513,
            'project_name' => 'Reduction Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 610,
            'project_id' => 513,
            'invoice_ref_no' => 'INV-REDUCE',
            'status' => 'Pending',
            'amount' => 1000,
            'sst_amount' => 0,
            'grand_total' => 1000,
        ]);

        $this->actingSession()
            ->patchJson('/projects/513/value', [
                'current_project_value' => 800,
                'reason' => 'Client approved reduced scope.',
                'acknowledgement' => true,
                'sync' => [
                    'invoices' => [610],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.commercial_sync.applied.invoices.0.adjustment_label', 'Project Value Reduction');

        $this->assertDatabaseHas('invoices', [
            'id' => 610,
            'amount' => 800,
            'grand_total' => 800,
        ]);
        $this->assertDatabaseHas('invoice_breakdown', [
            'invoice_id' => 610,
            'item_description' => 'Project Value Reduction',
            'unit_price' => -200,
            'subtotal' => -200,
            'system_adjustment_key' => 'project_value_adjustment',
        ]);
    }

    public function test_invoice_sync_preserves_sst_and_adjusts_pre_sst_amount(): void
    {
        DB::table('projects_main')->insert([
            'id' => 514,
            'project_name' => 'SST Sync Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 611,
            'project_id' => 514,
            'invoice_ref_no' => 'INV-SST',
            'status' => 'Pending',
            'amount' => 1000,
            'sst_amount' => 60,
            'grand_total' => 1060,
        ]);
        DB::table('invoice_breakdown')->insert([
            'invoice_id' => 611,
            'item_description' => 'Original Service',
            'quantity' => 1,
            'unit' => 'Lot',
            'unit_price' => 1000,
            'subtotal' => 1000,
            'sort_order' => 1,
        ]);

        $this->actingSession()
            ->patchJson('/projects/514/value', [
                'current_project_value' => 1300,
                'reason' => 'Approved variation with existing SST.',
                'acknowledgement' => true,
                'sync' => [
                    'invoices' => [611],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('invoices', [
            'id' => 611,
            'amount' => 1240,
            'sst_amount' => 60,
            'grand_total' => 1300,
        ]);
        $this->assertDatabaseHas('invoice_breakdown', [
            'invoice_id' => 611,
            'item_description' => 'Project Value Variation',
            'unit_price' => 240,
            'subtotal' => 240,
        ]);
    }

    public function test_invoice_sync_blocks_when_target_project_value_is_below_existing_sst(): void
    {
        DB::table('projects_main')->insert([
            'id' => 515,
            'project_name' => 'SST Block Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 612,
            'project_id' => 515,
            'invoice_ref_no' => 'INV-SST-BLOCK',
            'status' => 'Pending',
            'amount' => 1000,
            'sst_amount' => 60,
            'grand_total' => 1060,
        ]);

        $this->actingSession()
            ->patchJson('/projects/515/value', [
                'current_project_value' => 50,
                'reason' => 'Invalid lower than SST.',
                'acknowledgement' => true,
                'sync' => [
                    'invoices' => [612],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'commercial_sync_failed');

        $this->assertDatabaseHas('projects_main', [
            'id' => 515,
            'current_project_value' => null,
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => 612,
            'amount' => 1000,
            'sst_amount' => 60,
            'grand_total' => 1060,
        ]);
    }

    public function test_user_authored_project_value_variation_line_is_not_mutated(): void
    {
        DB::table('projects_main')->insert([
            'id' => 516,
            'project_name' => 'User Variation Line Project',
            'project_type' => 'Training',
            'quote_value' => 1099,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 613,
            'project_id' => 516,
            'invoice_ref_no' => 'INV-USER-LINE',
            'status' => 'Pending',
            'amount' => 1099,
            'sst_amount' => 0,
            'grand_total' => 1099,
        ]);
        DB::table('invoice_breakdown')->insert([
            [
                'invoice_id' => 613,
                'item_description' => 'Original Service',
                'description' => null,
                'quantity' => 1,
                'unit' => 'Lot',
                'unit_price' => 1000,
                'subtotal' => 1000,
                'sort_order' => 1,
            ],
            [
                'invoice_id' => 613,
                'item_description' => 'Project Value Variation',
                'description' => 'User-authored commercial line.',
                'quantity' => 1,
                'unit' => 'Lot',
                'unit_price' => 99,
                'subtotal' => 99,
                'sort_order' => 2,
            ],
        ]);

        $this->actingSession()
            ->patchJson('/projects/516/value', [
                'current_project_value' => 1200,
                'reason' => 'Approved variation order.',
                'acknowledgement' => true,
                'sync' => [
                    'invoices' => [613],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('invoice_breakdown', [
            'invoice_id' => 613,
            'item_description' => 'Project Value Variation',
            'description' => 'User-authored commercial line.',
            'unit_price' => 99,
            'subtotal' => 99,
            'system_adjustment_key' => null,
        ]);
        $this->assertDatabaseHas('invoice_breakdown', [
            'invoice_id' => 613,
            'item_description' => 'Project Value Variation',
            'description' => 'Project current value adjustment',
            'unit_price' => 101,
            'subtotal' => 101,
            'system_adjustment_key' => 'project_value_adjustment',
        ]);
    }

    public function test_paid_invoice_payment_adjustment_is_audit_only_and_does_not_change_paid_record(): void
    {
        DB::table('projects_main')->insert([
            'id' => 509,
            'project_name' => 'Paid Adjustment Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 605,
            'project_id' => 509,
            'invoice_ref_no' => 'INV-PAID-ADJ',
            'status' => 'Paid',
            'amount' => 1000,
            'sst_amount' => 0,
            'grand_total' => 1000,
            'paid_amount' => 1000,
            'paid_date' => '2026-06-01',
        ]);

        $this->actingSession()
            ->patchJson('/projects/509/value', [
                'current_project_value' => 1400,
                'reason' => 'Approved variation order.',
                'acknowledgement' => true,
                'sync' => [
                    'payment_adjustments' => [605],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.commercial_sync.applied.payment_adjustments.0.id', 605);

        $this->assertDatabaseHas('invoices', [
            'id' => 605,
            'grand_total' => 1000,
            'paid_amount' => 1000,
            'status' => 'Paid',
        ]);
        $this->assertDatabaseHas('project_value_revision_documents', [
            'project_id' => 509,
            'document_type' => 'payment',
            'document_id' => 605,
            'action' => 'adjustment_required',
            'old_amount' => 1000,
            'new_amount' => 1400,
        ]);
    }

    public function test_partially_paid_invoice_is_adjustment_only_even_without_payment_fields(): void
    {
        DB::table('projects_main')->insert([
            'id' => 520,
            'project_name' => 'Partially Paid Adjustment Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 615,
            'project_id' => 520,
            'invoice_ref_no' => 'INV-PARTIAL',
            'status' => 'Partially Paid',
            'amount' => 1000,
            'sst_amount' => 0,
            'grand_total' => 1000,
            'paid_amount' => null,
            'paid_date' => null,
        ]);

        $this->actingSession()
            ->postJson('/projects/520/value/impact-preview', [
                'current_project_value' => 1400,
                'reason' => 'Approved variation order.',
            ])
            ->assertOk()
            ->assertJsonPath('data.summary.editable_invoice_count', 0)
            ->assertJsonPath('data.summary.payment_record_count', 1)
            ->assertJsonPath('data.documents.invoices.0.classification', 'adjustment_required')
            ->assertJsonPath('data.documents.payment_adjustments.0.id', 615);

        $this->actingSession()
            ->patchJson('/projects/520/value', [
                'current_project_value' => 1400,
                'reason' => 'Approved variation order.',
                'acknowledgement' => true,
                'sync' => [
                    'invoices' => [615],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'commercial_sync_failed');

        $this->assertDatabaseHas('projects_main', [
            'id' => 520,
            'current_project_value' => null,
        ]);

        $this->actingSession()
            ->patchJson('/projects/520/value', [
                'current_project_value' => 1400,
                'reason' => 'Approved variation order.',
                'acknowledgement' => true,
                'sync' => [
                    'payment_adjustments' => [615],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.commercial_sync.applied.payment_adjustments.0.id', 615);

        $this->assertDatabaseHas('invoices', [
            'id' => 615,
            'grand_total' => 1000,
            'status' => 'Partially Paid',
        ]);
        $this->assertDatabaseHas('project_value_revision_documents', [
            'project_id' => 520,
            'document_type' => 'payment',
            'document_id' => 615,
            'action' => 'adjustment_required',
            'old_amount' => 1000,
            'new_amount' => 1400,
        ]);
    }

    public function test_repeated_invoice_sync_recalculates_adjustment_from_base_total(): void
    {
        DB::table('projects_main')->insert([
            'id' => 521,
            'project_name' => 'Repeated Sync Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => 1250,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 616,
            'project_id' => 521,
            'invoice_ref_no' => 'INV-REPEAT',
            'status' => 'Pending',
            'amount' => 1250,
            'sst_amount' => 0,
            'grand_total' => 1250,
        ]);
        DB::table('invoice_breakdown')->insert([
            [
                'invoice_id' => 616,
                'item_description' => 'Original Service',
                'description' => null,
                'quantity' => 1,
                'unit' => 'Lot',
                'unit_price' => 1000,
                'subtotal' => 1000,
                'sort_order' => 1,
                'system_adjustment_key' => null,
            ],
            [
                'invoice_id' => 616,
                'item_description' => 'Project Value Variation',
                'description' => 'Project current value adjustment',
                'quantity' => 1,
                'unit' => 'Lot',
                'unit_price' => 250,
                'subtotal' => 250,
                'sort_order' => 2,
                'system_adjustment_key' => 'project_value_adjustment',
            ],
        ]);

        $this->actingSession()
            ->patchJson('/projects/521/value', [
                'current_project_value' => 1500,
                'reason' => 'Second approved variation order.',
                'acknowledgement' => true,
                'sync' => [
                    'invoices' => [616],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.commercial_sync.applied.invoices.0.target_adjustment_amount', 500);

        $this->assertDatabaseHas('invoices', [
            'id' => 616,
            'amount' => 1500,
            'grand_total' => 1500,
        ]);
        $this->assertSame(1, DB::table('invoice_breakdown')
            ->where('invoice_id', 616)
            ->where('system_adjustment_key', 'project_value_adjustment')
            ->count());
        $this->assertDatabaseHas('invoice_breakdown', [
            'invoice_id' => 616,
            'item_description' => 'Project Value Variation',
            'unit_price' => 500,
            'subtotal' => 500,
            'system_adjustment_key' => 'project_value_adjustment',
        ]);
    }

    public function test_returning_project_value_to_base_removes_system_adjustment_rows(): void
    {
        DB::table('projects_main')->insert([
            'id' => 522,
            'project_name' => 'Return To Base Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => 800,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 617,
            'project_id' => 522,
            'invoice_ref_no' => 'INV-RETURN',
            'status' => 'Pending',
            'amount' => 800,
            'sst_amount' => 0,
            'grand_total' => 800,
        ]);
        DB::table('invoice_breakdown')->insert([
            [
                'invoice_id' => 617,
                'item_description' => 'Original Service',
                'description' => null,
                'quantity' => 1,
                'unit' => 'Lot',
                'unit_price' => 1000,
                'subtotal' => 1000,
                'sort_order' => 1,
                'system_adjustment_key' => null,
            ],
            [
                'invoice_id' => 617,
                'item_description' => 'Project Value Reduction',
                'description' => 'Project current value adjustment',
                'quantity' => 1,
                'unit' => 'Lot',
                'unit_price' => -200,
                'subtotal' => -200,
                'sort_order' => 2,
                'system_adjustment_key' => 'project_value_adjustment',
            ],
        ]);

        $this->actingSession()
            ->patchJson('/projects/522/value', [
                'current_project_value' => 1000,
                'reason' => 'Returned to awarded scope.',
                'acknowledgement' => true,
                'sync' => [
                    'invoices' => [617],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.commercial_sync.applied.invoices.0.target_adjustment_amount', 0);

        $this->assertDatabaseHas('invoices', [
            'id' => 617,
            'amount' => 1000,
            'grand_total' => 1000,
        ]);
        $this->assertDatabaseMissing('invoice_breakdown', [
            'invoice_id' => 617,
            'system_adjustment_key' => 'project_value_adjustment',
        ]);
    }

    public function test_non_paid_invoice_cannot_be_forced_into_payment_adjustment_sync(): void
    {
        DB::table('projects_main')->insert([
            'id' => 511,
            'project_name' => 'Blocked Adjustment Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 608,
            'project_id' => 511,
            'invoice_ref_no' => 'INV-VOID-FORCED',
            'status' => 'Void',
            'amount' => 1000,
            'sst_amount' => 0,
            'grand_total' => 1000,
        ]);

        $this->actingSession()
            ->patchJson('/projects/511/value', [
                'current_project_value' => 1400,
                'reason' => 'Approved variation order.',
                'acknowledgement' => true,
                'sync' => [
                    'payment_adjustments' => [608],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'commercial_sync_failed');

        $this->assertDatabaseHas('projects_main', [
            'id' => 511,
            'current_project_value' => null,
        ]);
        $this->assertDatabaseMissing('project_value_revisions', [
            'project_id' => 511,
        ]);

        $this->assertDatabaseMissing('project_value_revision_documents', [
            'project_id' => 511,
            'document_type' => 'payment',
            'document_id' => 608,
        ]);
    }

    public function test_terminal_paid_invoice_cannot_be_forced_into_payment_adjustment_sync(): void
    {
        DB::table('projects_main')->insert([
            'id' => 517,
            'project_name' => 'Terminal Paid Adjustment Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('invoices')->insert([
            'id' => 614,
            'project_id' => 517,
            'invoice_ref_no' => 'INV-VOID-PAID-FORCED',
            'status' => 'Void',
            'amount' => 1000,
            'sst_amount' => 0,
            'grand_total' => 1000,
            'paid_amount' => 1000,
            'paid_date' => '2026-06-15',
        ]);

        $this->actingSession()
            ->patchJson('/projects/517/value', [
                'current_project_value' => 1400,
                'reason' => 'Approved variation order.',
                'acknowledgement' => true,
                'sync' => [
                    'payment_adjustments' => [614],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'commercial_sync_failed');

        $this->assertDatabaseHas('projects_main', [
            'id' => 517,
            'current_project_value' => null,
        ]);
        $this->assertDatabaseMissing('project_value_revisions', [
            'project_id' => 517,
        ]);
        $this->assertDatabaseMissing('project_value_revision_documents', [
            'project_id' => 517,
            'document_type' => 'payment',
            'document_id' => 614,
        ]);
    }

    public function test_delivery_order_from_another_project_cannot_be_forced_into_sync_payload(): void
    {
        DB::table('projects_main')->insert([
            'id' => 518,
            'project_name' => 'Delivery Order Guard Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('projects_main')->insert([
            'id' => 519,
            'project_name' => 'Other Delivery Order Project',
            'project_type' => 'Training',
            'quote_value' => 1000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);
        DB::table('do_details')->insert([
            'id' => 702,
            'project_id' => 519,
            'do_number' => 'DO-OTHER',
        ]);

        $this->actingSession()
            ->patchJson('/projects/518/value', [
                'current_project_value' => 1400,
                'reason' => 'Approved variation order.',
                'acknowledgement' => true,
                'sync' => [
                    'delivery_orders' => [702],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'commercial_sync_failed');

        $this->assertDatabaseHas('projects_main', [
            'id' => 518,
            'current_project_value' => null,
        ]);
        $this->assertDatabaseMissing('project_value_revisions', [
            'project_id' => 518,
        ]);
    }

    public function test_project_details_update_does_not_overwrite_awarded_or_current_value(): void
    {
        DB::table('projects_main')->insert([
            'id' => 505,
            'project_name' => 'Editable Details Project',
            'project_type' => 'Training',
            'quote_id' => 88,
            'quote_value' => 1000,
            'current_project_value' => 1250,
            'award_date' => '2026-06-01',
            'status' => 'Active',
        ]);

        $this->actingSession()
            ->putJson('/projects/505', [
                'project_id' => 505,
                'project_name' => 'Renamed Details Project',
                'project_type' => 'Training',
                'quote_value' => 9999,
                'current_project_value' => 7777,
                'award_date' => '2026-06-02',
                'service_start_date' => null,
                'service_end_date' => null,
                'description' => 'Details only.',
                'po_loa_number' => 'PO-1',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('projects_main', [
            'id' => 505,
            'project_name' => 'Renamed Details Project',
            'quote_value' => 1000,
            'current_project_value' => 1250,
            'po_loa_number' => 'PO-1',
        ]);
    }

    public function test_awarded_quote_value_change_requires_decision_then_can_sync_project_current_value(): void
    {
        DB::table('projects_main')->insert([
            'id' => 502,
            'project_name' => 'Awarded Quote Project',
            'project_type' => 'Training',
            'quote_id' => 55,
            'quote_value' => 2000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);

        $quote = (object) ['status' => 'Awarded', 'grand_total' => 2000];
        $service = app(ProjectValueService::class);
        $decision = $service->handleAwardedQuoteValueDecision(
            $this->serviceRequest([]),
            'training',
            55,
            $quote,
            2300
        );

        $this->assertNotNull($decision);
        $this->assertSame(409, $decision->getStatusCode());

        $synced = $service->handleAwardedQuoteValueDecision(
            $this->serviceRequest([
                'project_value_sync_decision' => ProjectValueService::DECISION_SYNC,
                'project_value_sync_reason' => 'Client accepted revised quote.',
            ]),
            'training',
            55,
            $quote,
            2300
        );

        $this->assertNull($synced);
        $this->assertDatabaseHas('projects_main', [
            'id' => 502,
            'quote_value' => 2000,
            'current_project_value' => 2300,
        ]);
        $this->assertDatabaseHas('project_value_revisions', [
            'project_id' => 502,
            'quote_id' => 55,
            'quote_type' => 'training',
            'source' => ProjectValueService::SOURCE_AWARDED_QUOTE_EDIT,
            'old_value' => 2000,
            'new_value' => 2300,
            'reason' => 'Client accepted revised quote.',
        ]);
    }

    public function test_awarded_quote_value_decision_matches_legacy_project_type_aliases(): void
    {
        DB::table('projects_main')->insert([
            'id' => 503,
            'project_name' => 'Legacy Manpower Project',
            'project_type' => 'MAN POWER',
            'quote_type' => null,
            'quote_id' => 66,
            'quote_value' => 3000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);

        $quote = (object) ['status' => 'Awarded', 'grand_total' => 3000];
        $decision = app(ProjectValueService::class)->handleAwardedQuoteValueDecision(
            $this->serviceRequest([]),
            'manpower',
            66,
            $quote,
            3300
        );

        $this->assertNotNull($decision);
        $this->assertSame(409, $decision->getStatusCode());
    }

    public function test_award_modal_adjustment_sets_current_value_and_writes_history(): void
    {
        DB::table('projects_main')->insert([
            'id' => 504,
            'project_name' => 'Award Modal Variation',
            'project_type' => 'Industrial Hygiene',
            'quote_type' => 'ih',
            'quote_id' => 77,
            'quote_value' => 4000,
            'current_project_value' => null,
            'status' => 'Active',
        ]);

        app(ProjectValueService::class)->applyAwardModalAdjustment(
            504,
            $this->serviceRequest([
                'project_value_decision' => ProjectValueService::DECISION_ADJUSTED,
                'current_project_value' => 4500,
                'project_value_reason' => 'Client awarded with approved variation.',
            ]),
            'ih',
            77
        );

        $this->assertDatabaseHas('projects_main', [
            'id' => 504,
            'quote_value' => 4000,
            'current_project_value' => 4500,
        ]);
        $this->assertDatabaseHas('project_value_revisions', [
            'project_id' => 504,
            'quote_id' => 77,
            'quote_type' => 'ih',
            'source' => ProjectValueService::SOURCE_AWARD_MODAL,
            'old_value' => 4000,
            'new_value' => 4500,
            'reason' => 'Client awarded with approved variation.',
        ]);
    }

    private function createTables(): void
    {
        foreach ([
            'user_activities',
            'project_value_revision_documents',
            'project_value_revisions',
            'invoice_breakdown',
            'invoices_jd14form',
            'do_details',
            'project_progress',
            'invoices',
            'projects_main',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('client_id')->nullable();
            $table->integer('quote_id')->nullable();
            $table->string('quote_type')->nullable();
            $table->string('project_name')->nullable();
            $table->string('project_type')->nullable();
            $table->string('po_loa_number')->nullable();
            $table->decimal('quote_value', 15, 2)->nullable();
            $table->decimal('current_project_value', 15, 2)->nullable();
            $table->date('award_date')->nullable();
            $table->date('service_start_date')->nullable();
            $table->date('service_end_date')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by')->nullable();
        });

        Schema::create('project_value_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->string('quote_type', 50)->nullable();
            $table->string('source', 50);
            $table->decimal('old_value', 15, 2)->nullable();
            $table->decimal('new_value', 15, 2);
            $table->decimal('awarded_value', 15, 2)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('project_value_revision_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_value_revision_id');
            $table->unsignedBigInteger('project_id');
            $table->string('document_type', 50);
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('document_ref')->nullable();
            $table->string('action', 80);
            $table->decimal('old_amount', 15, 2)->nullable();
            $table->decimal('new_amount', 15, 2)->nullable();
            $table->string('status_before')->nullable();
            $table->string('status_after')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();
        });

        Schema::create('project_progress', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->date('progress_date');
            $table->text('progress_text');
            $table->integer('updated_by')->nullable();
            $table->timestamp('updated_on')->nullable();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id')->nullable();
            $table->string('invoice_ref_no')->nullable();
            $table->string('status')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('sst_amount', 15, 2)->nullable();
            $table->decimal('grand_total', 15, 2)->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->date('paid_date')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('invoice_breakdown', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('invoice_id');
            $table->string('item_description')->nullable();
            $table->text('description')->nullable();
            $table->string('system_adjustment_key')->nullable();
            $table->string('system_adjustment_source')->nullable();
            $table->decimal('quantity', 15, 2)->nullable();
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('subtotal', 15, 2)->nullable();
            $table->integer('sort_order')->nullable();
        });

        Schema::create('do_details', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id')->nullable();
            $table->string('do_number')->nullable();
        });

        Schema::create('invoices_jd14form', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id')->nullable();
            $table->string('approval_no')->nullable();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function actingSession()
    {
        $this->app['session']->start();
        $this->app['session']->put([
            'user_id' => 1,
            'staff_id' => 10,
            'name_code' => 'EMP',
            '_token' => 'test-token',
        ]);

        return $this
            ->withSession([
                'user_id' => 1,
                'staff_id' => 10,
                'name_code' => 'EMP',
                '_token' => 'test-token',
            ])
            ->withCookie(config('session.cookie'), $this->app['session']->getId())
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }

    private function serviceRequest(array $payload): Request
    {
        $session = $this->app['session.store'];
        $session->start();
        $session->put([
            'user_id' => 1,
            'staff_id' => 10,
            'name_code' => 'EMP',
        ]);

        $request = Request::create('/', 'PUT', $payload);
        $request->setLaravelSession($session);

        return $request;
    }
}
