<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            'quote_price_exception_requests',
            'quotes_training',
            'quotes_manpower',
            'client_vendor_registration_recipients',
            'client_vendor_registrations',
            'in_app_notifications',
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
}
