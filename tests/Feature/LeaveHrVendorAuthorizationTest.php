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
            'user_activities',
            'vendor_payments',
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

    public function test_leave_application_reports_notification_send_result(): void
    {
        $this->actingSession($this->employeeSession())
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

        $this->assertDatabaseHas('hr_leaves_application', [
            'staff_id' => 10,
            'type' => 'Annual',
            'reason' => 'Family matters',
            'status' => 'Pending',
        ]);
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
