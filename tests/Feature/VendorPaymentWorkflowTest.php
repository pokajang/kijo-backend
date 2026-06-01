<?php

namespace Tests\Feature;

use App\Jobs\SendHtmlMailJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        foreach ([
            'in_app_notifications',
            'staff_general',
            'system_users',
            'vendor_payment_workflow_recipients',
            'vendor_payment_workflow_settings',
            'projects_main',
            'vendor_main_details',
            'vendor_payments',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedInteger('deleted_by')->nullable();
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

    public function test_requester_cannot_check_or_approve_own_request(): void
    {
        $paymentId = $this->insertPayment(['created_by' => 20]);

        $this->actingSession(20, ['Manager'])
            ->patchJson("/vendor-payments/{$paymentId}/check")
            ->assertStatus(409);

        DB::table('vendor_payments')->where('id', $paymentId)->update([
            'status' => 'Checked',
            'checked_by' => 30,
        ]);

        $this->actingSession(20, ['Manager'])
            ->patchJson("/vendor-payments/{$paymentId}/approve")
            ->assertStatus(409);
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

        $this->actingSession(20, ['Manager'])
            ->patchJson("/vendor-payments/{$paymentId}/approve", ['remarks' => 'Same checker'])
            ->assertStatus(409);

        $this->actingSession(30, ['System Admin'])
            ->patchJson("/vendor-payments/{$paymentId}/approve", ['remarks' => 'Approved'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Approved',
            'approved_by' => 30,
            'approval_remarks' => 'Approved',
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
            ->assertStatus(409);

        $this->assertNull(DB::table('vendor_payments')->where('id', $paymentId)->value('deleted_at'));

        DB::table('vendor_payments')->where('id', $paymentId)->update(['status' => 'Paid']);

        $this->actingSession(20, ['Manager'])
            ->deleteJson("/vendor-payments/{$paymentId}")
            ->assertStatus(409);
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
        $submitResponse = $this->actingSession(10, ['Staff'])
            ->postJson('/vendor-payments', [
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
            ->putJson('/vendor-payments/workflow-settings', [
                'review_enabled' => true,
                'review_levels' => 2,
                'approval_enabled' => false,
                'approval_levels' => 0,
                'stages' => [
                    ['stage_type' => 'review', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                    ['stage_type' => 'review', 'level_no' => 2, 'recipient_staff_ids' => [60]],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('settings.review_levels', 2)
            ->assertJsonPath('stages.0.recipients.0.staff_id', 50);

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
            ->getJson('/vendor-payments/workflow-settings')
            ->assertOk()
            ->assertJsonPath('can_edit', false);

        $this->actingSession(60, ['Staff'])
            ->putJson('/vendor-payments/workflow-settings', [
                'review_enabled' => false,
                'review_levels' => 0,
                'approval_enabled' => false,
                'approval_levels' => 0,
                'stages' => [],
            ])
            ->assertStatus(403);

        $this->actingSession(30, ['System Admin'])
            ->getJson('/vendor-payments/workflow-settings')
            ->assertOk()
            ->assertJsonPath('can_edit', true);
    }

    public function test_approval_only_workflow_starts_checked_and_uses_configured_approver(): void
    {
        $this->actingSession(20, ['Manager'])
            ->putJson('/vendor-payments/workflow-settings', [
                'review_enabled' => false,
                'review_levels' => 0,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'approval', 'level_no' => 1, 'recipient_staff_ids' => [60]],
                ],
            ])
            ->assertOk();

        $submitResponse = $this->actingSession(10, ['Staff'])
            ->postJson('/vendor-payments', [
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
            ->putJson('/vendor-payments/workflow-settings', [
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'review', 'level_no' => 1, 'recipient_staff_ids' => [20]],
                    ['stage_type' => 'approval', 'level_no' => 1, 'recipient_staff_ids' => [30]],
                    ['stage_type' => 'finance', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                ],
            ])
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
            ->putJson('/vendor-payments/workflow-settings', [
                'review_enabled' => false,
                'review_levels' => 0,
                'approval_enabled' => false,
                'approval_levels' => 0,
                'stages' => [
                    ['stage_type' => 'finance', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                ],
            ])
            ->assertOk();

        $submitResponse = $this->actingSession(10, ['Staff'])
            ->postJson('/vendor-payments', [
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
            ->putJson('/vendor-payments/workflow-settings', [
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'finance', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('stages.2.stage_type', 'finance')
            ->assertJsonPath('stages.2.recipients.0.staff_id', 50);

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
            ->putJson('/vendor-payments/workflow-settings', [
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'finance', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                ],
            ])
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

    public function test_workflow_settings_reject_non_level_one_finance_stage(): void
    {
        $this->actingSession(20, ['Manager'])
            ->putJson('/vendor-payments/workflow-settings', [
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'finance', 'level_no' => 2, 'recipient_staff_ids' => [50]],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_payment_queue_returns_mark_paid_permission_for_configured_finance(): void
    {
        $this->actingSession(20, ['Manager'])
            ->putJson('/vendor-payments/workflow-settings', [
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
                'stages' => [
                    ['stage_type' => 'finance', 'level_no' => 1, 'recipient_staff_ids' => [50]],
                ],
            ])
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
}
