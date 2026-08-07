<?php

namespace Tests\Feature;

use App\Jobs\SendHtmlMailJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FeedbackWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        foreach (['system_feedback_history', 'system_feedbacks', 'in_app_notifications', 'staff_general', 'system_users', 'user_activities'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_feedbacks', function (Blueprint $table): void {
            $table->id();
            $table->text('feedback');
            $table->unsignedBigInteger('reported_by');
            $table->string('status')->default('Pending');
            $table->string('resolution_track')->default('Needs Triage');
            $table->timestamp('date_reported')->useCurrent();
            $table->date('action_date')->nullable();
            $table->timestamp('fixed_at')->nullable();
            $table->timestamp('verification_requested_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('remarks')->nullable();
        });

        Schema::create('system_feedback_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('feedback_id')->index();
            $table->string('event_type');
            $table->unsignedBigInteger('actor_staff_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->text('message')->nullable();
            $table->string('status_from')->nullable();
            $table->string('status_to')->nullable();
            $table->string('resolution_track_from')->nullable();
            $table->string('resolution_track_to')->nullable();
            $table->json('changes_json')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->unique();
            $table->string('full_name');
            $table->string('name_code');
            $table->string('email');
            $table->string('status')->default('Active');
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('in_app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('recipient_staff_id');
            $table->unsignedBigInteger('actor_staff_id')->nullable();
            $table->string('module_key');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('type');
            $table->string('dedupe_key')->nullable();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('route')->nullable();
            $table->string('severity')->default('info');
            $table->json('metadata_json')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['recipient_staff_id', 'dedupe_key']);
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('name_code');
            $table->string('action');
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        foreach ([
            [10, 'Admin User', 'ADM', 'admin@example.test', ['System Admin']],
            [22, 'Reporter User', 'REP', 'reporter@example.test', []],
            [33, 'Other User', 'OTH', 'other@example.test', []],
        ] as [$staffId, $name, $code, $email, $roles]) {
            DB::table('staff_general')->insert([
                'staff_id' => $staffId,
                'full_name' => $name,
                'name_code' => $code,
                'email' => $email,
                'status' => 'Active',
            ]);
            DB::table('system_users')->insert([
                'id' => $staffId,
                'staff_id' => $staffId,
                'email' => $email,
                'role' => json_encode($roles),
                'is_active' => true,
            ]);
        }
    }

    public function test_submission_creates_history_and_admin_notification(): void
    {
        $response = $this->actingAsStaff(22)
            ->postJson('/feedback', ['feedback' => 'Export is empty'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $feedbackId = (int) $response->json('feedback_id');
        $this->assertDatabaseHas('system_feedback_history', [
            'feedback_id' => $feedbackId,
            'event_type' => 'report_received',
            'actor_staff_id' => 22,
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 10,
            'module_key' => 'support.feedback',
            'entity_id' => $feedbackId,
            'type' => 'feedback.report.received',
        ]);
        Queue::assertPushed(SendHtmlMailJob::class);
    }

    public function test_submission_uses_the_exact_server_time_for_the_report_and_history(): void
    {
        $submittedAt = CarbonImmutable::create(2026, 8, 6, 14, 37, 52, 'Asia/Kuala_Lumpur');
        CarbonImmutable::setTestNow($submittedAt);

        try {
            $response = $this->actingAsStaff(22)
                ->postJson('/feedback', ['feedback' => 'Timestamp regression'])
                ->assertOk();
        } finally {
            CarbonImmutable::setTestNow();
        }

        $feedbackId = (int) $response->json('feedback_id');
        $reportedAt = DB::table('system_feedbacks')->where('id', $feedbackId)->value('date_reported');
        $historyAt = DB::table('system_feedback_history')
            ->where('feedback_id', $feedbackId)
            ->where('event_type', 'report_received')
            ->value('created_at');

        $this->assertSame('2026-08-06 14:37:52', (string) $reportedAt);
        $this->assertSame((string) $reportedAt, (string) $historyAt);
    }

    public function test_admin_updates_append_without_overwriting_previous_history(): void
    {
        $feedbackId = $this->feedback();

        $this->actingAsStaff(10, ['System Admin'])
            ->putJson("/feedback/{$feedbackId}", [
                'resolution_track' => '30-Day Fix',
                'remarks' => 'Accepted for current sprint.',
            ])
            ->assertOk();

        $this->actingAsStaff(10, ['System Admin'])
            ->putJson("/feedback/{$feedbackId}", ['status' => 'In Progress'])
            ->assertOk();

        $events = DB::table('system_feedback_history')
            ->where('feedback_id', $feedbackId)
            ->orderBy('id')
            ->get();
        $this->assertCount(3, $events);
        $this->assertSame('report_received', $events[0]->event_type);
        $this->assertSame('developer_updated', $events[1]->event_type);
        $this->assertSame('developer_updated', $events[2]->event_type);
        $this->assertSame('Needs Triage', $events[1]->resolution_track_from);
        $this->assertSame('30-Day Fix', $events[1]->resolution_track_to);
    }

    public function test_reporter_can_reject_then_confirm_a_later_fix(): void
    {
        $feedbackId = $this->feedback();

        $this->markFixed($feedbackId, '2026-08-05');

        $this->actingAsStaff(22)
            ->postJson("/feedback/{$feedbackId}/verification", [
                'decision' => 'reject',
                'message' => 'The issue still occurs on mobile.',
            ])
            ->assertOk()
            ->assertJsonPath('feedback.status', 'In Progress');

        $this->assertDatabaseHas('system_feedbacks', [
            'id' => $feedbackId,
            'status' => 'In Progress',
            'fixed_at' => null,
        ]);
        $this->assertDatabaseHas('system_feedback_history', [
            'feedback_id' => $feedbackId,
            'event_type' => 'fix_rejected',
            'message' => 'The issue still occurs on mobile.',
        ]);

        $this->markFixed($feedbackId, '2026-08-06');
        $this->actingAsStaff(22)
            ->postJson("/feedback/{$feedbackId}/verification", ['decision' => 'confirm'])
            ->assertOk()
            ->assertJsonPath('feedback.status', 'Resolved')
            ->assertJsonPath('permissions.can_verify', false);

        $this->assertDatabaseHas('system_feedbacks', [
            'id' => $feedbackId,
            'status' => 'Resolved',
            'resolved_by' => 22,
        ]);
        $this->assertNotNull(DB::table('system_feedbacks')->where('id', $feedbackId)->value('fixed_at'));
    }

    public function test_admin_cannot_resolve_and_non_owner_cannot_verify(): void
    {
        $feedbackId = $this->feedback();
        $this->markFixed($feedbackId, '2026-08-05');

        $this->actingAsStaff(10, ['System Admin'])
            ->putJson("/feedback/{$feedbackId}", ['status' => 'Resolved'])
            ->assertStatus(422);

        $this->actingAsStaff(33)
            ->postJson("/feedback/{$feedbackId}/verification", ['decision' => 'confirm'])
            ->assertForbidden();

        $this->assertSame('Fixed Completed', DB::table('system_feedbacks')->where('id', $feedbackId)->value('status'));
    }

    public function test_comments_are_participant_only_and_do_not_change_status(): void
    {
        $feedbackId = $this->feedback();

        $this->actingAsStaff(22)
            ->postJson("/feedback/{$feedbackId}/comments", ['message' => 'Extra reproduction detail'])
            ->assertOk();
        $this->actingAsStaff(33)
            ->postJson("/feedback/{$feedbackId}/comments", ['message' => 'Not my ticket'])
            ->assertForbidden();

        $this->assertDatabaseHas('system_feedback_history', [
            'feedback_id' => $feedbackId,
            'event_type' => 'comment_added',
            'message' => 'Extra reproduction detail',
        ]);
        $this->assertSame('Pending', DB::table('system_feedbacks')->where('id', $feedbackId)->value('status'));
    }

    public function test_detail_is_direct_and_delete_is_blocked(): void
    {
        $feedbackId = $this->feedback('2025-01-02 09:00:00');

        $this->actingAsStaff(22)
            ->getJson("/feedback/{$feedbackId}")
            ->assertOk()
            ->assertJsonPath('feedback.id', $feedbackId)
            ->assertJsonPath('history.0.event_type', 'report_received')
            ->assertJsonPath('permissions.can_edit', true);

        $this->actingAsStaff(22)
            ->deleteJson("/feedback/{$feedbackId}")
            ->assertStatus(409);
        $this->assertDatabaseHas('system_feedbacks', ['id' => $feedbackId]);
    }

    public function test_history_migration_backfills_received_and_legacy_snapshot_events(): void
    {
        Schema::dropIfExists('system_feedback_history');
        DB::table('system_feedbacks')->insert([
            'feedback' => 'Historical fixed issue',
            'reported_by' => 22,
            'status' => 'Fixed Completed',
            'resolution_track' => '30-Day Fix',
            'date_reported' => '2026-01-01 09:00:00',
            'action_date' => '2026-01-10',
            'fixed_at' => '2026-01-10 00:00:00',
            'remarks' => 'Historical state',
        ]);

        $migration = include database_path('migrations/2026_08_06_000000_create_system_feedback_history.php');
        $migration->up();

        $this->assertDatabaseCount('system_feedback_history', 2);
        $this->assertDatabaseHas('system_feedback_history', ['event_type' => 'report_received']);
        $this->assertDatabaseHas('system_feedback_history', ['event_type' => 'legacy_state_imported']);
    }

    private function feedback(string $reportedAt = '2026-08-01 09:00:00'): int
    {
        $id = (int) DB::table('system_feedbacks')->insertGetId([
            'feedback' => 'Original report',
            'reported_by' => 22,
            'status' => 'Pending',
            'resolution_track' => 'Needs Triage',
            'date_reported' => $reportedAt,
        ]);
        DB::table('system_feedback_history')->insert([
            'feedback_id' => $id,
            'event_type' => 'report_received',
            'actor_staff_id' => 22,
            'actor_name' => 'REP',
            'status_to' => 'Pending',
            'resolution_track_to' => 'Needs Triage',
            'created_at' => $reportedAt,
        ]);

        return $id;
    }

    private function markFixed(int $feedbackId, string $date): void
    {
        $this->actingAsStaff(10, ['System Admin'])
            ->putJson("/feedback/{$feedbackId}", [
                'status' => 'Fixed Completed',
                'action_date' => $date,
                'remarks' => 'Fix deployed.',
            ])
            ->assertOk();
    }

    private function actingAsStaff(int $staffId, array $roles = []): self
    {
        $staff = DB::table('staff_general')->where('staff_id', $staffId)->first();

        return $this
            ->withSession([
                '_token' => 'test-token',
                'user_id' => $staffId,
                'staff_id' => $staffId,
                'name_code' => $staff->name_code,
                'full_name' => $staff->full_name,
                'roles' => $roles,
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }
}
