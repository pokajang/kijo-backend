<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminTaskClassificationExampleControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('system_users');
        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::dropIfExists('staff_general');
        Schema::create('staff_general', function (Blueprint $table): void {
            $table->unsignedBigInteger('staff_id')->primary();
            $table->string('name_code')->nullable();
            $table->string('full_name')->nullable();
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

        Schema::dropIfExists('tasks');
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('task_category')->nullable();
            $table->string('classification_confidence')->nullable();
            $table->string('classification_source')->nullable();
            $table->string('work_type')->nullable();
        });

        Schema::dropIfExists('user_activities');
        Schema::create('user_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('name_code', 20);
            $table->text('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('system_users')->insert([
            [
                'id' => 1,
                'staff_id' => 10,
                'email' => 'sysadmin@example.test',
                'role' => json_encode(['System Admin']),
                'is_active' => 1,
            ],
            [
                'id' => 2,
                'staff_id' => 20,
                'email' => 'manager@example.test',
                'role' => json_encode(['Manager']),
                'is_active' => 1,
            ],
        ]);
        DB::table('staff_general')->insert([
            ['staff_id' => 10, 'name_code' => 'ADM', 'full_name' => 'System Admin'],
            ['staff_id' => 20, 'name_code' => 'MGR', 'full_name' => 'Manager User'],
        ]);
    }

    public function test_system_admin_can_list_and_search_paginated_learned_classifications_with_affected_count(): void
    {
        DB::table('task_classification_examples')->insert([
            [
                'normalized_title_hash' => hash('sha256', 'custom proposal'),
                'normalized_title' => 'custom proposal',
                'sample_title' => 'prpe custom new propoosal',
                'task_category' => 'real_effort',
                'effort_score' => 3,
                'classification_confidence' => 'medium',
                'classification_source' => 'ai',
                'matched_pattern' => 'ai:proposal preparation',
                'work_type' => 'commercial_sales',
                'work_type_confidence' => 'medium',
                'work_type_matched_pattern' => 'ai:proposal preparation',
                'usage_count' => 4,
                'last_seen_at' => '2026-05-28 10:00:00',
                'created_at' => '2026-05-28 09:00:00',
                'updated_at' => '2026-05-28 10:00:00',
            ],
            [
                'normalized_title_hash' => hash('sha256', 'supplier reconciliation'),
                'normalized_title' => 'supplier reconciliation',
                'sample_title' => 'supplier reconciliation',
                'task_category' => 'real_effort',
                'effort_score' => 3,
                'classification_confidence' => 'medium',
                'classification_source' => 'ai',
                'matched_pattern' => 'ai:finance reconciliation',
                'work_type' => 'finance_hr',
                'work_type_confidence' => 'medium',
                'work_type_matched_pattern' => 'ai:finance reconciliation',
                'usage_count' => 1,
                'last_seen_at' => '2026-05-27 10:00:00',
                'created_at' => '2026-05-27 09:00:00',
                'updated_at' => '2026-05-27 10:00:00',
            ],
        ]);
        DB::table('tasks')->insert([
            [
                'title' => 'custom proposal',
                'task_category' => 'real_effort',
                'classification_confidence' => 'medium',
                'classification_source' => 'ai_cache',
                'work_type' => 'commercial_sales',
            ],
            [
                'title' => 'Custom proposal',
                'task_category' => 'real_effort',
                'classification_confidence' => 'medium',
                'classification_source' => 'ai_cache',
                'work_type' => 'commercial_sales',
            ],
        ]);

        $this->withSession($this->adminSession(['System Admin']))
            ->getJson('/admin/task-classification-examples?search=proposal&page=1&perPage=25')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.perPage', 25)
            ->assertJsonPath('data.lastPage', 1)
            ->assertJsonPath('data.examples.0.normalizedTitle', 'custom proposal')
            ->assertJsonPath('data.examples.0.taskCategoryLabel', 'Real Effort')
            ->assertJsonPath('data.examples.0.workTypeLabel', 'Commercial / Sales')
            ->assertJsonPath('data.examples.0.affectedTaskCount', 2);
    }

    public function test_only_system_admin_can_manage_learned_classifications(): void
    {
        $this->withSession($this->adminSession(['Manager'], 2, 20))
            ->getJson('/admin/task-classification-examples')
            ->assertStatus(403);

        $this->withSession($this->adminSession(['Manager'], 2, 20))
            ->getJson('/admin/task-classification-health')
            ->assertStatus(403);
    }

    public function test_system_admin_can_filter_learned_classifications(): void
    {
        DB::table('task_classification_examples')->insert([
            [
                'normalized_title_hash' => hash('sha256', 'custom proposal'),
                'normalized_title' => 'custom proposal',
                'sample_title' => 'custom proposal',
                'task_category' => 'real_effort',
                'effort_score' => 3,
                'classification_confidence' => 'medium',
                'classification_source' => 'ai',
                'matched_pattern' => 'ai:proposal preparation',
                'work_type' => 'commercial_sales',
                'work_type_confidence' => 'medium',
                'work_type_matched_pattern' => 'ai:proposal preparation',
                'usage_count' => 4,
                'last_seen_at' => '2026-05-28 10:00:00',
                'created_at' => '2026-05-28 09:00:00',
                'updated_at' => '2026-05-28 10:00:00',
            ],
            [
                'normalized_title_hash' => hash('sha256', 'supplier reconciliation'),
                'normalized_title' => 'supplier reconciliation',
                'sample_title' => 'supplier reconciliation',
                'task_category' => 'deep_work',
                'effort_score' => 5,
                'classification_confidence' => 'high',
                'classification_source' => 'ai_cache',
                'matched_pattern' => 'ai:finance reconciliation',
                'work_type' => 'finance_hr',
                'work_type_confidence' => 'high',
                'work_type_matched_pattern' => 'ai:finance reconciliation',
                'usage_count' => 1,
                'last_seen_at' => '2026-05-27 10:00:00',
                'created_at' => '2026-05-27 09:00:00',
                'updated_at' => '2026-05-27 10:00:00',
            ],
            [
                'normalized_title_hash' => hash('sha256', 'orphan learned row'),
                'normalized_title' => 'orphan learned row',
                'sample_title' => 'orphan learned row',
                'task_category' => 'uncategorised',
                'effort_score' => 1,
                'classification_confidence' => 'low',
                'classification_source' => 'ai',
                'matched_pattern' => 'ai:unclear admin',
                'work_type' => 'clerical_admin',
                'work_type_confidence' => 'low',
                'work_type_matched_pattern' => 'ai:unclear admin',
                'usage_count' => 1,
                'last_seen_at' => '2026-05-26 10:00:00',
                'created_at' => '2026-05-26 09:00:00',
                'updated_at' => '2026-05-26 10:00:00',
            ],
        ]);
        DB::table('tasks')->insert([
            [
                'title' => 'custom proposal',
                'task_category' => 'deep_work',
                'classification_confidence' => 'high',
                'classification_source' => 'ai_cache',
                'work_type' => 'finance_hr',
            ],
        ]);

        $this->withSession($this->adminSession(['System Admin']))
            ->getJson('/admin/task-classification-examples?workType=commercial_sales&confidence=medium&source=ai')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.examples.0.normalizedTitle', 'custom proposal');

        $this->withSession($this->adminSession(['System Admin']))
            ->getJson('/admin/task-classification-examples?affected=with')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.examples.0.normalizedTitle', 'custom proposal');

        $this->withSession($this->adminSession(['System Admin']))
            ->getJson('/admin/task-classification-examples?taskCategory=uncategorised&affected=without')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.examples.0.normalizedTitle', 'orphan learned row');
    }

    public function test_system_admin_can_view_classification_health(): void
    {
        DB::table('task_classification_examples')->insert([
            'normalized_title_hash' => hash('sha256', 'custom proposal'),
            'normalized_title' => 'custom proposal',
            'sample_title' => 'custom proposal',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_confidence' => 'medium',
            'classification_source' => 'ai',
            'matched_pattern' => 'ai:proposal preparation',
            'work_type' => 'commercial_sales',
            'work_type_confidence' => 'medium',
            'work_type_matched_pattern' => 'ai:proposal preparation',
            'usage_count' => 4,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tasks')->insert([
            [
                'title' => 'unclear',
                'task_category' => 'unclear_unrated',
                'classification_confidence' => 'low',
                'classification_source' => 'system',
                'work_type' => 'unclear',
            ],
            [
                'title' => 'watching tv',
                'task_category' => 'non_work',
                'classification_confidence' => 'high',
                'classification_source' => 'system',
                'work_type' => 'non_work',
            ],
            [
                'title' => 'custom proposal',
                'task_category' => 'real_effort',
                'classification_confidence' => 'medium',
                'classification_source' => 'ai',
                'work_type' => 'commercial_sales',
            ],
            [
                'title' => 'cached proposal',
                'task_category' => 'real_effort',
                'classification_confidence' => 'medium',
                'classification_source' => 'ai_cache',
                'work_type' => 'commercial_sales',
            ],
        ]);

        $this->withSession($this->adminSession(['System Admin']))
            ->getJson('/admin/task-classification-health')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.totalClassifiedTasks', 4)
            ->assertJsonPath('data.unclearUnratedTasks', 1)
            ->assertJsonPath('data.nonWorkTasks', 1)
            ->assertJsonPath('data.lowConfidenceTasks', 1)
            ->assertJsonPath('data.aiClassifiedTasks', 1)
            ->assertJsonPath('data.learnedCacheTasks', 1)
            ->assertJsonPath('data.learnedCacheRows', 1)
            ->assertJsonPath('data.learnedCacheUsage', 4);
    }

    public function test_system_admin_can_delete_learned_classification_without_rewriting_tasks(): void
    {
        $id = DB::table('task_classification_examples')->insertGetId([
            'normalized_title_hash' => hash('sha256', 'custom proposal'),
            'normalized_title' => 'custom proposal',
            'sample_title' => 'custom proposal',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_confidence' => 'medium',
            'classification_source' => 'ai',
            'matched_pattern' => 'ai:proposal preparation',
            'work_type' => 'commercial_sales',
            'work_type_confidence' => 'medium',
            'work_type_matched_pattern' => 'ai:proposal preparation',
            'usage_count' => 4,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tasks')->insert([
            'title' => 'custom proposal',
            'task_category' => 'real_effort',
            'classification_confidence' => 'medium',
            'classification_source' => 'ai_cache',
            'work_type' => 'commercial_sales',
        ]);

        $this->withSession($this->adminSession(['System Admin']))
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->deleteJson("/admin/task-classification-examples/{$id}")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('task_classification_examples', ['id' => $id]);
        $this->assertDatabaseHas('tasks', [
            'title' => 'custom proposal',
            'task_category' => 'real_effort',
            'classification_source' => 'ai_cache',
            'work_type' => 'commercial_sales',
        ]);
        $this->assertDatabaseHas('user_activities', [
            'staff_id' => 10,
            'name_code' => 'ADM',
        ]);
        $this->assertStringContainsString(
            'Deleted learned workload classification',
            (string) DB::table('user_activities')->value('action')
        );
    }

    public function test_list_tolerates_missing_learned_classification_table(): void
    {
        Schema::dropIfExists('task_classification_examples');

        $this->withSession($this->adminSession(['System Admin']))
            ->getJson('/admin/task-classification-examples')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.examples', []);

        $this->withSession($this->adminSession(['System Admin']))
            ->getJson('/admin/task-classification-health')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.learnedCacheRows', 0);
    }

    private function adminSession(array $roles, int $userId = 1, int $staffId = 10): array
    {
        return [
            '_token' => 'test-csrf-token',
            'user_id' => $userId,
            'staff_id' => $staffId,
            'name_code' => 'ADM',
            'roles' => $roles,
        ];
    }
}
