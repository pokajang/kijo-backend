<?php

namespace Tests\Feature;

use App\Jobs\SendHtmlMailJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeaveHrVendorAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);

        foreach ([
            'in_app_notifications',
            'user_activities',
            'vendor_payments',
            'workflow_step_recipients',
            'workflow_template_steps',
            'workflow_templates',
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
            $table->timestamp('terminated_at')->nullable();
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

        DB::table('system_users')->insert([
            ['id' => 1, 'staff_id' => 10, 'email' => 'employee@example.test', 'role' => json_encode(['Employee']), 'is_active' => 1],
            ['id' => 2, 'staff_id' => 20, 'email' => 'hr@example.test', 'role' => json_encode(['HR']), 'is_active' => 1],
            ['id' => 3, 'staff_id' => 30, 'email' => 'manager@example.test', 'role' => json_encode(['Manager']), 'is_active' => 1],
        ]);

        DB::table('staff_general')->insert([
            ['staff_id' => 10, 'full_name' => 'Employee One', 'name_code' => 'EMP1', 'email' => 'employee@example.test', 'status' => 'Active', 'terminated_at' => null],
            ['staff_id' => 20, 'full_name' => 'HR User', 'name_code' => 'HR1', 'email' => 'hr@example.test', 'status' => 'Active', 'terminated_at' => null],
            ['staff_id' => 30, 'full_name' => 'Manager User', 'name_code' => 'MGR1', 'email' => 'manager@example.test', 'status' => 'Active', 'terminated_at' => null],
            ['staff_id' => 40, 'full_name' => 'Private Staff', 'name_code' => 'PVT1', 'email' => 'private@example.test', 'status' => 'Active', 'terminated_at' => null],
            ['staff_id' => 50, 'full_name' => 'Terminated Staff', 'name_code' => 'TRM1', 'email' => 'terminated@example.test', 'status' => 'Terminated', 'terminated_at' => '2025-12-31 00:00:00'],
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

    public function test_staff_list_and_leave_entitlements_include_staff_status_metadata(): void
    {
        DB::table('hr_leaves_allocation')->insert([
            ['staff_id' => 50, 'leave_type' => 'Annual', 'year' => 2026, 'total_days' => 14, 'used_days' => 2],
        ]);

        $staffResponse = $this->actingSession($this->hrSession())
            ->getJson('/staff/list')
            ->assertOk();

        $terminatedStaff = collect($staffResponse->json('staff'))->firstWhere('staff_id', 50);
        $this->assertSame('Terminated', $terminatedStaff['status']);
        $this->assertSame('2025-12-31 00:00:00', $terminatedStaff['terminated_at']);

        $entitlementsResponse = $this->actingSession($this->hrSession())
            ->getJson('/hr/leaves/entitlements')
            ->assertOk();

        $terminatedEntitlement = collect($entitlementsResponse->json('allocations'))
            ->firstWhere('staff_id', 50);
        $this->assertSame('Terminated', $terminatedEntitlement['staff_status']);
        $this->assertSame('2025-12-31 00:00:00', $terminatedEntitlement['staff_terminated_at']);
    }

    public function test_hr_can_update_past_year_leave_entitlements_and_audit_history_is_recorded(): void
    {
        $id = DB::table('hr_leaves_allocation')->insertGetId([
            'staff_id' => 10,
            'leave_type' => 'Annual',
            'year' => 1999,
            'total_days' => 10.50,
            'used_days' => 2,
        ]);

        $this->actingSession($this->hrSession())
            ->putJson("/hr/leaves/entitlements/{$id}", [
                'id' => $id,
                'staff_id' => 10,
                'type' => 'Frozen Leave',
                'year' => 1999,
                'days' => 12.25,
            ])
            ->assertOk();

        $this->assertDatabaseHas('hr_leaves_allocation', [
            'id' => $id,
            'staff_id' => 10,
            'leave_type' => 'Frozen Leave',
            'year' => 1999,
            'total_days' => 12.25,
        ]);

        $this->assertDatabaseHas('user_activities', [
            'staff_id' => 20,
            'name_code' => 'HR1',
        ]);

        $this->assertStringContainsString(
            "Updated leave entitlement #{$id} from staff #10, Annual, 1999, 10.5 days to staff #10, Frozen Leave, 1999, 12.25 days",
            DB::table('user_activities')->where('staff_id', 20)->value('action'),
        );
    }

    public function test_hr_leave_entitlement_history_lists_assignment_details(): void
    {
        $this->actingSession($this->hrSession())
            ->postJson('/hr/leaves/entitlements', [
                'staff_id' => 10,
                'type' => 'Frozen Leave',
                'year' => 1999,
                'days' => 3.5,
            ])
            ->assertOk();

        $this->actingSession($this->employeeSession())
            ->getJson('/hr/leaves/entitlements/history')
            ->assertStatus(403);

        $this->actingSession($this->hrSession())
            ->getJson('/hr/leaves/entitlements/history')
            ->assertOk()
            ->assertJsonPath('history.0.event_type', 'Assigned')
            ->assertJsonPath('history.0.staff', 'Employee One (EMP1)')
            ->assertJsonPath('history.0.leave_type', 'Frozen Leave')
            ->assertJsonPath('history.0.year', 1999)
            ->assertJsonPath('history.0.days', '3.5')
            ->assertJsonPath('history.0.assigned_by', 'HR User (HR1)')
            ->assertJsonPath(
                'history.0.description',
                'Assigned leave entitlement #1 to staff #10, Frozen Leave, 1999, 3.5 days',
            );
    }

    public function test_hr_leave_entitlement_history_keeps_legacy_update_descriptions(): void
    {
        DB::table('user_activities')->insert([
            'staff_id' => 20,
            'name_code' => 'HR1',
            'action' => 'Updated leave entitlement #88',
            'created_at' => '2026-01-15 09:00:00',
        ]);

        $this->actingSession($this->hrSession())
            ->getJson('/hr/leaves/entitlements/history')
            ->assertOk()
            ->assertJsonPath('history.0.event_type', 'Updated')
            ->assertJsonPath('history.0.entitlement_id', 88)
            ->assertJsonPath('history.0.staff', '-')
            ->assertJsonPath('history.0.leave_type', '-')
            ->assertJsonPath('history.0.days', null)
            ->assertJsonPath('history.0.assigned_by', 'HR User (HR1)')
            ->assertJsonPath('history.0.description', 'Updated leave entitlement #88');
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
            'start_date' => ($year + 1).'-01-15',
            'start_time' => '08:30',
            'end_date' => ($year + 1).'-01-15',
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

    public function test_all_leave_records_include_applicant_status_metadata(): void
    {
        $year = (int) now()->year;

        DB::table('hr_leaves_application')->insert([
            'staff_id' => 50,
            'type' => 'Annual',
            'reason' => 'Terminated staff historical leave',
            'start_date' => "{$year}-06-01",
            'start_time' => '08:30',
            'end_date' => "{$year}-06-01",
            'end_time' => '17:30',
            'duration_days' => 1,
            'status' => 'Approved',
            'applied_at' => "{$year}-05-20 09:15:00",
        ]);

        $this->actingSession($this->hrSession())
            ->getJson("/hr/leaves?year={$year}")
            ->assertOk()
            ->assertJsonPath('leaves.0.applicant_status', 'Terminated')
            ->assertJsonPath('leaves.0.applicant_terminated_at', '2025-12-31 00:00:00');
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
            'recipient_staff_id' => 30,
            'actor_staff_id' => 10,
            'module_key' => 'staff.leaves',
            'entity_type' => 'leave_application',
            'entity_id' => $leaveId,
            'type' => 'leave.needs_recommendation',
        ]);
    }

    public function test_leave_creation_succeeds_and_records_notification_when_email_fails(): void
    {
        // Phase 3 (M2): a thrown SMTP error must not fail the leave action.
        // The send helper catches it, reports/logs, and returns mail_sent=false,
        // while the leave row and in-app notification are still persisted.
        Mail::shouldReceive('html')->andThrow(new \RuntimeException('SMTP unavailable'));

        $response = $this->actingSession($this->employeeSession())
            ->postJson('/hr/leaves', [
                'type' => 'Annual',
                'reason' => 'Mail failure resilience',
                'start_date' => '2026-06-09',
                'start_time' => '08:30',
                'end_date' => '2026-06-09',
                'end_time' => '17:30',
                'duration_days' => 1,
                'status' => 'Pending',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('mail_sent', false);

        $leaveId = (int) $response->json('leave_id');

        $this->assertDatabaseHas('hr_leaves_application', [
            'id' => $leaveId,
            'staff_id' => 10,
            'reason' => 'Mail failure resilience',
            'status' => 'Pending',
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 30,
            'entity_id' => $leaveId,
            'type' => 'leave.needs_recommendation',
        ]);
    }

    public function test_central_leave_workflow_recipients_can_be_managed_by_system_admin_only(): void
    {
        $this->actingSession($this->employeeSession())
            ->putJson('/workflows/templates/leave-application', [
                'steps' => [
                    ['stepKey' => 'leave.submitted.recommenders', 'recipient_staff_ids' => [30]],
                ],
            ])
            ->assertStatus(403);

        $this->actingSession($this->managerSession())
            ->putJson('/workflows/templates/leave-application', [
                'steps' => [
                    ['stepKey' => 'leave.submitted.recommenders', 'recipient_staff_ids' => [30]],
                    ['stepKey' => 'leave.recommended.approvers', 'recipient_staff_ids' => [40]],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $submittedStepId = DB::table('workflow_template_steps as step')
            ->join('workflow_templates as template', 'template.id', '=', 'step.template_id')
            ->where('template.process_key', 'leave-application')
            ->where('step.step_key', 'leave.submitted.recommenders')
            ->value('step.id');

        $this->assertDatabaseHas('workflow_step_recipients', [
            'step_id' => $submittedStepId,
            'staff_id' => 30,
            'active' => 1,
        ]);

        $steps = $this->actingSession($this->hrSession())
            ->getJson('/workflows/templates/leave-application')
            ->assertOk()
            ->json('template.steps');

        $submittedStage = collect($steps)->firstWhere('stepKey', 'leave.submitted.recommenders');
        $this->assertSame(30, $submittedStage['recipients'][0]['staff_id']);
        $this->assertSame(30, $submittedStage['effectiveRecipients'][0]['staff_id']);
        $this->assertFalse($submittedStage['usingDefault']);
        $this->assertCount(6, $steps);
        $this->assertNotNull(collect($steps)->firstWhere('stepKey', 'leave.approved.notify'));
    }

    public function test_leave_workflow_settings_expose_effective_fallback_recipients(): void
    {
        $steps = $this->actingSession($this->hrSession())
            ->getJson('/workflows/templates/leave-application')
            ->assertOk()
            ->json('template.steps');

        $submittedStage = collect($steps)->firstWhere('stepKey', 'leave.submitted.recommenders');
        $recommendedStage = collect($steps)->firstWhere('stepKey', 'leave.recommended.approvers');

        $this->assertTrue($submittedStage['usingDefault']);
        $this->assertSame(30, $submittedStage['effectiveRecipients'][0]['staff_id']);
        $this->assertTrue($recommendedStage['usingDefault']);
        $this->assertSame(20, $recommendedStage['effectiveRecipients'][0]['staff_id']);
    }

    public function test_leave_submission_uses_configured_recommenders_for_notifications(): void
    {
        $this->configureLeaveWorkflow([
            'leave.submitted.recommenders' => [30],
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

    public function test_leave_submission_sends_individual_recommender_emails_without_cc(): void
    {
        Bus::fake([SendHtmlMailJob::class]);

        $this->configureLeaveWorkflow([
            'leave.submitted.recommenders' => [20, 30],
        ]);

        $this->actingSession($this->employeeSession())
            ->postJson('/hr/leaves', [
                'type' => 'Annual',
                'reason' => 'No visible cc',
                'start_date' => '2026-06-05',
                'start_time' => '08:30',
                'end_date' => '2026-06-05',
                'end_time' => '17:30',
                'duration_days' => 1,
                'status' => 'Pending',
            ])
            ->assertOk()
            ->assertJsonPath('mail_sent', true);

        Bus::assertDispatchedSync(SendHtmlMailJob::class, 2);
        Bus::assertDispatchedSync(SendHtmlMailJob::class, function (SendHtmlMailJob $job): bool {
            return in_array($this->jobProperty($job, 'to'), ['hr@example.test', 'manager@example.test'], true)
                && $this->jobProperty($job, 'subject') === 'New Leave Application by Employee One'
                && $this->jobProperty($job, 'cc') === [];
        });
    }

    public function test_leave_rejection_copy_email_uses_workflow_subject_and_recipient_name(): void
    {
        Bus::fake([SendHtmlMailJob::class]);

        $this->configureLeaveWorkflow([
            'leave.rejected.notify' => [20],
        ]);

        $leaveId = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => 10,
            'type' => 'Annual',
            'reason' => 'Copy email subject test',
            'start_date' => '2026-06-05',
            'start_time' => '08:30',
            'end_date' => '2026-06-05',
            'end_time' => '12:30',
            'duration_days' => 0.5,
            'status' => 'Pending',
            'applied_at' => '2026-05-20 09:15:00',
            'reviewed_by' => 30,
            'reviewed_at' => now(),
            'reviewed_status' => 'Recommended',
        ]);

        $this->actingSession($this->hrSession())
            ->postJson("/hr/leaves/{$leaveId}/action", [
                'id' => $leaveId,
                'action' => 'reject',
                'remarks' => 'Rejected',
            ])
            ->assertOk();

        Bus::assertDispatchedSync(SendHtmlMailJob::class, 2);
        Bus::assertDispatchedSync(SendHtmlMailJob::class, function (SendHtmlMailJob $job): bool {
            return $this->jobProperty($job, 'to') === 'employee@example.test'
                && $this->jobProperty($job, 'toName') === 'Employee One'
                && $this->jobProperty($job, 'subject') === 'Your Leave Application Has Been Rejected';
        });
        Bus::assertDispatchedSync(SendHtmlMailJob::class, function (SendHtmlMailJob $job): bool {
            return $this->jobProperty($job, 'to') === 'hr@example.test'
                && $this->jobProperty($job, 'toName') === 'HR User'
                && $this->jobProperty($job, 'subject') === 'Leave Application by Employee One Has Been Rejected'
                && $this->jobProperty($job, 'cc') === [];
        });
    }

    public function test_recommend_action_uses_configured_approvers_for_notifications(): void
    {
        $this->configureLeaveWorkflow([
            'leave.recommended.approvers' => [40],
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
            'recipient_staff_id' => 30,
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

        $this->actingSession($this->managerSession())
            ->postJson("/hr/leaves/{$leaveId}/action", [
                'id' => $leaveId,
                'action' => 'recommend',
                'remarks' => 'Recommended',
            ])
            ->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 40,
            'actor_staff_id' => 30,
            'entity_id' => $leaveId,
            'type' => 'leave.needs_approval',
        ]);
        $this->assertDatabaseMissing('in_app_notifications', [
            'recipient_staff_id' => 20,
            'entity_id' => $leaveId,
            'type' => 'leave.needs_approval',
        ]);
    }

    public function test_leave_recommend_action_requires_recommender_stage_access(): void
    {
        $leaveId = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => 10,
            'type' => 'Annual',
            'reason' => 'HR should not recommend',
            'start_date' => '2026-06-05',
            'start_time' => '08:30',
            'end_date' => '2026-06-05',
            'end_time' => '17:30',
            'duration_days' => 1,
            'status' => 'Pending',
            'applied_at' => '2026-05-20 09:15:00',
        ]);

        $this->actingSession($this->hrSession())
            ->postJson("/hr/leaves/{$leaveId}/action", [
                'id' => $leaveId,
                'action' => 'recommend',
                'remarks' => 'Recommended',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You are not authorized to recommend this leave.');

        $this->assertNull(DB::table('hr_leaves_application')->where('id', $leaveId)->value('reviewed_by'));
    }

    public function test_leave_approve_action_requires_approver_stage_access(): void
    {
        $leaveId = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => 10,
            'type' => 'Annual',
            'reason' => 'Manager should not approve',
            'start_date' => '2026-06-06',
            'start_time' => '08:30',
            'end_date' => '2026-06-06',
            'end_time' => '17:30',
            'duration_days' => 1,
            'status' => 'Pending',
            'applied_at' => '2026-05-20 09:15:00',
            'reviewed_by' => 30,
            'reviewed_at' => now(),
            'reviewed_status' => 'Recommended',
        ]);

        $this->actingSession($this->managerSession())
            ->postJson("/hr/leaves/{$leaveId}/action", [
                'id' => $leaveId,
                'action' => 'approve',
                'remarks' => 'Approved',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You are not authorized to approve this leave.');

        $this->assertDatabaseHas('hr_leaves_application', [
            'id' => $leaveId,
            'status' => 'Pending',
            'approved_by' => null,
        ]);
    }

    public function test_configured_workflow_recipient_gets_backend_permissions_without_default_stage_role(): void
    {
        $this->configureLeaveWorkflow([
            'leave.submitted.recommenders' => [20],
            'leave.recommended.approvers' => [30],
        ]);

        $leaveId = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => 10,
            'type' => 'Annual',
            'reason' => 'Configured recipient should recommend',
            'start_date' => '2026-06-07',
            'start_time' => '08:30',
            'end_date' => '2026-06-07',
            'end_time' => '17:30',
            'duration_days' => 1,
            'status' => 'Pending',
            'applied_at' => '2026-05-20 09:15:00',
        ]);

        $this->actingSession($this->hrSession())
            ->getJson('/hr/leaves')
            ->assertOk()
            ->assertJsonPath('action_permissions.can_recommend', true)
            ->assertJsonPath('action_permissions.can_approve', false);

        $this->actingSession($this->hrSession())
            ->postJson("/hr/leaves/{$leaveId}/action", [
                'id' => $leaveId,
                'action' => 'recommend',
                'remarks' => 'Configured recommender',
            ])
            ->assertOk();

        $this->assertDatabaseHas('hr_leaves_application', [
            'id' => $leaveId,
            'status' => 'Pending',
            'reviewed_by' => 20,
            'reviewed_status' => 'Recommended',
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

        $summary = $this->actingSession($this->managerSession())
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $summary['by_module']['staff.leaves'] ?? 0);
        $this->assertSame(1, $summary['by_route_group']['/staff/leaves'] ?? 0);
        $this->assertSame(1, $summary['by_tab']['staff.leaves'] ?? 0);

        $this->actingSession($this->managerSession())
            ->postJson("/hr/leaves/{$leaveId}/action", [
                'id' => $leaveId,
                'action' => 'recommend',
                'remarks' => 'Recommended',
            ])
            ->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 30,
            'entity_id' => $leaveId,
            'type' => 'leave.needs_recommendation',
        ]);
        $this->assertNotNull(
            DB::table('in_app_notifications')
                ->where('recipient_staff_id', 30)
                ->where('entity_id', $leaveId)
                ->where('type', 'leave.needs_recommendation')
                ->value('consumed_at'),
        );
        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 20,
            'actor_staff_id' => 30,
            'entity_id' => $leaveId,
            'type' => 'leave.needs_approval',
        ]);

        $summary = $this->actingSession($this->hrSession())
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $summary['by_module']['staff.leaves'] ?? 0);

        $this->actingSession($this->hrSession())
            ->postJson("/hr/leaves/{$leaveId}/action", [
                'id' => $leaveId,
                'action' => 'approve',
                'remarks' => 'Approved',
            ])
            ->assertOk();

        $this->assertNotNull(
            DB::table('in_app_notifications')
                ->where('recipient_staff_id', 20)
                ->where('entity_id', $leaveId)
                ->where('type', 'leave.needs_approval')
                ->value('consumed_at'),
        );
        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 10,
            'actor_staff_id' => 20,
            'module_key' => 'my.leaves',
            'entity_id' => $leaveId,
            'type' => 'leave.approved',
        ]);

        $summary = $this->actingSession($this->employeeSession())
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $summary['by_module']['my.leaves'] ?? 0);
        $this->assertSame(1, $summary['by_route_group']['/my/leaves'] ?? 0);

        $this->actingSession($this->employeeSession())
            ->postJson('/notifications/consume-entity', [
                'module_key' => 'my.leaves',
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
            'reviewed_by' => 30,
            'reviewed_status' => 'Recommended',
            'reviewed_at' => '2026-05-20 09:30:00',
        ]);

        $this->actingSession($this->hrSession())
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
            'approved_by' => 20,
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

    public function test_cancel_already_cancelled_leave_is_idempotent(): void
    {
        Bus::fake();

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
            'reason' => 'Already cancelled leave',
            'start_date' => '2026-06-01',
            'start_time' => '08:30',
            'end_date' => '2026-06-01',
            'end_time' => '17:30',
            'duration_days' => 1,
            'status' => 'Cancelled',
            'applied_at' => '2026-05-20 09:15:00',
            'cancelled_by' => 10,
            'cancelled_at' => '2026-05-21 11:00:00',
        ]);

        $this->actingSession($this->employeeSession())
            ->postJson("/hr/leaves/{$leaveId}/cancel", ['id' => $leaveId])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Leave application is already cancelled.');

        $this->assertDatabaseHas('hr_leaves_application', [
            'id' => $leaveId,
            'status' => 'Cancelled',
            'cancelled_by' => 10,
            'cancelled_at' => '2026-05-21 11:00:00',
        ]);
        $this->assertEquals(
            '3',
            (string) DB::table('hr_leaves_allocation')
                ->where('staff_id', 10)
                ->where('leave_type', 'Annual')
                ->where('year', 2026)
                ->value('used_days'),
        );
        $this->assertSame(0, DB::table('in_app_notifications')->count());
        $this->assertSame(0, DB::table('user_activities')->count());
        Bus::assertNotDispatched(SendHtmlMailJob::class);
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
            ->assertStatus(409);

        $this->actingSession($this->managerSession())
            ->patchJson("/vendor-payments/{$paymentId}/check")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('vendor_payments', [
            'id' => $paymentId,
            'status' => 'Checked',
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

    private function configureLeaveWorkflow(array $stages): void
    {
        $steps = [];
        foreach ($stages as $stageKey => $staffIds) {
            $steps[] = [
                'stepKey' => $stageKey,
                'recipient_staff_ids' => $staffIds,
            ];
        }

        $this->actingSession($this->managerSession())
            ->putJson('/workflows/templates/leave-application', ['steps' => $steps])
            ->assertOk();
    }

    private function jobProperty(object $job, string $property): mixed
    {
        $reflection = new \ReflectionClass($job);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($job);
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
