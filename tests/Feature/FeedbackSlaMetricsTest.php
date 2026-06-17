<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FeedbackSlaMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-17 10:30:00'));

        foreach (['system_feedbacks', 'staff_general', 'system_users', 'user_activities'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_feedbacks', function (Blueprint $table): void {
            $table->id();
            $table->text('feedback');
            $table->unsignedInteger('reported_by')->nullable();
            $table->string('status')->default('Pending');
            $table->string('resolution_track', 50)->default('Needs Triage');
            $table->timestamp('date_reported')->nullable();
            $table->date('action_date')->nullable();
            $table->timestamp('fixed_at')->nullable();
            $table->text('remarks')->nullable();
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->unsignedInteger('staff_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('staff_id');
            $table->string('email')->nullable();
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('staff_general')->insert([
            'staff_id' => 10,
            'full_name' => 'Admin User',
            'name_code' => 'ADM',
        ]);

        DB::table('system_users')->insert([
            'id' => 10,
            'staff_id' => 10,
            'email' => 'admin@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_monthly_metrics_group_by_reported_month_and_use_fixed_completed_only(): void
    {
        DB::table('system_feedbacks')->insert([
            [
                'feedback' => 'May ticket fixed in June within SLA',
                'reported_by' => 1,
                'status' => 'Fixed Completed',
                'resolution_track' => '30-Day Fix',
                'date_reported' => '2026-05-16 09:00:00',
                'action_date' => '2026-06-13',
                'fixed_at' => '2026-06-13 00:00:00',
                'remarks' => null,
            ],
            [
                'feedback' => 'Pending push is not completed',
                'reported_by' => 1,
                'status' => 'Fixed Pending Pushed',
                'resolution_track' => '30-Day Fix',
                'date_reported' => '2026-05-20 09:00:00',
                'action_date' => '2026-05-25',
                'fixed_at' => '2026-05-25 00:00:00',
                'remarks' => null,
            ],
            [
                'feedback' => 'Old pending ticket missed SLA',
                'reported_by' => 1,
                'status' => 'Pending',
                'resolution_track' => '30-Day Fix',
                'date_reported' => '2026-04-01 09:00:00',
                'action_date' => null,
                'fixed_at' => null,
                'remarks' => null,
            ],
            [
                'feedback' => 'Late fixed completed ticket',
                'reported_by' => 1,
                'status' => 'Fixed Completed',
                'resolution_track' => 'Next Upgrade',
                'date_reported' => '2026-04-10 09:00:00',
                'action_date' => '2026-05-20',
                'fixed_at' => '2026-05-20 00:00:00',
                'remarks' => 'Deferred to a larger release.',
            ],
            [
                'feedback' => 'Current open ticket still has time',
                'reported_by' => 1,
                'status' => 'Pending',
                'resolution_track' => '30-Day Fix',
                'date_reported' => '2026-06-10 09:00:00',
                'action_date' => null,
                'fixed_at' => null,
                'remarks' => null,
            ],
            [
                'feedback' => 'May non-actionable ticket',
                'reported_by' => 1,
                'status' => 'Resolved',
                'resolution_track' => 'Not Actionable',
                'date_reported' => '2026-05-18 09:00:00',
                'action_date' => '2026-05-19',
                'fixed_at' => null,
                'remarks' => 'Duplicate request.',
            ],
        ]);

        $response = $this->actingAdminSession()
            ->getJson('/feedback/metrics/monthly?year=2026')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('completed_status', 'Fixed Completed');

        $months = collect($response->json('months'))->keyBy('month');

        $this->assertSame(3, $months['2026-05']['reported_count']);
        $this->assertSame(2, $months['2026-05']['sla_track_count']);
        $this->assertSame(1, $months['2026-05']['eligible_count']);
        $this->assertSame(1, $months['2026-05']['completed_count']);
        $this->assertSame(1, $months['2026-05']['fixed_under_30_count']);
        $this->assertSame(0, $months['2026-05']['missed_30_count']);
        $this->assertSame(1, $months['2026-05']['open_within_window_count']);
        $this->assertSame(1, $months['2026-05']['not_actionable_count']);
        $this->assertSame(1, $months['2026-05']['excluded_count']);
        $this->assertEquals(100.0, $months['2026-05']['sla_percent']);
        $this->assertFalse($months['2026-05']['is_final']);

        $this->assertSame(2, $months['2026-04']['reported_count']);
        $this->assertSame(1, $months['2026-04']['sla_track_count']);
        $this->assertSame(1, $months['2026-04']['eligible_count']);
        $this->assertSame(0, $months['2026-04']['completed_count']);
        $this->assertSame(0, $months['2026-04']['fixed_under_30_count']);
        $this->assertSame(1, $months['2026-04']['missed_30_count']);
        $this->assertSame(0, $months['2026-04']['open_within_window_count']);
        $this->assertSame(1, $months['2026-04']['next_upgrade_count']);
        $this->assertSame(1, $months['2026-04']['excluded_count']);
        $this->assertEquals(0.0, $months['2026-04']['sla_percent']);
        $this->assertTrue($months['2026-04']['is_final']);

        $this->assertSame(1, $months['2026-06']['reported_count']);
        $this->assertSame(1, $months['2026-06']['sla_track_count']);
        $this->assertSame(0, $months['2026-06']['eligible_count']);
        $this->assertSame(1, $months['2026-06']['open_within_window_count']);
        $this->assertNull($months['2026-06']['sla_percent']);
    }

    public function test_admin_status_update_maintains_fixed_at(): void
    {
        $id = DB::table('system_feedbacks')->insertGetId([
            'feedback' => 'Needs admin work',
            'reported_by' => 1,
            'status' => 'Pending',
            'resolution_track' => 'Needs Triage',
            'date_reported' => '2026-06-01 09:00:00',
            'action_date' => null,
            'fixed_at' => null,
        ]);

        $this->actingAdminSession()
            ->putJson("/feedback/{$id}", [
                'status' => 'Fixed Completed',
                'action_date' => '2026-06-17',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('system_feedbacks', [
            'id' => $id,
            'status' => 'Fixed Completed',
        ]);
        $this->assertSame(
            '2026-06-17 00:00:00',
            DB::table('system_feedbacks')->where('id', $id)->value('fixed_at'),
        );

        $this->actingAdminSession()
            ->putJson("/feedback/{$id}", [
                'status' => 'Fixed Completed',
                'action_date' => '2026-06-15',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(
            '2026-06-15 00:00:00',
            DB::table('system_feedbacks')->where('id', $id)->value('fixed_at'),
        );

        $this->actingAdminSession()
            ->putJson("/feedback/{$id}", [
                'action_date' => '2026-06-14',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(
            '2026-06-14 00:00:00',
            DB::table('system_feedbacks')->where('id', $id)->value('fixed_at'),
        );

        $this->actingAdminSession()
            ->putJson("/feedback/{$id}", [
                'status' => 'In Progress',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('system_feedbacks', [
            'id' => $id,
            'status' => 'In Progress',
            'fixed_at' => null,
        ]);
    }

    public function test_new_feedback_defaults_to_needs_triage(): void
    {
        $this->actingAdminSession()
            ->postJson('/feedback', ['feedback' => 'New issue'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('system_feedbacks', [
            'feedback' => 'New issue',
            'resolution_track' => 'Needs Triage',
        ]);
    }

    public function test_admin_can_update_resolution_track_and_owner_cannot(): void
    {
        $id = DB::table('system_feedbacks')->insertGetId([
            'feedback' => 'Track me',
            'reported_by' => 22,
            'status' => 'Pending',
            'resolution_track' => 'Needs Triage',
            'date_reported' => '2026-06-01 09:00:00',
        ]);

        $this->actingAdminSession()
            ->putJson("/feedback/{$id}", [
                'resolution_track' => '30-Day Fix',
                'remarks' => 'Suitable for the 30-day queue.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('system_feedbacks', [
            'id' => $id,
            'resolution_track' => '30-Day Fix',
        ]);

        DB::table('staff_general')->insert([
            'staff_id' => 22,
            'full_name' => 'Owner User',
            'name_code' => 'OWN',
        ]);
        DB::table('system_users')->insert([
            'id' => 22,
            'staff_id' => 22,
            'email' => 'owner@example.test',
            'role' => json_encode([]),
            'is_active' => 1,
        ]);

        $this->actingOwnerSession(22)
            ->putJson("/feedback/{$id}", [
                'feedback' => 'Owner edit only',
                'resolution_track' => 'Rejected',
                'remarks' => 'Trying to classify.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('system_feedbacks', [
            'id' => $id,
            'feedback' => 'Owner edit only',
            'resolution_track' => '30-Day Fix',
        ]);
    }

    public function test_final_non_sla_resolution_tracks_require_remarks(): void
    {
        $id = DB::table('system_feedbacks')->insertGetId([
            'feedback' => 'Needs classification',
            'reported_by' => 1,
            'status' => 'Pending',
            'resolution_track' => 'Needs Triage',
            'date_reported' => '2026-06-01 09:00:00',
        ]);

        $this->actingAdminSession()
            ->putJson("/feedback/{$id}", [
                'resolution_track' => 'Next Upgrade',
                'remarks' => '',
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->actingAdminSession()
            ->putJson("/feedback/{$id}", [
                'resolution_track' => 'Next Upgrade',
                'remarks' => 'Requires a larger release.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_existing_final_non_sla_track_without_remarks_does_not_block_other_admin_updates(): void
    {
        $id = DB::table('system_feedbacks')->insertGetId([
            'feedback' => 'Backfilled late fix',
            'reported_by' => 1,
            'status' => 'Fixed Completed',
            'resolution_track' => 'Next Upgrade',
            'date_reported' => '2026-04-01 09:00:00',
            'action_date' => '2026-05-10',
            'fixed_at' => '2026-05-10 00:00:00',
            'remarks' => null,
        ]);

        $this->actingAdminSession()
            ->putJson("/feedback/{$id}", [
                'status' => 'Resolved',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('system_feedbacks', [
            'id' => $id,
            'status' => 'Resolved',
            'resolution_track' => 'Next Upgrade',
            'remarks' => null,
            'fixed_at' => null,
        ]);
    }

    public function test_resolved_status_clears_fixed_at_without_counting_as_fixed(): void
    {
        $id = DB::table('system_feedbacks')->insertGetId([
            'feedback' => 'Close without fix',
            'reported_by' => 1,
            'status' => 'Fixed Completed',
            'resolution_track' => '30-Day Fix',
            'date_reported' => '2026-05-01 09:00:00',
            'action_date' => '2026-05-10',
            'fixed_at' => '2026-05-10 00:00:00',
        ]);

        $this->actingAdminSession()
            ->putJson("/feedback/{$id}", [
                'status' => 'Resolved',
                'resolution_track' => 'Not Actionable',
                'remarks' => 'Closed after review.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('system_feedbacks', [
            'id' => $id,
            'status' => 'Resolved',
            'resolution_track' => 'Not Actionable',
            'fixed_at' => null,
        ]);
    }

    public function test_resolution_track_migration_backfills_historical_records(): void
    {
        Schema::dropIfExists('system_feedbacks');
        Schema::create('system_feedbacks', function (Blueprint $table): void {
            $table->id();
            $table->text('feedback');
            $table->unsignedInteger('reported_by')->nullable();
            $table->string('status')->default('Pending');
            $table->timestamp('date_reported')->nullable();
            $table->date('action_date')->nullable();
            $table->timestamp('fixed_at')->nullable();
            $table->text('remarks')->nullable();
        });

        DB::table('system_feedbacks')->insert([
            [
                'id' => 1,
                'feedback' => 'On time',
                'status' => 'Fixed Completed',
                'date_reported' => '2026-05-01 09:00:00',
                'fixed_at' => '2026-05-20 00:00:00',
            ],
            [
                'id' => 2,
                'feedback' => 'Late',
                'status' => 'Fixed Completed',
                'date_reported' => '2026-05-01 09:00:00',
                'fixed_at' => '2026-06-10 00:00:00',
            ],
            [
                'id' => 3,
                'feedback' => 'Still open',
                'status' => 'Pending',
                'date_reported' => '2026-05-01 09:00:00',
                'fixed_at' => null,
            ],
        ]);

        $migration = include database_path('migrations/2026_06_17_110000_add_resolution_track_to_system_feedbacks.php');
        $migration->up();

        $this->assertSame('30-Day Fix', DB::table('system_feedbacks')->where('id', 1)->value('resolution_track'));
        $this->assertSame('Next Upgrade', DB::table('system_feedbacks')->where('id', 2)->value('resolution_track'));
        $this->assertSame('Needs Triage', DB::table('system_feedbacks')->where('id', 3)->value('resolution_track'));
    }

    private function actingAdminSession(): self
    {
        return $this
            ->withSession([
                '_token' => 'test-token',
                'user_id' => 10,
                'staff_id' => 10,
                'name_code' => 'ADM',
                'full_name' => 'Admin User',
                'roles' => ['System Admin'],
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }

    private function actingOwnerSession(int $staffId): self
    {
        return $this
            ->withSession([
                '_token' => 'test-token',
                'user_id' => $staffId,
                'staff_id' => $staffId,
                'name_code' => 'OWN',
                'full_name' => 'Owner User',
                'roles' => [],
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }
}
