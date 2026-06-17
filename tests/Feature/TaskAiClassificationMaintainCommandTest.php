<?php

namespace Tests\Feature;

use App\Jobs\EnrichTaskClassificationWithAiJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskAiClassificationMaintainCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('tasks');
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('task_category')->default('uncategorised');
            $table->decimal('effort_score', 4, 1)->default(1);
            $table->string('classification_confidence')->nullable();
            $table->string('classification_source')->default('system');
            $table->boolean('user_override')->default(false);
            $table->string('matched_pattern')->nullable();
            $table->string('work_type')->default('unclear');
            $table->string('work_type_confidence')->nullable();
            $table->string('work_type_matched_pattern')->nullable();
            $table->string('ai_classification_status')->nullable();
            $table->timestamp('ai_classification_queued_at')->nullable();
            $table->timestamp('ai_classification_started_at')->nullable();
            $table->timestamp('ai_classification_completed_at')->nullable();
            $table->string('ai_classification_error')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
        ]);
    }

    public function test_dry_run_reports_candidates_without_updating_or_dispatching(): void
    {
        Queue::fake();
        $this->insertTask([
            'id' => 1,
            'ai_classification_status' => 'queued',
            'ai_classification_queued_at' => now()->subMinutes(20),
        ]);

        $exitCode = Artisan::call('tasks:ai-classification-maintain', [
            '--dry-run' => true,
            '--requeue' => true,
            '--older-than' => 10,
            '--limit' => 50,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            'scanned' => 1,
            'requeued' => 1,
            'markedNoResult' => 0,
            'skipped' => 0,
            'dryRun' => true,
        ], json_decode(trim(Artisan::output()), true));
        $this->assertDatabaseHas('tasks', [
            'id' => 1,
            'ai_classification_status' => 'queued',
            'ai_classification_started_at' => null,
        ]);
        Queue::assertNotPushed(EnrichTaskClassificationWithAiJob::class);
    }

    public function test_requeue_recovers_old_queued_processing_and_legacy_null_rows(): void
    {
        Queue::fake();
        $this->insertTask([
            'id' => 1,
            'ai_classification_status' => 'queued',
            'ai_classification_queued_at' => now()->subMinutes(20),
            'ai_classification_error' => 'old error',
        ]);
        $this->insertTask([
            'id' => 2,
            'ai_classification_status' => 'processing',
            'ai_classification_started_at' => now()->subMinutes(20),
        ]);
        $this->insertTask([
            'id' => 3,
            'ai_classification_status' => null,
            'created_at' => now()->subMinutes(20),
        ]);

        $exitCode = Artisan::call('tasks:ai-classification-maintain', [
            '--requeue' => true,
            '--older-than' => 10,
            '--limit' => 50,
        ]);

        $this->assertSame(0, $exitCode);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(3, $summary['requeued']);
        $this->assertSame(0, $summary['markedNoResult']);
        $this->assertSame(0, $summary['skipped']);

        foreach ([1, 2, 3] as $id) {
            $this->assertDatabaseHas('tasks', [
                'id' => $id,
                'ai_classification_status' => 'queued',
                'ai_classification_started_at' => null,
                'ai_classification_completed_at' => null,
                'ai_classification_error' => null,
            ]);
        }

        Queue::assertPushed(EnrichTaskClassificationWithAiJob::class, 3);
    }

    public function test_requeue_skips_recent_rows_and_user_overrides(): void
    {
        Queue::fake();
        $this->insertTask([
            'id' => 1,
            'ai_classification_status' => 'queued',
            'ai_classification_queued_at' => now()->subMinutes(2),
        ]);
        $this->insertTask([
            'id' => 2,
            'ai_classification_status' => 'processing',
            'ai_classification_started_at' => now()->subMinutes(20),
            'user_override' => true,
        ]);

        Artisan::call('tasks:ai-classification-maintain', [
            '--requeue' => true,
            '--older-than' => 10,
            '--limit' => 50,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $summary['scanned']);
        $this->assertSame(0, $summary['requeued']);
        $this->assertSame(0, $summary['skipped']);
        Queue::assertNotPushed(EnrichTaskClassificationWithAiJob::class);
    }

    public function test_recent_low_id_rows_do_not_consume_limit_before_old_recoverable_rows(): void
    {
        Queue::fake();
        $this->insertTask([
            'id' => 1,
            'ai_classification_status' => 'queued',
            'ai_classification_queued_at' => now()->subMinutes(2),
        ]);
        $this->insertTask([
            'id' => 2,
            'ai_classification_status' => 'processing',
            'ai_classification_started_at' => now()->subMinutes(2),
        ]);
        $this->insertTask([
            'id' => 3,
            'ai_classification_status' => 'queued',
            'ai_classification_queued_at' => now()->subMinutes(20),
        ]);
        $this->insertTask([
            'id' => 4,
            'ai_classification_status' => 'processing',
            'ai_classification_started_at' => now()->subMinutes(20),
        ]);

        Artisan::call('tasks:ai-classification-maintain', [
            '--requeue' => true,
            '--older-than' => 10,
            '--limit' => 2,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(2, $summary['scanned']);
        $this->assertSame(2, $summary['requeued']);
        Queue::assertPushed(EnrichTaskClassificationWithAiJob::class, 2);
        Queue::assertPushed(
            EnrichTaskClassificationWithAiJob::class,
            fn (EnrichTaskClassificationWithAiJob $job): bool => in_array($job->taskId(), [3, 4], true),
        );
    }

    public function test_second_pass_skips_do_not_consume_recoverable_limit(): void
    {
        Queue::fake();
        config(['services.workload_ai_classification.enabled' => false]);

        $this->insertTask([
            'id' => 1,
            'ai_classification_status' => null,
            'task_category' => 'unclear_unrated',
            'classification_confidence' => 'low',
            'work_type' => 'unclear',
            'created_at' => now()->subMinutes(20),
        ]);
        $this->insertTask([
            'id' => 2,
            'ai_classification_status' => 'queued',
            'ai_classification_queued_at' => now()->subMinutes(20),
        ]);

        Artisan::call('tasks:ai-classification-maintain', [
            '--requeue' => true,
            '--older-than' => 10,
            '--limit' => 1,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(2, $summary['scanned']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(1, $summary['requeued']);
        Queue::assertPushed(
            EnrichTaskClassificationWithAiJob::class,
            fn (EnrichTaskClassificationWithAiJob $job): bool => $job->taskId() === 2,
        );
    }

    public function test_legacy_null_lifecycle_sql_eligibility_only_requeues_weak_rows(): void
    {
        Queue::fake();
        $this->insertTask([
            'id' => 1,
            'ai_classification_status' => null,
            'task_category' => 'unclear_unrated',
            'classification_confidence' => 'low',
            'classification_source' => 'system',
            'work_type' => 'unclear',
            'created_at' => now()->subMinutes(20),
        ]);
        $this->insertTask([
            'id' => 2,
            'ai_classification_status' => null,
            'task_category' => 'real_effort',
            'classification_confidence' => 'high',
            'classification_source' => 'system',
            'work_type' => 'software_it',
            'created_at' => now()->subMinutes(20),
        ]);
        $this->insertTask([
            'id' => 3,
            'ai_classification_status' => null,
            'classification_source' => 'ai',
            'created_at' => now()->subMinutes(20),
        ]);
        $this->insertTask([
            'id' => 4,
            'ai_classification_status' => null,
            'classification_source' => 'ai_cache',
            'created_at' => now()->subMinutes(20),
        ]);
        $this->insertTask([
            'id' => 5,
            'ai_classification_status' => null,
            'task_category' => 'non_work',
            'classification_confidence' => 'high',
            'classification_source' => 'system',
            'work_type' => 'non_work',
            'created_at' => now()->subMinutes(20),
        ]);

        Artisan::call('tasks:ai-classification-maintain', [
            '--requeue' => true,
            '--older-than' => 10,
            '--limit' => 50,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $summary['scanned']);
        $this->assertSame(1, $summary['requeued']);
        Queue::assertPushed(
            EnrichTaskClassificationWithAiJob::class,
            fn (EnrichTaskClassificationWithAiJob $job): bool => $job->taskId() === 1,
        );
        foreach ([2, 3, 4, 5] as $id) {
            $this->assertDatabaseHas('tasks', [
                'id' => $id,
                'ai_classification_status' => null,
            ]);
        }
    }

    public function test_legacy_null_lifecycle_sql_mirrors_backend_defaults(): void
    {
        Queue::fake();
        $this->insertTask([
            'id' => 1,
            'ai_classification_status' => null,
            'task_category' => 'uncategorised',
            'classification_confidence' => null,
            'classification_source' => 'system',
            'work_type' => 'software_it',
            'created_at' => now()->subMinutes(20),
        ]);
        $this->insertTask([
            'id' => 2,
            'ai_classification_status' => null,
            'task_category' => 'real_effort',
            'classification_confidence' => 'high',
            'classification_source' => 'system',
            'work_type' => 'legacy_unknown',
            'created_at' => now()->subMinutes(20),
        ]);
        $this->insertTask([
            'id' => 3,
            'ai_classification_status' => null,
            'task_category' => 'real_effort',
            'classification_confidence' => 'high',
            'classification_source' => 'system',
            'work_type' => 'software_it',
            'created_at' => now()->subMinutes(20),
        ]);

        Artisan::call('tasks:ai-classification-maintain', [
            '--requeue' => true,
            '--older-than' => 10,
            '--limit' => 50,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(2, $summary['scanned']);
        $this->assertSame(2, $summary['requeued']);
        Queue::assertPushed(
            EnrichTaskClassificationWithAiJob::class,
            fn (EnrichTaskClassificationWithAiJob $job): bool => in_array($job->taskId(), [1, 2], true),
        );
        $this->assertDatabaseHas('tasks', [
            'id' => 3,
            'ai_classification_status' => null,
        ]);
    }

    public function test_terminal_and_failed_rows_are_never_touched(): void
    {
        Queue::fake();
        foreach (['applied', 'cached', 'no_result', 'failed', 'not_applicable'] as $index => $status) {
            $this->insertTask([
                'id' => $index + 1,
                'ai_classification_status' => $status,
                'created_at' => now()->subDays(10),
            ]);
        }

        Artisan::call('tasks:ai-classification-maintain', [
            '--mark-no-result' => true,
            '--older-than' => 10,
            '--limit' => 50,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $summary['scanned']);
        $this->assertSame(0, $summary['markedNoResult']);
        Queue::assertNotPushed(EnrichTaskClassificationWithAiJob::class);
    }

    public function test_ambiguous_mode_fails_without_changes(): void
    {
        Queue::fake();
        $this->insertTask([
            'id' => 1,
            'ai_classification_status' => 'queued',
            'ai_classification_queued_at' => now()->subMinutes(20),
        ]);

        $exitCode = Artisan::call('tasks:ai-classification-maintain', [
            '--requeue' => true,
            '--mark-no-result' => true,
            '--older-than' => 10,
            '--limit' => 50,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Choose either --requeue or --mark-no-result, not both.',
            Artisan::output(),
        );
        $this->assertDatabaseHas('tasks', [
            'id' => 1,
            'ai_classification_status' => 'queued',
            'ai_classification_completed_at' => null,
        ]);
        Queue::assertNotPushed(EnrichTaskClassificationWithAiJob::class);
    }

    public function test_neither_mode_defaults_to_requeue(): void
    {
        Queue::fake();
        $this->insertTask([
            'id' => 1,
            'ai_classification_status' => 'queued',
            'ai_classification_queued_at' => now()->subMinutes(20),
        ]);

        Artisan::call('tasks:ai-classification-maintain', [
            '--older-than' => 10,
            '--limit' => 50,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $summary['requeued']);
        Queue::assertPushed(EnrichTaskClassificationWithAiJob::class, 1);
    }

    public function test_missing_timestamps_are_skipped_by_candidate_query(): void
    {
        Queue::fake();
        $this->insertTask([
            'id' => 1,
            'ai_classification_status' => 'queued',
            'ai_classification_queued_at' => null,
            'created_at' => null,
        ]);

        Artisan::call('tasks:ai-classification-maintain', [
            '--requeue' => true,
            '--older-than' => 10,
            '--limit' => 50,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $summary['scanned']);
        $this->assertSame(0, $summary['requeued']);
        Queue::assertNotPushed(EnrichTaskClassificationWithAiJob::class);
    }

    public function test_invalid_timestamps_are_not_recovered(): void
    {
        Queue::fake();
        $this->insertTask([
            'id' => 1,
            'ai_classification_status' => 'queued',
            'ai_classification_queued_at' => '0000-00-00 00:00:00',
            'created_at' => null,
        ]);
        $this->insertTask([
            'id' => 2,
            'ai_classification_status' => 'queued',
            'ai_classification_queued_at' => now()->subMinutes(20),
        ]);

        Artisan::call('tasks:ai-classification-maintain', [
            '--requeue' => true,
            '--older-than' => 10,
            '--limit' => 1,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(2, $summary['scanned']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(1, $summary['requeued']);
        $this->assertDatabaseHas('tasks', [
            'id' => 1,
            'ai_classification_status' => 'queued',
            'ai_classification_queued_at' => '0000-00-00 00:00:00',
        ]);
        Queue::assertPushed(
            EnrichTaskClassificationWithAiJob::class,
            fn (EnrichTaskClassificationWithAiJob $job): bool => $job->taskId() === 2,
        );
    }

    public function test_mark_no_result_marks_old_unresolved_rows_terminal(): void
    {
        Queue::fake();
        $this->insertTask([
            'id' => 1,
            'ai_classification_status' => 'stale',
            'ai_classification_started_at' => now()->subMinutes(1500),
            'ai_classification_error' => 'stale marker',
        ]);

        $exitCode = Artisan::call('tasks:ai-classification-maintain', [
            '--mark-no-result' => true,
            '--older-than' => 1440,
            '--limit' => 50,
        ]);

        $this->assertSame(0, $exitCode);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $summary['markedNoResult']);
        $this->assertDatabaseHas('tasks', [
            'id' => 1,
            'ai_classification_status' => 'no_result',
            'ai_classification_error' => null,
        ]);
        $this->assertNotNull(DB::table('tasks')->where('id', 1)->value('ai_classification_completed_at'));
        Queue::assertNotPushed(EnrichTaskClassificationWithAiJob::class);
    }

    public function test_limit_caps_scanned_rows(): void
    {
        Queue::fake();
        foreach ([1, 2, 3] as $id) {
            $this->insertTask([
                'id' => $id,
                'ai_classification_status' => 'queued',
                'ai_classification_queued_at' => now()->subMinutes(20),
            ]);
        }

        Artisan::call('tasks:ai-classification-maintain', [
            '--requeue' => true,
            '--older-than' => 10,
            '--limit' => 2,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(2, $summary['scanned']);
        $this->assertSame(2, $summary['requeued']);
        Queue::assertPushed(EnrichTaskClassificationWithAiJob::class, 2);
    }

    public function test_schedule_registers_recovery_commands(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString(
            'tasks:ai-classification-maintain --requeue --older-than=10 --limit=50',
            $source,
        );
        $this->assertStringContainsString(
            'tasks:ai-classification-maintain --mark-no-result --older-than=1440 --limit=500',
            $source,
        );
    }

    private function insertTask(array $overrides = []): void
    {
        DB::table('tasks')->insert(array_merge([
            'id' => null,
            'title' => 'random unknown noun',
            'task_category' => 'unclear_unrated',
            'effort_score' => 0,
            'classification_confidence' => 'low',
            'classification_source' => 'system',
            'user_override' => false,
            'matched_pattern' => 'unclear:no_work_signal',
            'work_type' => 'unclear',
            'work_type_confidence' => 'low',
            'work_type_matched_pattern' => 'unclear:no_work_signal',
            'ai_classification_status' => null,
            'ai_classification_queued_at' => null,
            'ai_classification_started_at' => null,
            'ai_classification_completed_at' => null,
            'ai_classification_error' => null,
            'created_at' => now()->subMinutes(20),
        ], $overrides));
    }
}
