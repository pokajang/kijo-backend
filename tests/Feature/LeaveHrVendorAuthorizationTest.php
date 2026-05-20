<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeaveHrVendorAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ]);

        foreach ([
            'in_app_notifications',
            'user_activities',
            'vendor_payments',
            'hr_leave_workflow_recipients',
            'hr_leaves_application',
            'hr_leaves_allocation',
            'staff_profile',
            'staff_general',
            'system_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->unsignedInteger('staff_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('crm_position')->nullable();
            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->date('start_date')->nullable();
            $table->string('status')->nullable();
            $table->json('role')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('staff_profile', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->date('birth_date')->nullable();
            $table->string('nric')->nullable();
            $table->string('current_address')->nullable();
            $table->string('emergency_name1')->nullable();
            $table->string('emergency_name2')->nullable();
            $table->string('emergency_relationship1')->nullable();
            $table->string('emergency_relationship2')->nullable();
            $table->string('emergency_phone1')->nullable();
            $table->string('emergency_phone2')->nullable();
            $table->string('emergency_address1')->nullable();
            $table->string('emergency_address2')->nullable();
            $table->string('chronic_illness')->nullable();
            $table->string('allergies')->nullable();
            $table->string('disabilities')->nullable();
            $table->string('current_medication')->nullable();
            $table->string('other_concerns')->nullable();
        });

        Schema::create('hr_leaves_allocation', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('leave_type');
            $table->integer('year');
            $table->decimal('total_days', 8, 2)->default(0);
            $table->decimal('used_days', 8, 2)->default(0);
        });

        Schema::create('hr_leaves_application', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('type');
            $table->text('reason')->nullable();
            $table->date('start_date');
            $table->string('start_time');
            $table->date('end_date');
            $table->string('end_time');
            $table->decimal('duration_days', 8, 2)->default(0);
            $table->string('status')->default('Pending');
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_status')->nullable();
            $table->text('reviewed_remarks')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approved_status')->nullable();
            $table->text('approved_remarks')->nullable();
            $table->unsignedInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
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
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedInteger('deleted_by')->nullable();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
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

        Schema::create('hr_leave_workflow_recipients', function (Blueprint $table): void {
            $table->id();
            $table->string('stage_key');
            $table->unsignedInteger('staff_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['stage_key', 'staff_id']);
        });

        DB::table('system_users')->insert([
            ['id' => 1, 'staff_id' => 10, 'email' => 'employee@example.test', 'role' => json_encode(['Employee']), 'is_active' => 1],
            ['id' => 2, 'staff_id' => 20, 'email' => 'hr@example.test', 'role' => json_encode(['HR']), 'is_active' => 1],
            ['id' => 3, 'staff_id' => 30, 'email' => 'manager@example.test', 'role' => json_encode(['Manager']), 'is_active' => 1],
        ]);

        DB::table('staff_general')->insert([
            ['staff_id' => 10, 'full_name' => 'Employee One', 'name_code' => 'EMP1', 'email' => 'employee@example.test', 'status' => 'Active'],
            ['staff_id' => 20, 'full_name' => 'HR User', 'name_code' => 'HR1', 'email' => 'hr@example.test', 'status' => 'Active'],
            ['staff_id' => 30, 'full_name' => 'Manager User', 'name_code' => 'MGR1', 'email' => 'manager@example.test', 'status' => 'Active'],
            ['staff_id' => 40, 'full_name' => 'Private Staff', 'name_code' => 'PVT1', 'email' => 'private@example.test', 'status' => 'Active'],
        ]);

        DB::table('staff_profile')->insert([
            'staff_id' => 40,
            'nric' => '900101-01-1234',
            'chronic_illness' => 'Asthma',
        ]);
    }

    public function test_leave_entitlement_management_requires_hr_but_mine_remains_self_service(): void
    {
        DB::table('hr_leaves_allocation')->insert([
            ['staff_id' => 10, 'leave_type' => 'Annual', 'year' => 2026, 'total_days' => 14, 'used_days' => 2],
            ['staff_id' => 40, 'leave_type' => 'Medical', 'year' => 2026, 'total_days' => 14, 'used_days' => 0],
        ]);

        $this->actingSession($this->employeeSession())
            ->getJson('/hr/leaves/entitlements')
            ->assertStatus(403);

        $this->actingSession($this->employeeSession())
            ->postJson('/hr/leaves/entitlements', [
                'staff_id' => 10,
                'type' => 'Replacement',
                'year' => 2026,
                'days' => 1,
            ])
            ->assertStatus(403);

        $this->actingSession($this->employeeSession())
            ->getJson('/hr/leaves/entitlements/mine')
            ->assertOk()
            ->assertJsonPath('entitlements.0.leave_type', 'Annual');

        $this->actingSession($this->hrSession())
            ->getJson('/hr/leaves/entitlements')
            ->assertOk()
            ->assertJsonCount(2, 'allocations');

        $this->actingSession($this->hrSession())
            ->deleteJson('/hr/leaves/entitlements/1')
            ->assertOk();

        $this->assertDatabaseMissing('hr_leaves_allocation', ['id' => 1]);
    }

    public function test_hr_staff_detail_requires_hr_or_system_admin(): void
    {
        $this->actingSession($this->employeeSession())
            ->getJson('/hr/staff/40')
            ->assertStatus(403);

        $this->actingSession($this->hrSession())
            ->getJson('/hr/staff/40')
            ->assertOk()
            ->assertJsonPath('data.general.full_name', 'Private Staff')
            ->assertJsonPath('data.profile.nric', '900101-01-1234')
            ->assertJsonPath('data.profile.chronic_illness', 'Asthma');
    }

    public function test_all_leave_records_include_applications_submitted_in_filtered_year(): void
    {
        $year = (int) now()->year;

        DB::table('hr_leaves_application')->insert([
            'staff_id' => 10,
            'type' => 'Annual',
            'reason' => 'Future leave submitted this year',
            'start_date' => ($year + 1) . '-01-15',
            'start_time' => '08:30',
            'end_date' => ($year + 1) . '-01-15',
            'end_time' => '17:30',
            'duration_days' => 1,
            'status' => 'Pending',
            'applied_at' => "{$year}-05-20 09:15:00",
        ]);

        $this->actingSession($this->hrSession())
            ->getJson("/hr/leaves?year={$year}")
            ->assertOk()
            ->assertJsonPath('leaves.0.applicant_name', 'Employee One')
            ->assertJsonPath('leaves.0.reason', 'Future leave submitted this year');
    }

    public function test_all_leave_records_include_canceller_details(): void
    {
        $year = (int) now()->year;

        DB::table('hr_leaves_application')->insert([
            'staff_id' => 10,
            'type' => 'Annual',
            'reason' => 'Cancelled before review',
            'start_date' => "{$year}-06-01",
            'start_time' => '08:30',
            'end_date' => "{$year}-06-01",
            'end_time' => '17:30',
            'duration_days' => 1,
            'status' => 'Cancelled',
            'applied_at' => "{$year}-05-20 09:15:00",
            'cancelled_by' => 10,
            'cancelled_at' => "{$year}-05-20 10:30:00",
        ]);

        $this->actingSession($this->hrSession())
            ->getJson("/hr/leaves?year={$year}")
            ->assertOk()
            ->assertJsonPath('leaves.0.canceller_name', 'Employee One')
            ->assertJsonPath('leaves.0.canceller_code', 'EMP1');
    }

    public function test_leave_application_reports_notification_send_result(): void
    {
        $response = $this->actingSession($this->employeeSession())
            ->postJson('/hr/leaves', [
                'type' => 'Annual',
                'reason' => 'Family matters',
                'start_date' => '2026-06-01',
                'start_time' => '08:30',
                'end_date' => '2026-06-01',
                'end_time' => '17:30',
                'duration_days' => 1,
                'status' => 'Pending',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('mail_sent', true);

        $leaveId = (int) $response->json('leave_id');

        $this->assertDatabaseHas('hr_leaves_application', [
            'staff_id' => 10,
            'type' => 'Annual',
            'reason' => 'Family matters',
            'status' => 'Pending',
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 20,
            'actor_staff_id' => 10,
            'module_key' => 'staff.leaves',
            'entity_type' => 'leave_application',
            'entity_id' => $leaveId,
            'type' => 'leave.needs_recommendation',
        ]);
    }

    public function test_hr_can_manage_leave_workflow_recipients_but_employee_cannot(): void
    {
        $this->actingSession($this->employeeSession())
            ->putJson('/hr/leaves/workflow-recipients', [
                'stages' => [
                    'leave.submitted.recommenders' => [30],
                ],
            ])
            ->assertStatus(403);

        $this->actingSession($this->hrSession())
            ->putJson('/hr/leaves/workflow-recipients', [
                'stages' => [
                    'leave.submitted.recommenders' => [30],
                    'leave.recommended.approvers' => [40],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('hr_leave_workflow_recipients', [
            'stage_key' => 'leave.submitted.recommenders',
            'staff_id' => 30,
            'is_active' => 1,
        ]);

        $stages = $this->actingSession($this->hrSession())
            ->getJson('/hr/leaves/workflow-recipients')
            ->assertOk()
            ->json('stages');

        $submittedStage = collect($stages)->firstWhere('key', 'leave.submitted.recommenders');
        $this->assertSame(30, $submittedStage['recipients'][0]['staff_id']);
        $this->assertSame(30, $submittedStage['effective_recipients'][0]['staff_id']);
        $this->assertFalse($submittedStage['using_default']);
        $this->assertCount(2, $stages);
        $this->assertNull(collect($stages)->firstWhere('key', 'leave.approved.notify'));
    }

    public function test_leave_workflow_settings_expose_effective_fallback_recipients(): void
    {
        $stages = $this->actingSession($this->hrSession())
            ->getJson('/hr/leaves/workflow-recipients')
            ->assertOk()
            ->json('stages');

        $submittedStage = collect($stages)->firstWhere('key', 'leave.submitted.recommenders');
        $recommendedStage = collect($stages)->firstWhere('key', 'leave.recommended.approvers');

        $this->assertTrue($submittedStage['using_default']);
        $this->assertSame(20, $submittedStage['effective_recipients'][0]['staff_id']);
        $this->assertTrue($recommendedStage['using_default']);
        $this->assertSame(30, $recommendedStage['effective_recipients'][0]['staff_id']);
    }

    public function test_leave_submission_uses_configured_recommenders_for_notifications(): void
    {
        DB::table('hr_leave_workflow_recipients')->insert([
            'stage_key' => 'leave.submitted.recommenders',
            'staff_id' => 30,
            'sort_order' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingSession($this->employeeSession())
            ->postJson('/hr/leaves', [
                'type' => 'Annual',
                'reason' => 'Configured recommender test',
                'start_date' => '2026-06-03',
                'start_time' => '08:30',
                'end_date' => '2026-06-03',
                'end_time' => '17:30',
                'duration_days' => 1,
                'status' => 'Pending',
            ])
            ->assertOk()
            ->assertJsonPath('mail_sent', true);

        $leaveId = (int) $response->json('leave_id');

        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 30,
            'entity_id' => $leaveId,
            'type' => 'leave.needs_recommendation',
        ]);
        $this->assertDatabaseMissing('in_app_notifications', [
            'recipient_staff_id' => 20,
            'entity_id' => $leaveId,
            'type' => 'leave.needs_recommendation',
        ]);
    }

    public function test_recommend_action_uses_configured_approvers_for_notifications(): void
    {
        DB::table('hr_leave_workflow_recipients')->insert([
            'stage_key' => 'leave.recommended.approvers',
            'staff_id' => 40,
            'sort_order' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $leaveId = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => 10,
            'type' => 'Annual',
            'reason' => 'Configured approver test',
            'start_date' => '2026-06-04',
            'start_time' => '08:30',
            'end_date' => '2026-06-04',
            'end_time' => '17:30',
            'duration_days' => 1,
            'status' => 'Pending',
            'applied_at' => '2026-05-20 09:15:00',
        ]);

        DB::table('in_app_notifications')->insert([
            'recipient_staff_id' => 20,
            'actor_staff_id' => 10,
            'module_key' => 'staff.leaves',
            'entity_type' => 'leave_application',
            'entity_id' => $leaveId,
            'type' => 'leave.needs_recommendation',
            'title' => 'Leave request needs recommendation',
            'severity' => 'warning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingSession($this->hrSession())
            ->postJson("/hr/leaves/{$leaveId}/action", [
                'id' => $leaveId,
                'action' => 'recommend',
                'remarks' => 'Recommended',
            ])
            ->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 40,
            'actor_staff_id' => 20,
            'entity_id' => $leaveId,
            'type' => 'leave.needs_approval',
        ]);
        $this->assertDatabaseMissing('in_app_notifications', [
            'recipient_staff_id' => 30,
            'entity_id' => $leaveId,
            'type' => 'leave.needs_approval',
        ]);
    }

    public function test_leave_notification_lifecycle_moves_between_reviewer_approver_and_applicant(): void
    {
        $response = $this->actingSession($this->employeeSession())
            ->postJson('/hr/leaves', [
                'type' => 'Annual',
                'reason' => 'Lifecycle test',
                'start_date' => '2026-06-02',
                'start_time' => '08:30',
                'end_date' => '2026-06-02',
                'end_time' => '17:30',
                'duration_days' => 1,
                'status' => 'Pending',
            ])
            ->assertOk();

        $leaveId = (int) $response->json('leave_id');

        $summary = $this->actingSession($this->hrSession())
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $summary['by_module']['staff.leaves'] ?? 0);
        $this->assertSame(1, $summary['by_route_group']['/staff/manage'] ?? 0);
        $this->assertSame(1, $summary['by_tab']['staff.leaves'] ?? 0);

        $this->actingSession($this->hrSession())
            ->postJson("/hr/leaves/{$leaveId}/action", [
                'id' => $leaveId,
                'action' => 'recommend',
                'remarks' => 'Recommended',
            ])
            ->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 20,
            'entity_id' => $leaveId,
            'type' => 'leave.needs_recommendation',
        ]);
        $this->assertNotNull(
            DB::table('in_app_notifications')
                ->where('recipient_staff_id', 20)
                ->where('entity_id', $leaveId)
                ->where('type', 'leave.needs_recommendation')
                ->value('consumed_at'),
        );
        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 30,
            'actor_staff_id' => 20,
            'entity_id' => $leaveId,
            'type' => 'leave.needs_approval',
        ]);

        $summary = $this->actingSession($this->managerSession())
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $summary['by_module']['staff.leaves'] ?? 0);

        $this->actingSession($this->managerSession())
            ->postJson("/hr/leaves/{$leaveId}/action", [
                'id' => $leaveId,
                'action' => 'approve',
                'remarks' => 'Approved',
            ])
            ->assertOk();

        $this->assertNotNull(
            DB::table('in_app_notifications')
                ->where('recipient_staff_id', 30)
                ->where('entity_id', $leaveId)
                ->where('type', 'leave.needs_approval')
                ->value('consumed_at'),
        );
        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 10,
            'actor_staff_id' => 30,
            'entity_id' => $leaveId,
            'type' => 'leave.approved',
        ]);

        $summary = $this->actingSession($this->employeeSession())
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $summary['by_module']['staff.leaves'] ?? 0);

        $this->actingSession($this->employeeSession())
            ->postJson('/notifications/consume-entity', [
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => $leaveId,
            ])
            ->assertOk()
            ->assertJsonPath('data.consumed_count', 1);

        $this->actingSession($this->employeeSession())
            ->getJson('/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_approve_leave_updates_integer_year_allocation_usage(): void
    {
        DB::table('hr_leaves_allocation')->insert([
            'staff_id' => 10,
            'leave_type' => 'Annual',
            'year' => 2026,
            'total_days' => 14,
            'used_days' => 2,
        ]);

        $leaveId = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => 10,
            'type' => 'Annual',
            'reason' => 'Approved leave',
            'start_date' => '2026-06-01',
            'start_time' => '08:30',
            'end_date' => '2026-06-01',
            'end_time' => '17:30',
            'duration_days' => 1,
            'status' => 'Pending',
            'applied_at' => '2026-05-20 09:15:00',
            'reviewed_by' => 20,
            'reviewed_status' => 'Recommended',
            'reviewed_at' => '2026-05-20 09:30:00',
        ]);

        $this->actingSession($this->managerSession())
            ->postJson("/hr/leaves/{$leaveId}/action", [
                'id' => $leaveId,
                'action' => 'approve',
                'remarks' => 'Approved',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('hr_leaves_application', [
            'id' => $leaveId,
            'status' => 'Approved',
            'approved_by' => 30,
        ]);
        $this->assertEquals(
            '3',
            (string) DB::table('hr_leaves_allocation')
                ->where('staff_id', 10)
                ->where('leave_type', 'Annual')
                ->where('year', 2026)
                ->value('used_days'),
        );
    }

    public function test_cancel_approved_leave_reverses_integer_year_allocation_usage(): void
    {
        DB::table('hr_leaves_allocation')->insert([
            'staff_id' => 10,
            'leave_type' => 'Annual',
            'year' => 2026,
            'total_days' => 14,
            'used_days' => 3,
        ]);

        $leaveId = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => 10,
            'type' => 'Annual',
            'reason' => 'Cancel approved leave',
            'start_date' => '2026-06-01',
            'start_time' => '08:30',
            'end_date' => '2026-06-01',
            'end_time' => '17:30',
            'duration_days' => 1,
            'status' => 'Approved',
            'applied_at' => '2026-05-20 09:15:00',
            'reviewed_by' => 20,
            'reviewed_status' => 'Recommended',
            'reviewed_at' => '2026-05-20 09:30:00',
            'approved_by' => 30,
            'approved_status' => 'Approved',
            'approved_at' => '2026-05-20 10:00:00',
        ]);

        $this->actingSession($this->employeeSession())
            ->postJson("/hr/leaves/{$leaveId}/cancel", ['id' => $leaveId])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('hr_leaves_application', [
            'id' => $leaveId,
            'status' => 'Cancelled',
            'cancelled_by' => 10,
        ]);
        $this->assertEquals(
            '2',
            (string) DB::table('hr_leaves_allocation')
                ->where('staff_id', 10)
                ->where('leave_type', 'Annual')
                ->where('year', 2026)
                ->value('used_days'),
        );
    }

    public function test_privileged_staff_can_cancel_approved_leave_for_other_staff(): void
    {
        DB::table('hr_leaves_allocation')->insert([
            'staff_id' => 10,
            'leave_type' => 'Annual',
            'year' => 2026,
            'total_days' => 14,
            'used_days' => 3,
        ]);

        $leaveId = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => 10,
            'type' => 'Annual',
            'reason' => 'HR revoke approved leave',
            'start_date' => '2026-06-01',
            'start_time' => '08:30',
            'end_date' => '2026-06-01',
            'end_time' => '17:30',
            'duration_days' => 1,
            'status' => 'Approved',
            'applied_at' => '2026-05-20 09:15:00',
            'reviewed_by' => 20,
            'reviewed_status' => 'Recommended',
            'reviewed_at' => '2026-05-20 09:30:00',
            'approved_by' => 30,
            'approved_status' => 'Approved',
            'approved_at' => '2026-05-20 10:00:00',
        ]);

        $this->actingSession($this->hrSession())
            ->postJson("/hr/leaves/{$leaveId}/cancel", ['id' => $leaveId])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('hr_leaves_application', [
            'id' => $leaveId,
            'status' => 'Cancelled',
            'cancelled_by' => 20,
        ]);
        $this->assertEquals(
            '2',
            (string) DB::table('hr_leaves_allocation')
                ->where('staff_id', 10)
                ->where('leave_type', 'Annual')
                ->where('year', 2026)
                ->value('used_days'),
        );
    }

    public function test_vendor_payment_approve_and_delete_require_manager_role(): void
    {
        $paymentId = DB::table('vendor_payments')->insertGetId([
            'vendor_id' => 7,
            'amount' => 150,
            'status' => 'Pending',
            'created_at' => now(),
        ]);

        $this->actingSession($this->employeeSession())
            ->patchJson("/vendor-payments/{$paymentId}/approve")
            ->assertStatus(403);

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Pending',
            'approved_by' => null,
        ]);

        $this->actingSession($this->employeeSession())
            ->deleteJson("/vendor-payments/{$paymentId}")
            ->assertStatus(403);

        $this->assertNull(DB::table('vendor_payments')->where('id', $paymentId)->value('deleted_at'));

        $this->actingSession($this->managerSession())
            ->patchJson("/vendor-payments/{$paymentId}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Approved',
            'approved_by' => 30,
        ]);

        $this->actingSession($this->managerSession())
            ->deleteJson("/vendor-payments/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertNotNull(DB::table('vendor_payments')->where('id', $paymentId)->value('deleted_at'));
    }

    public function test_vendor_payment_approve_rejects_route_body_id_mismatch(): void
    {
        DB::table('vendor_payments')->insert([
            ['id' => 1, 'vendor_id' => 7, 'amount' => 150, 'status' => 'Pending', 'created_at' => now()],
            ['id' => 2, 'vendor_id' => 8, 'amount' => 200, 'status' => 'Pending', 'created_at' => now()],
        ]);

        $this->actingSession($this->managerSession())
            ->patchJson('/vendor-payments/1/approve', ['id' => 2])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Payment ID mismatch.');

        $this->assertDatabaseHas('vendor_payments', [
            'id' => 1,
            'status' => 'Pending',
            'approved_by' => null,
        ]);
    }

    private function employeeSession(): array
    {
        return ['user_id' => 1, 'staff_id' => 10, 'name_code' => 'EMP1', 'roles' => ['Employee']];
    }

    private function actingSession(array $session)
    {
        return $this
            ->withSession($session + ['_token' => 'test-token'])
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }

    private function hrSession(): array
    {
        return ['user_id' => 2, 'staff_id' => 20, 'name_code' => 'HR1', 'roles' => ['HR']];
    }

    private function managerSession(): array
    {
        return ['user_id' => 3, 'staff_id' => 30, 'name_code' => 'MGR1', 'roles' => ['Manager']];
    }
}
