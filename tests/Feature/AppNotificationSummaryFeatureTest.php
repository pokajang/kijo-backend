<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use App\Services\AppNotificationService;
use App\Services\Leaves\LeaveNotificationType;
use App\Services\Leaves\LeaveWorkflowRecipientService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AppNotificationSummaryFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-19 09:00:00');

        $this->withoutMiddleware([
            RequireAuth::class,
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);

        foreach ([
            'quote_approval_requests',
            'quote_price_exception_requests',
            'quotes_training',
            'quotes_manpower',
            'client_vendor_registration_recipients',
            'client_vendor_registrations',
            'in_app_notifications',
            'vendor_payment_workflow_recipients',
            'vendor_payments',
            'hr_leave_workflow_recipients',
            'hr_leaves_application',
            'system_users',
            'staff_general',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('staff_id')->unique();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('Active');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('staff_id')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_leaves_application', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('staff_id');
            $table->string('status')->default('Pending');
            $table->integer('reviewed_by')->nullable();
            $table->string('reviewed_status')->nullable();
            $table->integer('approved_by')->nullable();
            $table->string('approved_status')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_leave_workflow_recipients', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('stage_key');
            $table->integer('staff_id');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('client_vendor_registrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('client_id');
            $table->date('valid_from');
            $table->date('valid_until');
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('client_vendor_registration_recipients', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('registration_id');
            $table->integer('staff_id');
            $table->timestamps();
        });

        foreach (['quotes_training', 'quotes_manpower'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->increments('id');
                $table->string('status')->nullable();
            });
        }

        Schema::create('quote_price_exception_requests', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('request_type')->default('quote');
            $table->string('service_group');
            $table->integer('quote_id');
            $table->string('status')->default('pending');
            $table->integer('requested_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('pending');
            $table->string('required_step');
            $table->boolean('is_current')->default(true);
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

        Schema::create('vendor_payments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('status')->default('Pending');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_payment_workflow_recipients', function (Blueprint $table): void {
            $table->id();
            $table->string('stage_type', 20);
            $table->unsignedTinyInteger('level_no')->default(1);
            $table->unsignedBigInteger('staff_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_exposes_user_targeted_vendor_registration_badges(): void
    {
        DB::table('client_vendor_registrations')->insert([
            [
                'id' => 1,
                'client_id' => 1,
                'valid_from' => '2025-01-01',
                'valid_until' => '2026-05-01',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'client_id' => 1,
                'valid_from' => '2025-01-01',
                'valid_until' => '2026-05-01',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'client_id' => 1,
                'valid_from' => '2026-01-01',
                'valid_until' => '2026-06-15',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ]);
        DB::table('client_vendor_registration_recipients')->insert([
            ['registration_id' => 1, 'staff_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['registration_id' => 2, 'staff_id' => 11, 'created_at' => now(), 'updated_at' => now()],
            ['registration_id' => 3, 'staff_id' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $summary = $this->withSession(['staff_id' => 10, 'roles' => ['Employee']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $summary['by_module']['client.vendor_registration'] ?? 0);
        $this->assertSame(1, $summary['by_route_group']['/client/manage'] ?? 0);
        $this->assertSame(1, $summary['by_tab']['client.vendor-registration'] ?? 0);
    }

    public function test_summary_exposes_only_quote_approvals_assigned_to_the_signed_in_approver(): void
    {
        DB::table('staff_general')->insert([
            ['staff_id' => 11, 'full_name' => 'Azlin', 'email' => 'azlin@amiosh.com'],
            ['staff_id' => 22, 'full_name' => 'Kamarul', 'email' => 'kamarul@amiosh.com'],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 11, 'email' => 'azlin@amiosh.com', 'role' => 'Manager'],
            ['staff_id' => 22, 'email' => 'kamarul@amiosh.com', 'role' => 'Manager'],
        ]);
        DB::table('quote_approval_requests')->insert([
            ['status' => 'pending', 'required_step' => 'hod', 'is_current' => true],
            ['status' => 'pending', 'required_step' => 'bd', 'is_current' => true],
            ['status' => 'approved', 'required_step' => 'hod', 'is_current' => true],
        ]);

        $hodSummary = $this->withSession(['staff_id' => 11, 'roles' => ['Manager']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');
        $bdSummary = $this->withSession(['staff_id' => 22, 'roles' => ['Manager']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');
        $unassignedSystemAdminSummary = $this->withSession(['staff_id' => 99, 'roles' => ['System Admin']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $hodSummary['by_module']['crm.quote-approvals'] ?? 0);
        $this->assertSame(1, $hodSummary['by_route_group']['/crm/records'] ?? 0);
        $this->assertSame(1, $bdSummary['by_module']['crm.quote-approvals'] ?? 0);
        $this->assertSame(2, $unassignedSystemAdminSummary['by_module']['crm.quote-approvals'] ?? 0);
    }

    public function test_summary_hides_quote_approval_badges_for_non_approvers_with_stored_notifications(): void
    {
        DB::table('staff_general')->insert([
            ['staff_id' => 55, 'full_name' => 'Regular User', 'email' => 'regular@example.com'],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 55, 'email' => 'regular@example.com', 'role' => 'Employee'],
        ]);

        DB::table('quote_approval_requests')->insert([
            ['status' => 'pending', 'required_step' => 'hod', 'is_current' => true],
        ]);

        DB::table('in_app_notifications')->insert([
            'recipient_staff_id' => 55,
            'actor_staff_id' => 11,
            'module_key' => 'crm.quote-approvals',
            'entity_type' => 'quote',
            'entity_id' => 700,
            'type' => 'quote.approval.pending',
            'title' => 'Quote 700 pending approval',
            'message' => 'Manual approval needed',
            'route' => '/crm/records/700',
            'severity' => 'warning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = $this->withSession(['staff_id' => 55, 'roles' => ['Employee']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('crm.quote-approvals', (array) $summary['by_module']);
        $this->assertArrayNotHasKey('/crm/records', (array) $summary['by_route_group']);
        $this->assertArrayNotHasKey('crm.quote-approvals', (array) $summary['by_tab']);
        $this->assertSame(1, $summary['listable_total'] ?? 0);
    }

    public function test_summary_preserves_negotiation_pending_and_ready_to_apply_badges(): void
    {
        DB::table('quotes_training')->insert([
            ['id' => 1, 'status' => 'Open'],
            ['id' => 2, 'status' => 'Pending'],
        ]);
        DB::table('quotes_manpower')->insert([
            ['id' => 1, 'status' => 'Open'],
        ]);
        DB::table('quote_price_exception_requests')->insert([
            [
                'request_type' => 'quote',
                'service_group' => 'training',
                'quote_id' => 1,
                'status' => 'pending',
                'requested_by_id' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'request_type' => 'quote',
                'service_group' => 'manpower',
                'quote_id' => 1,
                'status' => 'pending',
                'requested_by_id' => 11,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'request_type' => 'quote',
                'service_group' => 'training',
                'quote_id' => 2,
                'status' => 'approved',
                'requested_by_id' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $managerSummary = $this->withSession(['staff_id' => 30, 'roles' => ['Manager']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $managerSummary['by_module']['crm.negotiations'] ?? 0);
        $this->assertSame(2, $managerSummary['by_route_group']['/crm/price-exceptions'] ?? 0);

        $requesterSummary = $this->withSession(['staff_id' => 10, 'roles' => ['Employee']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $requesterSummary['by_module']['crm.negotiations'] ?? 0);
        $this->assertSame(1, $requesterSummary['by_route_group']['/crm/price-exceptions'] ?? 0);
        $this->assertSame(1, $requesterSummary['by_tab']['crm.negotiations'] ?? 0);
    }

    public function test_summary_derives_staff_leave_badges_from_actionable_workflow_rows(): void
    {
        DB::table('staff_general')->insert([
            ['staff_id' => 20, 'full_name' => 'HR User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 30, 'full_name' => 'Manager User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'full_name' => 'Employee User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 20, 'role' => 'HR', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 30, 'role' => 'Manager', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'role' => 'Employee', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('hr_leaves_application')->insert([
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => null, 'reviewed_status' => null, 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => null, 'reviewed_status' => null, 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => 20, 'reviewed_status' => 'Recommended', 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => 20, 'reviewed_status' => 'Recommended', 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'status' => 'Approved', 'reviewed_by' => 20, 'reviewed_status' => 'Recommended', 'approved_by' => 30, 'approved_status' => 'Approved', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $hrSummary = $this->withSession(['staff_id' => 20, 'roles' => ['HR']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $hrSummary['by_module']['staff.leaves'] ?? 0);
        $this->assertSame(2, $hrSummary['by_route_group']['/staff/leaves'] ?? 0);
        $this->assertSame(2, $hrSummary['by_tab']['staff.leaves'] ?? 0);

        $managerSummary = $this->withSession(['staff_id' => 30, 'roles' => ['Manager']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $managerSummary['by_module']['staff.leaves'] ?? 0);

        $employeeSummary = $this->withSession(['staff_id' => 40, 'roles' => ['Employee']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $employeeSummary['by_module']['staff.leaves'] ?? 0);
    }

    public function test_summary_derives_vendor_payment_badges_for_workflow_keyholders(): void
    {
        DB::table('staff_general')->insert([
            ['staff_id' => 20, 'full_name' => 'Manager User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 30, 'full_name' => 'System Admin User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'full_name' => 'Finance User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 50, 'full_name' => 'Configured Finance', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 20, 'role' => 'Manager', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 30, 'role' => 'System Admin', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'role' => 'Finance', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 50, 'role' => 'Employee', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('vendor_payment_workflow_recipients')->insert([
            ['stage_type' => 'finance', 'level_no' => 1, 'staff_id' => 50, 'sort_order' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('vendor_payments')->insert([
            ['status' => 'Pending', 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'Checked', 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'Approved', 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'Paid', 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $systemAdminSummary = $this->withSession(['staff_id' => 30, 'roles' => ['System Admin']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $systemAdminSummary['by_module']['vendor.payments'] ?? 0);
        $this->assertSame(3, $systemAdminSummary['by_route_group']['/vendor/payment-records'] ?? 0);
        $this->assertSame(3, $systemAdminSummary['by_tab']['vendor.payment-records'] ?? 0);

        $configuredFinanceSummary = $this->withSession(['staff_id' => 50, 'roles' => ['Employee']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $configuredFinanceSummary['by_module']['vendor.payments'] ?? 0);

        $fallbackFinanceSummary = $this->withSession(['staff_id' => 40, 'roles' => ['Finance']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $fallbackFinanceSummary['by_module']['vendor.payments'] ?? 0);
    }

    public function test_staff_leave_badge_ignores_stale_stored_notifications(): void
    {
        DB::table('staff_general')->insert([
            ['staff_id' => 20, 'full_name' => 'HR User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'full_name' => 'Employee User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 20, 'role' => 'HR', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'role' => 'Employee', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('hr_leaves_application')->insert([
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => 20, 'reviewed_status' => 'Recommended', 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach (range(1, 5) as $entityId) {
            DB::table('in_app_notifications')->insert([
                'recipient_staff_id' => 20,
                'actor_staff_id' => 30,
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => $entityId,
                'type' => 'leave.needs_approval',
                'title' => 'Leave request needs approval',
                'route' => "/staff/leaves/records/{$entityId}",
                'severity' => 'warning',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $summary = $this->withSession(['staff_id' => 20, 'roles' => ['HR']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $summary['by_module']['staff.leaves'] ?? 0);
        $this->assertSame(1, $summary['by_route_group']['/staff/leaves'] ?? 0);
        $this->assertSame(1, $summary['by_tab']['staff.leaves'] ?? 0);
    }

    public function test_configured_leave_workflow_recipients_control_leave_badge_visibility(): void
    {
        DB::table('staff_general')->insert([
            ['staff_id' => 30, 'full_name' => 'Fallback Manager', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 31, 'full_name' => 'Configured Approver', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 30, 'role' => 'Manager', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 31, 'role' => 'Employee', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('hr_leave_workflow_recipients')->insert([
            [
                'stage_key' => 'leave.recommended.approvers',
                'staff_id' => 31,
                'sort_order' => 0,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('hr_leaves_application')->insert([
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => 20, 'reviewed_status' => 'Recommended', 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $fallbackManagerSummary = $this->withSession(['staff_id' => 30, 'roles' => ['Manager']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $fallbackManagerSummary['by_module']['staff.leaves'] ?? 0);

        $configuredApproverSummary = $this->withSession(['staff_id' => 31, 'roles' => ['Employee']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $configuredApproverSummary['by_module']['staff.leaves'] ?? 0);
        $this->assertSame(1, $configuredApproverSummary['by_tab']['staff.leaves'] ?? 0);
    }

    public function test_summary_maps_applicant_leave_notifications_to_my_leave_badges(): void
    {
        DB::table('in_app_notifications')->insert([
            [
                'recipient_staff_id' => 40,
                'actor_staff_id' => 30,
                'module_key' => 'my.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => 1,
                'type' => 'leave.approved',
                'title' => 'Leave approved',
                'route' => '/my/leaves/records/1',
                'severity' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'recipient_staff_id' => 40,
                'actor_staff_id' => 30,
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => 2,
                'type' => 'leave.rejected',
                'title' => 'Leave rejected',
                'route' => '/my/leaves/records/2',
                'severity' => 'danger',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $summary = $this->withSession(['staff_id' => 40, 'roles' => ['Employee']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $summary['by_module']['my.leaves'] ?? 0);
        $this->assertSame(2, $summary['by_route_group']['/my/leaves'] ?? 0);
        $this->assertSame(2, $summary['by_tab']['my.leaves'] ?? 0);
        $this->assertSame(0, $summary['by_route_group']['/staff/leaves'] ?? 0);
    }

    public function test_route_scoped_consumption_preserves_staff_workflow_notifications(): void
    {
        DB::table('in_app_notifications')->insert([
            [
                'recipient_staff_id' => 40,
                'actor_staff_id' => 30,
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => 7,
                'type' => 'leave.approved',
                'title' => 'Legacy applicant leave approved',
                'route' => '/my/leaves/records/7',
                'severity' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'recipient_staff_id' => 40,
                'actor_staff_id' => 10,
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => 7,
                'type' => 'leave.needs_recommendation',
                'title' => 'Leave request needs recommendation',
                'route' => '/staff/leaves/records/7',
                'severity' => 'warning',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'recipient_staff_id' => 40,
                'actor_staff_id' => 30,
                'module_key' => 'my.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => 13,
                'type' => 'leave.approved',
                'title' => 'Archived leave approved',
                'route' => '/my/leaves-archive/records/13',
                'severity' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->withSession(['staff_id' => 40, 'roles' => ['HR']])
            ->postJson('/notifications/consume-entity', [
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => 7,
                'route_prefix' => '/my/leaves',
            ])
            ->assertOk()
            ->assertJsonPath('data.consumed_count', 1);

        $this->assertNotNull(
            DB::table('in_app_notifications')
                ->where('recipient_staff_id', 40)
                ->where('type', 'leave.approved')
                ->value('consumed_at'),
        );
        $this->assertNull(
            DB::table('in_app_notifications')
                ->where('recipient_staff_id', 40)
                ->where('type', 'leave.needs_recommendation')
                ->value('consumed_at'),
        );
    }

    public function test_route_group_consumption_marks_personal_leave_table_notifications(): void
    {
        DB::table('in_app_notifications')->insert([
            [
                'recipient_staff_id' => 40,
                'actor_staff_id' => 30,
                'module_key' => 'my.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => 10,
                'type' => 'leave.approved',
                'title' => 'Leave approved',
                'route' => '/my/leaves/records/10',
                'severity' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'recipient_staff_id' => 40,
                'actor_staff_id' => 30,
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => 11,
                'type' => 'leave.rejected',
                'title' => 'Legacy leave rejected',
                'route' => '/my/leaves/records/11',
                'severity' => 'danger',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'recipient_staff_id' => 40,
                'actor_staff_id' => 10,
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => 12,
                'type' => 'leave.needs_recommendation',
                'title' => 'Leave request needs recommendation',
                'route' => '/staff/leaves/records/12',
                'severity' => 'warning',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->withSession(['staff_id' => 40, 'roles' => ['HR']])
            ->postJson('/notifications/consume-route-group', [
                'route_prefix' => '/my/leaves',
                'module_keys' => ['my.leaves', 'staff.leaves'],
            ])
            ->assertOk()
            ->assertJsonPath('data.consumed_count', 2);

        $this->assertSame(
            2,
            DB::table('in_app_notifications')
                ->where('recipient_staff_id', 40)
                ->whereIn('route', ['/my/leaves/records/10', '/my/leaves/records/11'])
                ->whereNotNull('consumed_at')
                ->count(),
        );
        $this->assertNull(
            DB::table('in_app_notifications')
                ->where('recipient_staff_id', 40)
                ->where('route', 'like', '/staff/leaves%')
                ->value('consumed_at'),
        );
        $this->assertNull(
            DB::table('in_app_notifications')
                ->where('recipient_staff_id', 40)
                ->where('route', 'like', '/my/leaves-archive%')
                ->value('consumed_at'),
        );
    }

    public function test_route_group_consumption_requires_module_keys(): void
    {
        $this->withSession(['staff_id' => 40, 'roles' => ['Employee']])
            ->postJson('/notifications/consume-route-group', [
                'route_prefix' => '/my/leaves',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('module_keys');
    }

    public function test_notifications_list_returns_only_active_rows_for_the_signed_in_user(): void
    {
        // Phase D5 step 1: list surfaces stored content, user-scoped, active only.
        // Active row for user 40.
        DB::table('in_app_notifications')->insert([
            'recipient_staff_id' => 40,
            'actor_staff_id' => 30,
            'module_key' => 'my.leaves',
            'entity_type' => 'leave_application',
            'entity_id' => 1,
            'type' => 'leave.approved',
            'title' => 'Leave approved',
            'message' => 'Your leave request has been approved.',
            'route' => '/my/leaves/records/1',
            'severity' => 'success',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Consumed row for user 40 (must be excluded).
        DB::table('in_app_notifications')->insert([
            'recipient_staff_id' => 40,
            'module_key' => 'my.leaves',
            'entity_type' => 'leave_application',
            'entity_id' => 2,
            'type' => 'leave.rejected',
            'title' => 'Leave rejected',
            'route' => '/my/leaves/records/2',
            'severity' => 'danger',
            'consumed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Active row belonging to a DIFFERENT user (must not leak).
        DB::table('in_app_notifications')->insert([
            'recipient_staff_id' => 99,
            'module_key' => 'staff.leaves',
            'entity_type' => 'leave_application',
            'entity_id' => 3,
            'type' => 'leave.needs_approval',
            'title' => 'Leave request needs approval',
            'route' => '/staff/leaves/records/3',
            'severity' => 'warning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = $this->withSession(['staff_id' => 40, 'roles' => ['Employee']])
            ->getJson('/notifications/list')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['total']);
        $this->assertCount(1, $data['items']);
        $this->assertSame('Leave approved', $data['items'][0]['title']);
        $this->assertSame('Your leave request has been approved.', $data['items'][0]['message']);
        $this->assertSame('/my/leaves/records/1', $data['items'][0]['route']);
        $this->assertSame('success', $data['items'][0]['severity']);
    }

    public function test_notifications_list_paginates_and_clamps_limit(): void
    {
        foreach (range(1, 5) as $entityId) {
            DB::table('in_app_notifications')->insert([
                'recipient_staff_id' => 40,
                'module_key' => 'my.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => $entityId,
                'type' => 'leave.approved',
                'title' => "Notice {$entityId}",
                'route' => "/my/leaves/records/{$entityId}",
                'severity' => 'success',
                'created_at' => now()->addSeconds($entityId),
                'updated_at' => now(),
            ]);
        }

        $data = $this->withSession(['staff_id' => 40, 'roles' => ['Employee']])
            ->getJson('/notifications/list?limit=2&offset=0')
            ->assertOk()
            ->json('data');

        $this->assertSame(5, $data['total']);
        $this->assertCount(2, $data['items']);
        $this->assertSame(2, $data['limit']);
        // Newest first (entity 5 created last).
        $this->assertSame('Notice 5', $data['items'][0]['title']);

        // Over-large limit is rejected by validation.
        $this->withSession(['staff_id' => 40, 'roles' => ['Employee']])
            ->getJson('/notifications/list?limit=500')
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');
    }

    public function test_leave_badge_and_creation_resolve_identical_configured_recipients(): void
    {
        // Regression guard for Phase 1 (M1): the badge count's configured-recipient
        // resolver must return the same staff IDs as the recipient service used by
        // leave-notification creation and permission checks, for one fixture.
        DB::table('staff_general')->insert([
            ['staff_id' => 31, 'full_name' => 'Configured Approver', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 32, 'full_name' => 'Second Approver', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 33, 'full_name' => 'Inactive Approver', 'status' => 'Inactive', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 31, 'role' => 'Employee', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 32, 'role' => 'Employee', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 33, 'role' => 'Employee', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('hr_leave_workflow_recipients')->insert([
            ['stage_key' => LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS, 'staff_id' => 31, 'sort_order' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['stage_key' => LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS, 'staff_id' => 32, 'sort_order' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            // Inactive staff must be excluded by both paths.
            ['stage_key' => LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS, 'staff_id' => 33, 'sort_order' => 2, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $service = app(LeaveWorkflowRecipientService::class);

        // Count path (read-only, no role fallback).
        $countIds = $service->configuredStageStaffIds(
            LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS,
        );
        // Creation path: when configured recipients exist, the fallback roles are
        // never consulted, so this yields the configured set only.
        $creationIds = $service->stageStaffIds(
            LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS,
            ['HR', 'System Admin'],
        );

        sort($countIds);
        sort($creationIds);

        $this->assertSame([31, 32], $countIds);
        $this->assertSame($creationIds, $countIds);
    }

    public function test_stored_rows_do_not_inflate_negotiation_recompute(): void
    {
        // Phase 2 (M4): crm.negotiations reconciles via MAX(stored, recompute),
        // so stray stored rows can never be added on top of the live recompute.
        DB::table('quotes_training')->insert([
            ['id' => 1, 'status' => 'Open'],
        ]);
        DB::table('quotes_manpower')->insert([
            ['id' => 1, 'status' => 'Open'],
        ]);
        DB::table('quote_price_exception_requests')->insert([
            [
                'request_type' => 'quote',
                'service_group' => 'training',
                'quote_id' => 1,
                'status' => 'pending',
                'requested_by_id' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'request_type' => 'quote',
                'service_group' => 'manpower',
                'quote_id' => 1,
                'status' => 'pending',
                'requested_by_id' => 11,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Stray stored notification rows for the same module/recipient. The OLD
        // additive merge would have produced recompute(2) + stored(1) = 3.
        DB::table('in_app_notifications')->insert([
            'recipient_staff_id' => 30,
            'actor_staff_id' => 10,
            'module_key' => 'crm.negotiations',
            'entity_type' => 'quote_price_exception',
            'entity_id' => 1,
            'type' => 'negotiation.pending',
            'title' => 'Negotiation pending',
            'route' => '/crm/price-exceptions/1',
            'severity' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = $this->withSession(['staff_id' => 30, 'roles' => ['Manager']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        // MAX(stored=1, recompute=2) = 2 — not inflated to 3.
        $this->assertSame(2, $summary['by_module']['crm.negotiations'] ?? 0);
        $this->assertSame(2, $summary['by_route_group']['/crm/price-exceptions'] ?? 0);
        $this->assertSame(2, $summary['by_tab']['crm.negotiations'] ?? 0);
    }

    public function test_staff_leaves_parity_logs_when_stored_and_recompute_diverge(): void
    {
        // Phase D1: recompute is authoritative (1 actionable leave for HR) but
        // there are 5 stale stored rows -> divergence must be logged, while the
        // returned badge value stays on the recompute (unchanged behavior).
        DB::table('staff_general')->insert([
            ['staff_id' => 20, 'full_name' => 'HR User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 20, 'role' => 'HR', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('hr_leaves_application')->insert([
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => 20, 'reviewed_status' => 'Recommended', 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach (range(1, 5) as $entityId) {
            DB::table('in_app_notifications')->insert([
                'recipient_staff_id' => 20,
                'actor_staff_id' => 30,
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => $entityId,
                'type' => 'leave.needs_approval',
                'title' => 'Leave request needs approval',
                'route' => "/staff/leaves/records/{$entityId}",
                'severity' => 'warning',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Log::spy();

        $summary = $this->withSession(['staff_id' => 20, 'roles' => ['HR']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        // Behavior unchanged: recompute (1) still wins.
        $this->assertSame(1, $summary['by_module']['staff.leaves'] ?? 0);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'notif.parity.staff_leaves'
                    && ($context['recompute'] ?? null) === 1
                    && ($context['stored'] ?? null) === 5
                    && ($context['delta'] ?? null) === -4;
            })
            ->once();
    }

    public function test_staff_leaves_parity_silent_when_counts_agree(): void
    {
        // Employee with no actionable leaves and no stored rows: recompute=0,
        // stored=0 -> no parity divergence -> nothing logged.
        DB::table('staff_general')->insert([
            ['staff_id' => 40, 'full_name' => 'Employee User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 40, 'role' => 'Employee', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Log::spy();

        $this->withSession(['staff_id' => 40, 'roles' => ['Employee']])
            ->getJson('/notifications/summary')
            ->assertOk();

        Log::shouldNotHaveReceived('info', ['notif.parity.staff_leaves']);
    }

    public function test_staff_leaves_badge_uses_stored_rows_when_flag_is_stored(): void
    {
        // Phase D3: with the authority flag flipped to 'stored', the badge counts
        // stored notification rows instead of the live recompute. Same fixture as
        // the D1 parity test (recompute=1, stored=5) but the asserted badge flips
        // from 1 -> 5, proving the flag changes the source of truth.
        config(['leave.notification_badge_source' => 'stored']);

        DB::table('staff_general')->insert([
            ['staff_id' => 20, 'full_name' => 'HR User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 20, 'role' => 'HR', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('hr_leaves_application')->insert([
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => 20, 'reviewed_status' => 'Recommended', 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach (range(1, 5) as $entityId) {
            DB::table('in_app_notifications')->insert([
                'recipient_staff_id' => 20,
                'actor_staff_id' => 30,
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => $entityId,
                'type' => 'leave.needs_approval',
                'title' => 'Leave request needs approval',
                'route' => "/staff/leaves/records/{$entityId}",
                'severity' => 'warning',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $summary = $this->withSession(['staff_id' => 20, 'roles' => ['HR']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        // Stored authority: badge = 5 stored rows (not the recompute's 1).
        $this->assertSame(5, $summary['by_module']['staff.leaves'] ?? 0);
        $this->assertSame(5, $summary['by_route_group']['/staff/leaves'] ?? 0);
        $this->assertSame(5, $summary['by_tab']['staff.leaves'] ?? 0);
    }

    public function test_staff_leaves_badge_defaults_to_recompute_when_flag_absent(): void
    {
        // Guard: default (no flag set) keeps recompute authoritative. Same fixture,
        // badge stays 1 — confirms the flip is opt-in and behavior-neutral.
        DB::table('staff_general')->insert([
            ['staff_id' => 20, 'full_name' => 'HR User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 20, 'role' => 'HR', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('hr_leaves_application')->insert([
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => 20, 'reviewed_status' => 'Recommended', 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach (range(1, 5) as $entityId) {
            DB::table('in_app_notifications')->insert([
                'recipient_staff_id' => 20,
                'actor_staff_id' => 30,
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => $entityId,
                'type' => 'leave.needs_approval',
                'title' => 'Leave request needs approval',
                'route' => "/staff/leaves/records/{$entityId}",
                'severity' => 'warning',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $summary = $this->withSession(['staff_id' => 20, 'roles' => ['HR']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $summary['by_module']['staff.leaves'] ?? 0);
    }

    public function test_hybrid_clearing_action_clears_on_resolve_fyi_clears_on_view(): void
    {
        // Phase D4 (H2): ACTION items clear on workflow RESOLVE (not on view);
        // FYI items clear on VIEW (consume). One fixture exercises both rules.
        $action = DB::table('in_app_notifications')->insertGetId([
            'recipient_staff_id' => 20,
            'actor_staff_id' => 10,
            'module_key' => 'staff.leaves',
            'entity_type' => 'leave_application',
            'entity_id' => 1,
            'type' => 'leave.needs_approval',          // ACTION
            'title' => 'Leave request needs approval',
            'route' => '/staff/leaves/records/1',
            'severity' => 'warning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fyi = DB::table('in_app_notifications')->insertGetId([
            'recipient_staff_id' => 20,
            'actor_staff_id' => 10,
            'module_key' => 'my.leaves',
            'entity_type' => 'leave_application',
            'entity_id' => 2,
            'type' => 'leave.approved',                // FYI
            'title' => 'Leave approved',
            'route' => '/my/leaves/records/2',
            'severity' => 'success',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sanity: the fixture types are classified as expected.
        $this->assertTrue(LeaveNotificationType::isAction('leave.needs_approval'));
        $this->assertTrue(LeaveNotificationType::isFyi('leave.approved'));

        // --- VIEW (consume the /my/leaves page) ------------------------------
        $this->withSession(['staff_id' => 20, 'roles' => ['HR']])
            ->postJson('/notifications/consume-route-group', [
                'route_prefix' => '/my/leaves',
                'module_keys' => ['my.leaves', 'staff.leaves'],
            ])
            ->assertOk();

        // FYI cleared on view; ACTION untouched (its /staff/leaves route is out
        // of the viewed scope).
        $this->assertNotNull(DB::table('in_app_notifications')->where('id', $fyi)->value('consumed_at'));
        $this->assertNull(DB::table('in_app_notifications')->where('id', $action)->value('consumed_at'));
        $this->assertNull(DB::table('in_app_notifications')->where('id', $action)->value('resolved_at'));

        // --- RESOLVE (workflow transition) -----------------------------------
        app(AppNotificationService::class)->resolveActive(
            'staff.leaves',
            'leave_application',
            1,
            LeaveNotificationType::ACTION,
        );

        // ACTION now cleared by resolve.
        $this->assertNotNull(DB::table('in_app_notifications')->where('id', $action)->value('resolved_at'));
    }

    public function test_summary_merges_multiple_modules_and_reports_total(): void
    {
        DB::table('staff_general')->insert([
            ['staff_id' => 30, 'full_name' => 'System Admin User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'full_name' => 'Employee User', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 30, 'role' => 'System Admin', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'role' => 'Employee', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('hr_leaves_application')->insert([
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => null, 'reviewed_status' => null, 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => null, 'reviewed_status' => null, 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => 20, 'reviewed_status' => 'Recommended', 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => 20, 'reviewed_status' => 'Recommended', 'approved_by' => null, 'approved_status' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('vendor_payments')->insert([
            ['status' => 'Pending', 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'Checked', 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'Approved', 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'Paid', 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $summary = $this->withSession(['staff_id' => 30, 'roles' => ['System Admin']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        // System Admin is recommender + approver: 2 unreviewed + 2 recommended.
        $this->assertSame(4, $summary['by_module']['staff.leaves'] ?? 0);
        // System Admin sees Pending + Checked + Approved (Paid excluded).
        $this->assertSame(3, $summary['by_module']['vendor.payments'] ?? 0);

        $this->assertSame(4, $summary['by_route_group']['/staff/leaves'] ?? 0);
        $this->assertSame(3, $summary['by_route_group']['/vendor/payment-records'] ?? 0);
        $this->assertSame(4, $summary['by_tab']['staff.leaves'] ?? 0);
        $this->assertSame(3, $summary['by_tab']['vendor.payment-records'] ?? 0);

        // Modules with no actionable rows for this user must not appear.
        $this->assertArrayNotHasKey('crm.negotiations', (array) $summary['by_module']);
        $this->assertArrayNotHasKey('client.vendor_registration', (array) $summary['by_module']);
        $this->assertArrayNotHasKey('my.leaves', (array) $summary['by_module']);

        // total is the sum across modules.
        $this->assertSame(7, $summary['total'] ?? 0);
    }
}
