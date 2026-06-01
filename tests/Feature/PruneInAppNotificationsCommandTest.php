<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PruneInAppNotificationsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-31 09:00:00');

        Schema::dropIfExists('in_app_notifications');
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

    private function seedRow(array $overrides): int
    {
        return (int) DB::table('in_app_notifications')->insertGetId(array_merge([
            'recipient_staff_id' => 10,
            'actor_staff_id' => 20,
            'module_key' => 'staff.leaves',
            'entity_type' => 'leave_application',
            'entity_id' => 1,
            'type' => 'leave.needs_approval',
            'title' => 'Leave request needs approval',
            'severity' => 'warning',
            'read_at' => null,
            'consumed_at' => null,
            'resolved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_prune_removes_only_old_resolved_or_consumed_rows(): void
    {
        $old = Carbon::now()->subDays(120);
        $recent = Carbon::now()->subDays(10);

        $oldConsumed = $this->seedRow(['entity_id' => 1, 'consumed_at' => $old, 'updated_at' => $old]);
        $oldResolved = $this->seedRow(['entity_id' => 2, 'resolved_at' => $old, 'updated_at' => $old]);
        $oldActive = $this->seedRow(['entity_id' => 3, 'updated_at' => $old]); // still active
        $recentConsumed = $this->seedRow(['entity_id' => 4, 'consumed_at' => $recent, 'updated_at' => $recent]);

        $exitCode = Artisan::call('notifications:prune');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Pruned 2 in-app notification(s)', Artisan::output());

        // Old + resolved/consumed → deleted.
        $this->assertDatabaseMissing('in_app_notifications', ['id' => $oldConsumed]);
        $this->assertDatabaseMissing('in_app_notifications', ['id' => $oldResolved]);

        // Old but still active → kept.
        $this->assertDatabaseHas('in_app_notifications', ['id' => $oldActive]);
        // Recent consumed (inside window) → kept.
        $this->assertDatabaseHas('in_app_notifications', ['id' => $recentConsumed]);
    }

    public function test_prune_dry_run_reports_without_deleting(): void
    {
        $old = Carbon::now()->subDays(120);
        $id = $this->seedRow(['consumed_at' => $old, 'updated_at' => $old]);

        $exitCode = Artisan::call('notifications:prune', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[dry-run] Would delete 1', Artisan::output());
        $this->assertDatabaseHas('in_app_notifications', ['id' => $id]);
    }

    public function test_prune_respects_custom_days_option(): void
    {
        $thirtyDaysOld = Carbon::now()->subDays(30);
        $id = $this->seedRow(['consumed_at' => $thirtyDaysOld, 'updated_at' => $thirtyDaysOld]);

        // Default 90-day window keeps it.
        Artisan::call('notifications:prune');
        $this->assertDatabaseHas('in_app_notifications', ['id' => $id]);

        // A 15-day window prunes it.
        $exitCode = Artisan::call('notifications:prune', ['--days' => 15]);
        $this->assertSame(0, $exitCode);
        $this->assertDatabaseMissing('in_app_notifications', ['id' => $id]);
    }
}
