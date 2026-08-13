<?php

namespace Tests\Feature;

use App\Jobs\SendHtmlMailJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VendorPaymentWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.frontend_url' => 'https://kijo.amiosh.com',
            'app.url' => 'https://api.amiosh.com',
            'mail.default' => 'array',
            'mail.from.address' => 'kijo@work.amiosh.com',
            'mail.from.name' => 'Kijo Alert',
        ]);
        Storage::fake('private');

        foreach ([
            'user_activities',
            'in_app_notifications',
            'staff_general',
            'system_users',
            'workflow_step_recipients',
            'workflow_template_steps',
            'workflow_templates',
            'vendor_payment_workflow_recipients',
            'vendor_payment_workflow_settings',
            'vendor_payment_events',
            'vendor_payment_transactions',
            'projects_main',
            'vendor_main_details',
            'vendor_payments',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email')->nullable();
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->unsignedBigInteger('staff_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('Active');
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('vendor_main_details', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('vendor_id');
            $table->string('vendor_name')->nullable();
        });

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('project_name')->nullable();
        });

        Schema::create('vendor_payments', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('vendor_id');
            $table->unsignedInteger('project_id')->nullable();
            $table->string('payment_context')->nullable();
            $table->string('remarks')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('method')->nullable();
            $table->string('status')->default('Pending');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('date_approved')->nullable();
            $table->string('payment_type')->nullable();
            $table->string('receipt_path')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->string('created_by_full_name')->nullable();
            $table->string('created_by_name_code')->nullable();
            $table->unsignedInteger('checked_by')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->text('checker_remarks')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->text('approval_remarks')->nullable();
            $table->unsignedInteger('returned_by')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('returned_remarks')->nullable();
            $table->unsignedInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejected_remarks')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->unsignedInteger('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('paid_remarks')->nullable();
            $table->unsignedTinyInteger('current_review_level')->nullable();
            $table->unsignedTinyInteger('current_approval_level')->nullable();
            $table->json('workflow_progress_json')->nullable();
            $table->json('workflow_settings_snapshot_json')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->unsignedBigInteger('parent_payment_id')->nullable();
            $table->unsignedInteger('revision_number')->default(1);
            $table->unsignedBigInteger('superseded_by_payment_id')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('vendor_name_snapshot')->nullable();
            $table->string('receipt_original_name')->nullable();
            $table->string('receipt_mime_type')->nullable();
            $table->unsignedBigInteger('receipt_size')->nullable();
            $table->string('receipt_sha256', 64)->nullable();
            $table->string('receipt_state')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedInteger('deleted_by')->nullable();
        });

        Schema::create('vendor_payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vendor_payment_id');
            $table->decimal('amount', 14, 2);
            $table->date('paid_date');
            $table->string('method');
            $table->string('reference_number');
            $table->string('bank_name_snapshot')->nullable();
            $table->string('bank_account_snapshot')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->string('created_by_name_code')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_payment_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vendor_payment_id');
            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->unsignedBigInteger('actor_staff_id')->nullable();
            $table->string('actor_name_code')->nullable();
            $table->text('remarks')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('vendor_payment_workflow_settings', function (Blueprint $table): void {
            $table->string('setting_key')->primary();
            $table->text('setting_value')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_payment_workflow_recipients', function (Blueprint $table): void {
            $table->id();
            $table->string('stage_type', 20);
            $table->unsignedTinyInteger('level_no')->default(1);
            $table->unsignedBigInteger('staff_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('process_key', 120)->unique();
            $table->string('label');
            $table->string('module_key', 80);
            $table->string('route_pattern', 191)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('workflow_template_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('workflow_templates')->cascadeOnDelete();
            $table->string('step_key', 120);
            $table->unsignedInteger('level_no')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('label');
            $table->string('action_label', 80);
            $table->json('fallback_roles')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['template_id', 'step_key', 'level_no'], 'workflow_steps_template_key_level_unique');
        });

        Schema::create('workflow_step_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('step_id')->constrained('workflow_template_steps')->cascadeOnDelete();
            $table->unsignedBigInteger('staff_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['step_id', 'staff_id']);
            $table->index('staff_id');
        });

        Schema::create('in_app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('recipient_staff_id');
            $table->unsignedBigInteger('actor_staff_id')->nullable();
            $table->string('module_key');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('type');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('route')->nullable();
            $table->string('severity')->default('info');
            $table->json('metadata_json')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        DB::table('system_users')->insert([
            ['id' => 1, 'staff_id' => 10, 'email' => 'requester@example.test', 'role' => json_encode(['Staff']), 'is_active' => 1],
            ['id' => 2, 'staff_id' => 20, 'email' => 'checker@example.test', 'role' => json_encode(['Manager']), 'is_active' => 1],
            ['id' => 3, 'staff_id' => 30, 'email' => 'approver@example.test', 'role' => json_encode(['System Admin']), 'is_active' => 1],
            ['id' => 4, 'staff_id' => 40, 'email' => 'finance@example.test', 'role' => json_encode(['Finance']), 'is_active' => 1],
            ['id' => 5, 'staff_id' => 50, 'email' => 'reviewer@example.test', 'role' => json_encode(['Staff']), 'is_active' => 1],
            ['id' => 6, 'staff_id' => 60, 'email' => 'approver2@example.test', 'role' => json_encode(['Staff']), 'is_active' => 1],
        ]);

        DB::table('staff_general')->insert([
            ['staff_id' => 10, 'full_name' => 'Request User', 'name_code' => 'REQ', 'email' => 'requester@example.test', 'status' => 'Active'],
            ['staff_id' => 20, 'full_name' => 'Check User', 'name_code' => 'CHK', 'email' => 'checker@example.test', 'status' => 'Active'],
            ['staff_id' => 30, 'full_name' => 'Approve User', 'name_code' => 'APP', 'email' => 'approver@example.test', 'status' => 'Active'],
            ['staff_id' => 40, 'full_name' => 'Finance User', 'name_code' => 'FIN', 'email' => 'finance@example.test', 'status' => 'Active'],
            ['staff_id' => 50, 'full_name' => 'Review User', 'name_code' => 'REV', 'email' => 'reviewer@example.test', 'status' => 'Active'],
            ['staff_id' => 60, 'full_name' => 'Approval Two', 'name_code' => 'AP2', 'email' => 'approver2@example.test', 'status' => 'Active'],
        ]);

        DB::table('vendor_main_details')->insert(['vendor_id' => 7, 'vendor_name' => 'Vendor A']);
        DB::table('projects_main')->insert(['id' => 501, 'project_name' => 'Project A']);
    }

    public function test_configured_requester_cannot_review_or_approve_own_request(): void
    {
        $this->actingSession(30, ['System Admin'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'review', 'level_no' => 1, 'recipient_staff_ids' => [20]],
                    ['stage_type' => 'approval', 'level_no' => 1, 'recipient_staff_ids' => [20]],
                ],
            ]))
            ->assertOk();

        $paymentId = $this->insertPayment(['created_by' => 20]);

        $this->actingSession(20, ['Manager'])
            ->patchJson("/vendor-payments/{$paymentId}/check")
            ->assertForbidden();

        $this->actingSession(20, ['Manager'])
            ->getJson('/vendor-payments')
            ->assertOk()
            ->assertJsonPath('history.0.id', $paymentId)
            ->assertJsonPath('history.0.can_check', false)
            ->assertJsonPath('history.0.can_approve', false);

        $this->actingSession(20, ['Manager'])
            ->patchJson("/vendor-payments/{$paymentId}/approve")
            ->assertStatus(409);

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Pending',
        ]);
    }

    public function test_payment_moves_pending_checked_approved_paid(): void
    {
        $paymentId = $this->insertPayment();

        $this->actingSession(20, ['Manager'])
            ->patchJson("/vendor-payments/{$paymentId}/check", ['remarks' => 'Verified'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Checked',
            'checked_by' => 20,
            'checker_remarks' => 'Verified',
        ]);

        $this->actingSession(30, ['System Admin'])
            ->getJson('/vendor-payments')
            ->assertOk()
            ->assertJsonPath('history.0.workflow_progress.0.label', 'Review')
            ->assertJsonPath('history.0.workflow_progress.0.status', 'Reviewed')
            ->assertJsonPath('history.0.workflow_progress.0.actorName', 'Check User')
            ->assertJsonPath('history.0.workflow_progress.0.actorCode', 'CHK')
            ->assertJsonPath('history.0.workflow_progress.0.remarks', 'Verified');

        $this->actingSession(30, ['System Admin'])
            ->patchJson("/vendor-payments/{$paymentId}/approve", ['remarks' => 'Approved independently'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Approved',
            'approved_by' => 30,
            'approval_remarks' => 'Approved independently',
        ]);

        $this->actingSession(40, ['Finance'])
            ->patchJson("/vendor-payments/{$paymentId}/mark-paid", [
                'paid_date' => '2026-05-28',
                'paid_amount' => 125,
                'remarks' => 'Bank transfer',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Paid',
            'paid_date' => '2026-05-28',
            'paid_by' => 40,
            'paid_remarks' => 'Bank transfer',
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'module_key' => 'vendor.payments',
            'entity_id' => $paymentId,
            'type' => 'vendor_payment_paid',
            'recipient_staff_id' => 10,
        ]);

        $this->actingSession(10, ['Staff'])
            ->getJson('/vendor-payments')
            ->assertOk()
            ->assertJsonPath('history.0.id', $paymentId)
            ->assertJsonPath('history.0.workflow_flow.currentStage', null)
            ->assertJsonPath('history.0.workflow_flow.stages.0.status', 'Reviewed')
            ->assertJsonPath('history.0.workflow_flow.stages.1.status', 'Approved')
            ->assertJsonPath('history.0.workflow_flow.stages.2.status', 'Paid')
            ->assertJsonPath('history.0.requested_by_actor.display', 'Request User (REQ)')
            ->assertJsonPath('history.0.reviewed_by_actor.display', 'Check User (CHK)')
            ->assertJsonPath('history.0.approved_by_actor.display', 'Approve User (APP)')
            ->assertJsonPath('history.0.paid_by_actor.display', 'Finance User (FIN)');

        $this->actingSession(10, ['Staff'])
            ->getJson("/vendor-payments/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('data.reviewed_by_actor.staff_id', 20)
            ->assertJsonPath('data.reviewed_by_actor.full_name', 'Check User')
            ->assertJsonPath('data.paid_by_actor.staff_id', 40)
            ->assertJsonPath('data.paid_by_actor.name_code', 'FIN');
    }

    public function test_invalid_transitions_and_approved_delete_are_rejected(): void
    {
        $paymentId = $this->insertPayment();

        $this->actingSession(30, ['System Admin'])
            ->patchJson("/vendor-payments/{$paymentId}/approve")
            ->assertStatus(409);

        DB::table('vendor_payments')->where('id', $paymentId)->update(['status' => 'Approved']);

        $this->actingSession(20, ['Manager'])
            ->deleteJson("/vendor-payments/{$paymentId}")
            ->assertForbidden();

        $this->assertNull(DB::table('vendor_payments')->where('id', $paymentId)->value('deleted_at'));

        DB::table('vendor_payments')->where('id', $paymentId)->update(['status' => 'Paid']);

        $this->actingSession(20, ['Manager'])
            ->deleteJson("/vendor-payments/{$paymentId}")
            ->assertForbidden();
    }

    public function test_workflow_creates_and_resolves_notifications(): void
    {
        $paymentId = $this->insertPayment();

        $this->actingSession(20, ['Manager'])
            ->patchJson("/vendor-payments/{$paymentId}/check")
            ->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'module_key' => 'vendor.payments',
            'entity_type' => 'vendor_payment',
            'entity_id' => $paymentId,
            'type' => 'vendor_payment_checked',
            'recipient_staff_id' => 30,
        ]);

        $this->actingSession(30, ['System Admin'])
            ->patchJson("/vendor-payments/{$paymentId}/approve")
            ->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'module_key' => 'vendor.payments',
            'entity_id' => $paymentId,
            'type' => 'vendor_payment_finance_requested',
            'recipient_staff_id' => 40,
        ]);
    }

    public function test_submit_return_and_reject_notifications_are_recorded(): void
    {
        $submitResponse = $this->submitPayment(10, [
            'vendor_id' => 7,
            'payment_context' => 'Office',
            'payment_type' => 'Deposit',
            'amount' => 125,
            'method' => 'Online Transfer',
            'remarks' => 'Office setup',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $submittedPaymentId = (int) $submitResponse->json('id');
        $this->assertDatabaseHas('in_app_notifications', [
            'module_key' => 'vendor.payments',
            'entity_id' => $submittedPaymentId,
            'type' => 'vendor_payment_submitted',
            'recipient_staff_id' => 20,
        ]);

        $this->actingSession(20, ['Manager'])
            ->patchJson("/vendor-payments/{$submittedPaymentId}/return", ['remarks' => 'Need invoice'])
            ->assertOk();

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $submittedPaymentId,
            'status' => 'Returned',
            'returned_by' => 20,
            'returned_remarks' => 'Need invoice',
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'module_key' => 'vendor.payments',
            'entity_id' => $submittedPaymentId,
            'type' => 'vendor_payment_returned',
            'recipient_staff_id' => 10,
        ]);

        $rejectPaymentId = $this->insertPayment(['status' => 'Checked', 'checked_by' => 20]);
        $this->actingSession(30, ['System Admin'])
            ->patchJson("/vendor-payments/{$rejectPaymentId}/reject", ['remarks' => 'Duplicate'])
            ->assertOk();

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $rejectPaymentId,
            'status' => 'Rejected',
            'rejected_by' => 30,
            'rejected_remarks' => 'Duplicate',
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'module_key' => 'vendor.payments',
            'entity_id' => $rejectPaymentId,
            'type' => 'vendor_payment_rejected',
            'recipient_staff_id' => 10,
        ]);
    }

    public function test_legacy_payment_submission_without_idempotency_key_remains_compatible(): void
    {
        $response = $this->submitPayment(10, ['idempotency_key' => null])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('idempotent', false);

        $key = DB::table('vendor_payments')
            ->where('id', (int) $response->json('id'))
            ->value('idempotency_key');

        $this->assertNotEmpty($key);
    }

    public function test_current_payment_submission_keeps_client_idempotency_across_retries(): void
    {
        $key = 'stable-client-key';
        $first = $this->submitPayment(10, ['idempotency_key' => $key])
            ->assertOk()
            ->assertJsonPath('idempotent', false);
        $second = $this->submitPayment(10, ['idempotency_key' => $key])
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        $this->assertSame((int) $first->json('id'), (int) $second->json('id'));
        $this->assertSame(1, DB::table('vendor_payments')->where('idempotency_key', $key)->count());
    }

    public function test_paid_by_vendor_endpoint_returns_only_paid_rows(): void
    {
        $this->insertPayment(['status' => 'Paid', 'paid_date' => '2026-05-01', 'paid_amount' => 100]);
        $this->insertPayment(['status' => 'Approved', 'amount' => 999]);

        $this->actingSession(20, ['Manager'])
            ->getJson('/vendor-payments/paid-by-vendor')
            ->assertOk()
            ->assertJsonPath('data.0.vendor_id', 7)
            ->assertJsonPath('data.0.paid_count', 1);
    }

    public function test_workflow_settings_can_configure_non_manager_reviewers(): void
    {
        $this->actingSession(20, ['Manager'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => true,
                'review_levels' => 2,
                'approval_enabled' => false,
                'approval_levels' => 0,
                'stages' => [
                    ['stage_type' => 'review', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                    ['stage_type' => 'review', 'level_no' => 2, 'recipient_staff_ids' => [60]],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('template.settings.review_levels', 2)
            ->assertJsonPath('template.steps.0.recipients.0.staff_id', 50);

        $paymentId = $this->insertPayment(['current_review_level' => 1]);

        $this->actingSession(50, ['Staff'])
            ->patchJson("/vendor-payments/{$paymentId}/check", ['remarks' => 'L1 ok'])
            ->assertOk();

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Pending',
            'current_review_level' => 2,
            'checked_by' => 50,
        ]);

        $this->actingSession(60, ['Staff'])
            ->patchJson("/vendor-payments/{$paymentId}/check", ['remarks' => 'L2 ok'])
            ->assertOk();

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Approved',
            'approved_by' => 60,
            'checked_by' => 60,
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'module_key' => 'vendor.payments',
            'entity_id' => $paymentId,
            'type' => 'vendor_payment_finance_requested',
            'recipient_staff_id' => 40,
        ]);
    }

    public function test_workflow_settings_can_be_read_by_staff_but_saved_only_by_manager_or_system_admin(): void
    {
        $this->actingSession(60, ['Staff'])
            ->getJson('/workflows/templates/vendor-payment')
            ->assertOk()
            ->assertJsonPath('can_edit', false);

        $this->actingSession(60, ['Staff'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => false,
                'review_levels' => 0,
                'approval_enabled' => false,
                'approval_levels' => 0,
                'stages' => [],
            ]))
            ->assertStatus(403);

        $this->actingSession(30, ['System Admin'])
            ->getJson('/workflows/templates/vendor-payment')
            ->assertOk()
            ->assertJsonPath('can_edit', true);
    }

    public function test_approval_only_workflow_starts_checked_and_uses_configured_approver(): void
    {
        $this->actingSession(20, ['Manager'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => false,
                'review_levels' => 0,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'approval', 'level_no' => 1, 'recipient_staff_ids' => [60]],
                ],
            ]))
            ->assertOk();

        $submitResponse = $this->submitPayment(10, [
            'vendor_id' => 7,
            'payment_context' => 'Office',
            'payment_type' => 'Deposit',
            'amount' => 125,
            'method' => 'Online Transfer',
            'remarks' => 'Approval only',
        ])
            ->assertOk();

        $paymentId = (int) $submitResponse->json('id');
        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Checked',
            'current_approval_level' => 1,
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'module_key' => 'vendor.payments',
            'entity_id' => $paymentId,
            'type' => 'vendor_payment_checked',
            'recipient_staff_id' => 60,
        ]);

        $this->actingSession(60, ['Staff'])
            ->patchJson("/vendor-payments/{$paymentId}/approve", ['remarks' => 'Approved'])
            ->assertOk();

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Approved',
            'approved_by' => 60,
            'approval_remarks' => 'Approved',
        ]);
    }

    public function test_final_approval_notifies_configured_finance_recipient(): void
    {
        Bus::fake([SendHtmlMailJob::class]);

        $this->actingSession(20, ['Manager'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'review', 'level_no' => 1, 'recipient_staff_ids' => [20]],
                    ['stage_type' => 'approval', 'level_no' => 1, 'recipient_staff_ids' => [30]],
                    ['stage_type' => 'finance', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                ],
            ]))
            ->assertOk();

        $paymentId = $this->insertPayment();

        $this->actingSession(20, ['Manager'])
            ->patchJson("/vendor-payments/{$paymentId}/check")
            ->assertOk();

        $this->actingSession(30, ['System Admin'])
            ->patchJson("/vendor-payments/{$paymentId}/approve")
            ->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'module_key' => 'vendor.payments',
            'entity_id' => $paymentId,
            'type' => 'vendor_payment_finance_requested',
            'recipient_staff_id' => 50,
        ]);

        Bus::assertDispatchedSync(SendHtmlMailJob::class, function (SendHtmlMailJob $job): bool {
            $body = (string) $this->jobProperty($job, 'body');

            return $this->jobProperty($job, 'to') === 'reviewer@example.test'
                && $this->jobProperty($job, 'fromAddress') === 'kijo@work.amiosh.com'
                && $this->jobProperty($job, 'fromName') === 'Kijo Alert'
                && str_contains($body, 'href="https://kijo.amiosh.com/vendor/payment-records/')
                && ! str_contains($body, 'https://api.amiosh.com');
        });
    }

    public function test_finance_only_workflow_starts_approved_and_notifies_finance(): void
    {
        $this->actingSession(20, ['Manager'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => false,
                'review_levels' => 0,
                'approval_enabled' => false,
                'approval_levels' => 0,
                'stages' => [
                    ['stage_type' => 'finance', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                ],
            ]))
            ->assertOk();

        $submitResponse = $this->submitPayment(10, [
            'vendor_id' => 7,
            'payment_context' => 'Office',
            'payment_type' => 'Deposit',
            'amount' => 125,
            'method' => 'Online Transfer',
            'remarks' => 'Finance only',
        ])
            ->assertOk();

        $paymentId = (int) $submitResponse->json('id');

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Approved',
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'module_key' => 'vendor.payments',
            'entity_id' => $paymentId,
            'type' => 'vendor_payment_finance_requested',
            'recipient_staff_id' => 50,
        ]);
    }

    public function test_configured_finance_recipient_can_mark_paid_without_finance_role(): void
    {
        $this->actingSession(20, ['Manager'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'finance', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('template.steps.2.stepKey', 'finance')
            ->assertJsonPath('template.steps.2.recipients.0.staff_id', 50);

        $paymentId = $this->insertPayment(['status' => 'Approved']);

        $this->actingSession(60, ['Staff'])
            ->patchJson("/vendor-payments/{$paymentId}/mark-paid", [
                'paid_date' => '2026-05-28',
                'paid_amount' => 125,
            ])
            ->assertStatus(403);

        $this->actingSession(50, ['Staff'])
            ->patchJson("/vendor-payments/{$paymentId}/mark-paid", [
                'paid_date' => '2026-05-28',
                'paid_amount' => 125,
                'remarks' => 'Finance entry',
            ])
            ->assertOk();

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Paid',
            'paid_by' => 50,
            'paid_remarks' => 'Finance entry',
        ]);
    }

    public function test_inactive_configured_finance_recipient_does_not_block_fallback_finance(): void
    {
        $this->actingSession(20, ['Manager'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'finance', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                ],
            ]))
            ->assertOk();

        DB::table('staff_general')->where('staff_id', 50)->update(['status' => 'Inactive']);
        DB::table('system_users')->where('staff_id', 50)->update(['is_active' => 0]);

        $paymentId = $this->insertPayment(['status' => 'Approved']);

        $this->actingSession(40, ['Finance'])
            ->patchJson("/vendor-payments/{$paymentId}/mark-paid", [
                'paid_date' => '2026-05-28',
                'paid_amount' => 125,
            ])
            ->assertOk();

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Paid',
            'paid_by' => 40,
        ]);
    }

    public function test_mark_paid_fallback_role_check_requires_exact_role(): void
    {
        $paymentId = $this->insertPayment(['status' => 'Approved']);

        $this->actingSession(60, ['Finance Viewer'])
            ->patchJson("/vendor-payments/{$paymentId}/mark-paid", [
                'paid_date' => '2026-05-28',
                'paid_amount' => 125,
            ])
            ->assertStatus(403);
    }

    public function test_central_vendor_workflow_normalizes_finance_stage_to_level_one(): void
    {
        $this->actingSession(20, ['Manager'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'finance', 'level_no' => 2, 'recipient_staff_ids' => [50]],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('template.steps.2.stepKey', 'finance')
            ->assertJsonPath('template.steps.2.levelNo', 1);
    }

    public function test_payment_queue_returns_mark_paid_permission_for_configured_finance(): void
    {
        $this->actingSession(20, ['Manager'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'finance', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                ],
            ]))
            ->assertOk();

        $this->insertPayment(['status' => 'Approved']);

        $this->actingSession(50, ['Staff'])
            ->getJson('/vendor-payments')
            ->assertOk()
            ->assertJsonPath('history.0.can_mark_paid', true);

        $this->actingSession(60, ['Staff'])
            ->getJson('/vendor-payments')
            ->assertOk()
            ->assertJsonPath('history.0.can_mark_paid', false);
    }

    public function test_payment_flow_and_authorization_remain_bound_to_submission_snapshot(): void
    {
        $this->actingSession(30, ['System Admin'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'review', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                    ['stage_type' => 'approval', 'level_no' => 1, 'recipient_staff_ids' => [60]],
                    ['stage_type' => 'finance', 'level_no' => 1, 'recipient_staff_ids' => [40]],
                ],
            ]))
            ->assertOk();

        $paymentId = (int) $this->submitPayment(10, [
            'vendor_id' => 7,
            'payment_context' => 'Snapshot flow',
            'payment_type' => 'Deposit',
            'amount' => 125,
            'method' => 'Online Transfer',
        ])
            ->assertOk()
            ->json('id');

        $this->actingSession(30, ['System Admin'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'review', 'level_no' => 1, 'recipient_staff_ids' => [20]],
                    ['stage_type' => 'approval', 'level_no' => 1, 'recipient_staff_ids' => [30]],
                    ['stage_type' => 'finance', 'level_no' => 1, 'recipient_staff_ids' => [30]],
                ],
            ]))
            ->assertOk();

        $this->actingSession(10, ['Staff'])
            ->getJson('/vendor-payments')
            ->assertOk()
            ->assertJsonPath('history.0.id', $paymentId)
            ->assertJsonPath('history.0.workflow_flow.currentStage.label', 'Review')
            ->assertJsonPath('history.0.workflow_flow.stages.0.state', 'current')
            ->assertJsonPath('history.0.workflow_flow.stages.0.recipients.0.staffId', 50)
            ->assertJsonPath('history.0.workflow_flow.stages.1.state', 'waiting')
            ->assertJsonPath('history.0.workflow_flow.stages.1.recipients.0.staffId', 60)
            ->assertJsonPath('history.0.workflow_flow.stages.2.state', 'waiting')
            ->assertJsonPath('history.0.can_check', false);

        $this->actingSession(20, ['Manager'])
            ->patchJson("/vendor-payments/{$paymentId}/check")
            ->assertStatus(403);

        $this->actingSession(50, ['Staff'])
            ->patchJson("/vendor-payments/{$paymentId}/check", ['remarks' => 'Snapshot reviewer'])
            ->assertOk();

        $this->actingSession(60, ['Staff'])
            ->patchJson("/vendor-payments/{$paymentId}/approve", ['remarks' => 'Snapshot approver'])
            ->assertOk();
    }

    public function test_legacy_payment_without_snapshot_uses_current_workflow(): void
    {
        $this->actingSession(30, ['System Admin'])
            ->putJson('/workflows/templates/vendor-payment', $this->vendorWorkflowPayload([
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'review', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                    ['stage_type' => 'approval', 'level_no' => 1, 'recipient_staff_ids' => [60]],
                ],
            ]))
            ->assertOk();

        $paymentId = $this->insertPayment(['workflow_settings_snapshot_json' => null]);

        $this->actingSession(50, ['Staff'])
            ->getJson('/vendor-payments')
            ->assertOk()
            ->assertJsonPath('history.0.id', $paymentId)
            ->assertJsonPath('history.0.can_check', true)
            ->assertJsonPath('history.0.workflow_flow.stages.0.recipients.0.staffId', 50)
            ->assertJsonPath('history.0.workflow_flow.stages.0.state', 'current');
    }

    public function test_returned_payment_flow_is_terminal_and_has_no_stage_actions(): void
    {
        $paymentId = $this->insertPayment([
            'status' => 'Returned',
            'returned_by' => 20,
            'returned_at' => now(),
            'returned_remarks' => 'Please correct the invoice.',
        ]);

        $this->actingSession(20, ['Manager'])
            ->getJson('/vendor-payments')
            ->assertOk()
            ->assertJsonPath('history.0.id', $paymentId)
            ->assertJsonPath('history.0.workflow_flow.currentStage', null)
            ->assertJsonPath('history.0.workflow_flow.stages.0.state', 'returned')
            ->assertJsonPath('history.0.workflow_flow.stages.0.remarks', 'Please correct the invoice.')
            ->assertJsonPath('history.0.can_check', false)
            ->assertJsonPath('history.0.can_approve', false)
            ->assertJsonPath('history.0.can_return', false)
            ->assertJsonPath('history.0.can_reject', false);
    }

    public function test_legacy_returned_payment_after_review_shows_approval_as_terminal_stage(): void
    {
        $paymentId = $this->insertPayment([
            'status' => 'Returned',
            'checked_by' => 20,
            'checked_at' => now()->subMinute(),
            'checker_remarks' => 'Review complete.',
            'returned_by' => 30,
            'returned_at' => now(),
            'returned_remarks' => 'Approval needs more information.',
            'workflow_progress_json' => null,
            'workflow_settings_snapshot_json' => null,
        ]);

        $this->actingSession(10, ['Staff'])
            ->getJson('/vendor-payments')
            ->assertOk()
            ->assertJsonPath('history.0.id', $paymentId)
            ->assertJsonPath('history.0.workflow_flow.currentStage', null)
            ->assertJsonPath('history.0.workflow_flow.stages.0.state', 'completed')
            ->assertJsonPath('history.0.workflow_flow.stages.0.status', 'Reviewed')
            ->assertJsonPath('history.0.workflow_flow.stages.1.state', 'returned')
            ->assertJsonPath('history.0.workflow_flow.stages.1.status', 'Returned')
            ->assertJsonPath('history.0.workflow_flow.stages.1.actor.staffId', 30)
            ->assertJsonPath('history.0.workflow_flow.stages.1.remarks', 'Approval needs more information.');
    }

    public function test_authenticated_staff_can_view_payment_and_its_verified_invoice(): void
    {
        $response = $this->submitPayment(10)->assertOk();
        $paymentId = (int) $response->json('id');

        $this->actingSession(50, ['Staff'])
            ->getJson("/vendor-payments/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('data.id', $paymentId)
            ->assertJsonPath('data.permissions.can_view', true)
            ->assertJsonPath('data.receipt_state', 'available')
            ->assertJsonPath('data.receipt_url', "/vendor-payments/{$paymentId}/invoice");

        $this->actingSession(50, ['Staff'])
            ->get("/vendor-payments/{$paymentId}/invoice")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');

        $cacheControl = $this->actingSession(50, ['Staff'])
            ->get("/vendor-payments/{$paymentId}/invoice")
            ->headers->get('cache-control');
        $this->assertStringContainsString('private', (string) $cacheControl);
        $this->assertStringContainsString('no-store', (string) $cacheControl);

        $stored = DB::table('vendor_payments')->where('id', $paymentId)->first();
        $this->assertSame(64, strlen((string) $stored->receipt_sha256));
        $this->assertSame('invoice.pdf', $stored->receipt_original_name);
    }

    public function test_invoice_integrity_mismatch_is_rejected_instead_of_serving_corrupt_bytes(): void
    {
        $response = $this->submitPayment(10)->assertOk();
        $paymentId = (int) $response->json('id');
        DB::table('vendor_payments')->where('id', $paymentId)->update([
            'receipt_sha256' => str_repeat('0', 64),
        ]);

        $this->actingSession(30, ['System Admin'])
            ->getJson("/vendor-payments/{$paymentId}/invoice")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Invoice attachment failed integrity verification.');
    }

    public function test_creator_can_cancel_untouched_request_but_another_staff_member_cannot(): void
    {
        $paymentId = $this->insertPayment(['version' => 1]);

        $this->actingSession(50, ['Staff'])
            ->postJson("/vendor-payments/{$paymentId}/cancel", ['version' => 1, 'reason' => 'Not mine'])
            ->assertForbidden();

        $this->actingSession(10, ['Staff'])
            ->postJson("/vendor-payments/{$paymentId}/cancel", ['version' => 1, 'reason' => 'Duplicate request'])
            ->assertOk();

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Cancelled',
            'cancelled_by' => 10,
            'cancellation_reason' => 'Duplicate request',
            'version' => 2,
        ]);
    }

    public function test_finance_transactions_support_partial_payment_overpayment_guard_and_reversal(): void
    {
        $paymentId = $this->insertPayment(['status' => 'Approved', 'version' => 1]);
        $payload = [
            'amount' => 50,
            'paid_date' => '2026-08-13',
            'method' => 'Online Transfer',
            'reference_number' => 'TXN-001',
            'idempotency_key' => 'transaction-one',
            'version' => 1,
        ];

        $response = $this->actingSession(40, ['Finance'])
            ->postJson("/vendor-payments/{$paymentId}/transactions", $payload)
            ->assertOk();
        $transactionId = (int) $response->json('id');

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Partially Paid',
            'paid_amount' => 50,
            'version' => 2,
        ]);

        $this->actingSession(40, ['Finance'])
            ->postJson("/vendor-payments/{$paymentId}/transactions", array_merge($payload, [
                'amount' => 100,
                'reference_number' => 'TXN-OVER',
                'idempotency_key' => 'transaction-over',
                'version' => 2,
            ]))
            ->assertStatus(409);

        $this->actingSession(40, ['Finance'])
            ->postJson("/vendor-payments/{$paymentId}/transactions/{$transactionId}/reverse", [
                'version' => 2,
                'reason' => 'Bank transfer was rejected',
            ])
            ->assertOk();

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Approved',
            'version' => 3,
        ]);
        $this->assertDatabaseHas('vendor_payment_transactions', [
            'id' => $transactionId,
            'reversed_by' => 40,
            'reversal_reason' => 'Bank transfer was rejected',
        ]);
    }

    private function insertPayment(array $overrides = []): int
    {
        return DB::table('vendor_payments')->insertGetId(array_merge([
            'vendor_id' => 7,
            'project_id' => 501,
            'payment_context' => 'Project',
            'payment_type' => 'Deposit',
            'amount' => 125,
            'method' => 'Online Transfer',
            'status' => 'Pending',
            'created_by' => 10,
            'created_by_name_code' => 'REQ',
            'created_at' => now(),
            'deleted_at' => null,
        ], $overrides));
    }

    private function submitPayment(int $staffId, array $overrides = [])
    {
        return $this->actingSession($staffId, ['Staff'])->post('/vendor-payments', array_merge([
            'vendor_id' => 7,
            'payment_context' => 'Office',
            'payment_type' => 'Deposit',
            'amount' => 125,
            'method' => 'Online Transfer',
            'receipt' => UploadedFile::fake()->createWithContent(
                'invoice.pdf',
                "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF",
            ),
            'idempotency_key' => 'test-'.uniqid('', true),
        ], $overrides));
    }

    private function actingSession(int $staffId, array $roles)
    {
        $userId = (int) DB::table('system_users')->where('staff_id', $staffId)->value('id');

        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => $userId,
                'staff_id' => $staffId,
                'roles' => $roles,
                'name_code' => (string) $staffId,
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }

    private function jobProperty(object $job, string $property): mixed
    {
        $reflection = new \ReflectionClass($job);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($job);
    }

    private function vendorWorkflowPayload(array $payload): array
    {
        return [
            'settings' => [
                'review_enabled' => (bool) ($payload['review_enabled'] ?? false),
                'review_levels' => (int) ($payload['review_levels'] ?? 0),
                'approval_enabled' => (bool) ($payload['approval_enabled'] ?? false),
                'approval_levels' => (int) ($payload['approval_levels'] ?? 0),
            ],
            'steps' => array_map(static fn (array $stage): array => [
                'stepKey' => (string) ($stage['stage_type'] ?? ''),
                'levelNo' => (int) ($stage['level_no'] ?? 1),
                'recipient_staff_ids' => $stage['recipient_staff_ids'] ?? [],
            ], $payload['stages'] ?? []),
        ];
    }
}
