<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReconcileLeaveNotificationsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-31 09:00:00');

        foreach (['in_app_notifications', 'hr_leave_workflow_recipients', 'hr_leaves_application', 'system_users', 'staff_general'] as $table) {
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
            $table->string('type')->default('Annual');
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

        // Fallback-role recipients: manager (30) recommends, HR (20) approves.
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function activeRows(string $type, int $entityId): array
    {
        return DB::table('in_app_notifications')
            ->where('module_key', 'staff.leaves')
            ->where('type', $type)
            ->where('entity_id', $entityId)
            ->whereNull('consumed_at')
            ->whereNull('resolved_at')
            ->pluck('recipient_staff_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    public function test_reconcile_creates_missing_rows_for_in_stage_leaves(): void
    {
        // A submitted-pending leave and a recommended-pending leave, both with
        // NO stored notifications -> reconcile must create them.
        $submitted = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => 40, 'status' => 'Pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $recommended = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => 40, 'status' => 'Pending', 'reviewed_by' => 30, 'reviewed_status' => 'Recommended',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $exit = Artisan::call('notifications:reconcile-leaves');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"created":2', Artisan::output());

        // Recommender (30) gets the needs_recommendation row.
        $this->assertSame([30], $this->activeRows('leave.needs_recommendation', $submitted));
        // Approver (20) gets the needs_approval row.
        $this->assertSame([20], $this->activeRows('leave.needs_approval', $recommended));
    }

    public function test_reconcile_resolves_rows_whose_leave_left_the_stage(): void
    {
        // Leave already approved (no longer actionable) but a stale active
        // needs_approval row lingers -> reconcile must resolve it.
        $leaveId = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => 40, 'status' => 'Approved', 'reviewed_by' => 30, 'reviewed_status' => 'Recommended',
            'approved_by' => 20, 'approved_status' => 'Approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('in_app_notifications')->insert([
            'recipient_staff_id' => 20,
            'module_key' => 'staff.leaves',
            'entity_type' => 'leave_application',
            'entity_id' => $leaveId,
            'type' => 'leave.needs_approval',
            'title' => 'Leave request needs approval',
            'severity' => 'warning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('notifications:reconcile-leaves');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"resolved":1', Artisan::output());
        $this->assertSame([], $this->activeRows('leave.needs_approval', $leaveId));
        $this->assertNotNull(
            DB::table('in_app_notifications')->where('entity_id', $leaveId)->value('resolved_at'),
        );
    }

    public function test_reconcile_dry_run_writes_nothing(): void
    {
        $submitted = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => 40, 'status' => 'Pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $exit = Artisan::call('notifications:reconcile-leaves', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"created":1', $output);
        $this->assertStringContainsString('"dryRun":true', $output);
        $this->assertSame(0, DB::table('in_app_notifications')->count());
    }

    public function test_reconcile_is_idempotent_on_rerun(): void
    {
        DB::table('hr_leaves_application')->insert([
            'staff_id' => 40, 'status' => 'Pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('notifications:reconcile-leaves');
        $afterFirst = DB::table('in_app_notifications')->count();

        Artisan::call('notifications:reconcile-leaves');
        $output = Artisan::output();

        $this->assertStringContainsString('"created":0', $output);
        $this->assertStringContainsString('"resolved":0', $output);
        $this->assertSame($afterFirst, DB::table('in_app_notifications')->count());
    }
}
