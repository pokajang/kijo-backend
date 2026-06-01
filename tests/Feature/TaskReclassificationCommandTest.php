<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskReclassificationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('tasks');
        Schema::create('tasks', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('task_category')->nullable();
            $table->decimal('effort_score', 4, 1)->nullable();
            $table->string('classification_confidence')->nullable();
            $table->string('classification_source')->nullable();
            $table->boolean('user_override')->nullable();
            $table->string('matched_pattern')->nullable();
            $table->string('work_type')->nullable();
            $table->string('work_type_confidence')->nullable();
            $table->string('work_type_matched_pattern')->nullable();
        });

        Schema::dropIfExists('task_classification_examples');
        Schema::create('task_classification_examples', function (Blueprint $table): void {
            $table->id();
            $table->string('normalized_title_hash', 64)->unique();
            $table->string('normalized_title', 255);
            $table->string('sample_title', 255);
            $table->string('task_category');
            $table->decimal('effort_score', 4, 1);
            $table->string('classification_confidence');
            $table->string('classification_source')->default('ai');
            $table->string('matched_pattern')->nullable();
            $table->string('work_type')->default('unclear');
            $table->string('work_type_confidence')->nullable();
            $table->string('work_type_matched_pattern')->nullable();
            $table->unsignedInteger('usage_count')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_tasks_reclassify_dry_run_reports_changes_without_updating_rows(): void
    {
        DB::table('tasks')->insert([
            'title' => 'Create training module',
            'task_category' => 'uncategorised',
            'effort_score' => 1,
            'classification_confidence' => 'low',
            'classification_source' => 'system',
            'user_override' => false,
            'matched_pattern' => null,
            'work_type' => 'unclear',
            'work_type_confidence' => 'low',
            'work_type_matched_pattern' => null,
        ]);

        $exitCode = Artisan::call('tasks:reclassify', [
            '--dry-run' => true,
            '--limit' => 100,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"scanned":1', $output);
        $this->assertStringContainsString('"changed":1', $output);
        $this->assertStringContainsString('"dryRun":true', $output);
        $this->assertDatabaseHas('tasks', [
            'title' => 'Create training module',
            'task_category' => 'uncategorised',
            'effort_score' => 1,
            'classification_confidence' => 'low',
            'matched_pattern' => null,
            'work_type' => 'unclear',
        ]);
    }

    public function test_tasks_reclassify_updates_low_confidence_tasks_and_skips_user_overrides(): void
    {
        DB::table('tasks')->insert([
            [
                'title' => 'Create training module',
                'task_category' => 'uncategorised',
                'effort_score' => 1,
                'classification_confidence' => 'low',
                'classification_source' => 'system',
                'user_override' => false,
                'matched_pattern' => null,
                'work_type' => 'unclear',
                'work_type_confidence' => 'low',
                'work_type_matched_pattern' => null,
            ],
            [
                'title' => 'Server down',
                'task_category' => 'uncategorised',
                'effort_score' => 1,
                'classification_confidence' => 'low',
                'classification_source' => 'system',
                'user_override' => true,
                'matched_pattern' => null,
                'work_type' => 'unclear',
                'work_type_confidence' => 'low',
                'work_type_matched_pattern' => null,
            ],
        ]);

        $exitCode = Artisan::call('tasks:reclassify', [
            '--limit' => 100,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"scanned":1', $output);
        $this->assertStringContainsString('"changed":1', $output);
        $this->assertDatabaseHas('tasks', [
            'title' => 'Create training module',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_confidence' => 'high',
            'classification_source' => 'system',
            'user_override' => false,
            'matched_pattern' => 'rule:real_effort',
            'work_type' => 'training_delivery',
            'work_type_confidence' => 'high',
        ]);
        $this->assertDatabaseHas('tasks', [
            'title' => 'Server down',
            'task_category' => 'uncategorised',
            'effort_score' => 1,
            'user_override' => true,
            'matched_pattern' => null,
        ]);
    }

    public function test_tasks_reclassify_ai_dry_run_reports_ai_activity_without_updating_rows(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
            'services.workload_ai_classification.model' => 'gpt-4o-mini',
        ]);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'task_category' => 'real_effort',
                    'effort_score' => 3,
                    'work_type' => 'commercial_sales',
                    'confidence' => 'high',
                    'reason' => 'proposal preparation work',
                ]),
            ]),
        ]);

        DB::table('tasks')->insert([
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
        ]);

        $exitCode = Artisan::call('tasks:reclassify', [
            '--dry-run' => true,
            '--ai' => true,
            '--limit' => 100,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"ai_attempted":1', $output);
        $this->assertStringContainsString('"ai_applied":1', $output);
        $this->assertStringContainsString('"ai_failed":0', $output);
        $this->assertDatabaseHas('tasks', [
            'title' => 'random unknown noun',
            'task_category' => 'unclear_unrated',
            'effort_score' => 0,
            'classification_source' => 'system',
        ]);
    }

    public function test_tasks_reclassify_ai_updates_unclear_rows_when_response_is_valid(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
            'services.workload_ai_classification.model' => 'gpt-4o-mini',
        ]);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [
                    [
                        'content' => [
                            [
                                'text' => json_encode([
                                    'task_category' => 'real_effort',
                                    'effort_score' => 3,
                                    'work_type' => 'commercial_sales',
                                    'confidence' => 'medium',
                                    'reason' => 'commercial workbook preparation',
                                ]),
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        DB::table('tasks')->insert([
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
        ]);

        $exitCode = Artisan::call('tasks:reclassify', [
            '--ai' => true,
            '--limit' => 100,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"ai_applied":1', Artisan::output());
        $this->assertDatabaseHas('tasks', [
            'title' => 'random unknown noun',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_confidence' => 'medium',
            'classification_source' => 'ai',
            'matched_pattern' => 'ai:commercial workbook preparation',
            'work_type' => 'commercial_sales',
            'work_type_confidence' => 'medium',
            'work_type_matched_pattern' => 'ai:commercial workbook preparation',
        ]);
        $this->assertDatabaseHas('task_classification_examples', [
            'normalized_title' => 'random unknown noun',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_source' => 'ai',
            'work_type' => 'commercial_sales',
            'usage_count' => 1,
        ]);
    }

    public function test_tasks_reclassify_reuses_learned_ai_classification_without_ai_option(): void
    {
        DB::table('task_classification_examples')->insert([
            'normalized_title_hash' => hash('sha256', 'random unknown noun'),
            'normalized_title' => 'random unknown noun',
            'sample_title' => 'random unknown noun',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_confidence' => 'medium',
            'classification_source' => 'ai',
            'matched_pattern' => 'ai:previously learned classification',
            'work_type' => 'commercial_sales',
            'work_type_confidence' => 'medium',
            'work_type_matched_pattern' => 'ai:previously learned classification',
            'usage_count' => 1,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tasks')->insert([
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
        ]);

        $exitCode = Artisan::call('tasks:reclassify', [
            '--limit' => 100,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"ai_attempted":0', $output);
        $this->assertStringContainsString('"learned_used":1', $output);
        $this->assertDatabaseHas('tasks', [
            'title' => 'random unknown noun',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_confidence' => 'medium',
            'classification_source' => 'ai_cache',
            'matched_pattern' => 'ai:previously learned classification',
            'work_type' => 'commercial_sales',
        ]);
    }

    public function test_tasks_reclassify_ai_option_does_not_resubmit_learned_cache_classification(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
            'services.workload_ai_classification.model' => 'gpt-4o-mini',
        ]);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'should not call']], 500),
        ]);

        DB::table('task_classification_examples')->insert([
            'normalized_title_hash' => hash('sha256', 'random unknown noun'),
            'normalized_title' => 'random unknown noun',
            'sample_title' => 'random unknown noun',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_confidence' => 'medium',
            'classification_source' => 'ai',
            'matched_pattern' => 'ai:previously learned classification',
            'work_type' => 'commercial_sales',
            'work_type_confidence' => 'medium',
            'work_type_matched_pattern' => 'ai:previously learned classification',
            'usage_count' => 1,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tasks')->insert([
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
        ]);

        $exitCode = Artisan::call('tasks:reclassify', [
            '--ai' => true,
            '--limit' => 100,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"ai_attempted":0', Artisan::output());
        Http::assertNothingSent();
        $this->assertDatabaseHas('tasks', [
            'title' => 'random unknown noun',
            'classification_source' => 'ai_cache',
        ]);
    }

    public function test_tasks_reclassify_tolerates_missing_learned_classification_table(): void
    {
        Schema::dropIfExists('task_classification_examples');

        DB::table('tasks')->insert([
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
        ]);

        $exitCode = Artisan::call('tasks:reclassify', [
            '--dry-run' => true,
            '--limit' => 100,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"scanned":1', $output);
        $this->assertStringContainsString('"learned_used":0', $output);
    }

    public function test_tasks_reclassify_ai_failure_keeps_local_classification(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
            'services.workload_ai_classification.model' => 'gpt-4o-mini',
        ]);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'rate limited']], 429),
        ]);

        DB::table('tasks')->insert([
            'title' => 'random unknown noun',
            'task_category' => 'uncategorised',
            'effort_score' => 1,
            'classification_confidence' => 'low',
            'classification_source' => 'system',
            'user_override' => false,
            'matched_pattern' => null,
            'work_type' => 'unclear',
            'work_type_confidence' => 'low',
            'work_type_matched_pattern' => null,
        ]);

        $exitCode = Artisan::call('tasks:reclassify', [
            '--ai' => true,
            '--limit' => 100,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"ai_attempted":1', $output);
        $this->assertStringContainsString('"ai_applied":0', $output);
        $this->assertStringContainsString('"ai_failed":1', $output);
        $this->assertDatabaseHas('tasks', [
            'title' => 'random unknown noun',
            'task_category' => 'unclear_unrated',
            'effort_score' => 0,
            'classification_source' => 'system',
            'matched_pattern' => 'unclear:no_work_signal',
            'work_type' => 'unclear',
        ]);
    }

    public function test_tasks_reclassify_ai_invalid_schema_keeps_local_classification(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
            'services.workload_ai_classification.model' => 'gpt-4o-mini',
        ]);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'task_category' => 'deep_work',
                    'effort_score' => 99,
                    'work_type' => 'software_it',
                    'confidence' => 'high',
                    'reason' => 'invalid score for category',
                ]),
            ]),
        ]);

        DB::table('tasks')->insert([
            'title' => 'random unknown noun',
            'task_category' => 'uncategorised',
            'effort_score' => 1,
            'classification_confidence' => 'low',
            'classification_source' => 'system',
            'user_override' => false,
            'matched_pattern' => null,
            'work_type' => 'unclear',
            'work_type_confidence' => 'low',
            'work_type_matched_pattern' => null,
        ]);

        $exitCode = Artisan::call('tasks:reclassify', [
            '--ai' => true,
            '--limit' => 100,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"ai_failed":1', Artisan::output());
        $this->assertDatabaseHas('tasks', [
            'title' => 'random unknown noun',
            'task_category' => 'unclear_unrated',
            'effort_score' => 0,
            'classification_source' => 'system',
            'matched_pattern' => 'unclear:no_work_signal',
            'work_type' => 'unclear',
        ]);
    }
}
