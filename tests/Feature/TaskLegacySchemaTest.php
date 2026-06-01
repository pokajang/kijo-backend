<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskLegacySchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('staff_general');
        Schema::dropIfExists('system_users');

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->unsignedBigInteger('staff_id')->primary();
            $table->string('full_name');
            $table->string('name_code');
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('title');
            $table->string('status');
            $table->date('due_date');
            $table->timestamp('created_at')->nullable();
            $table->date('completed_at')->nullable();
        });

        Schema::create('task_comments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->longText('comment');
            $table->timestamp('created_at')->nullable();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 51,
            'email' => 'legacy-tasks@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
        ]);

        DB::table('staff_general')->insert([
            'staff_id' => 51,
            'full_name' => 'Legacy Staff',
            'name_code' => 'LEG',
        ]);

        DB::table('tasks')->insert([
            'staff_id' => 51,
            'title' => 'Legacy task',
            'status' => 'Ongoing',
            'due_date' => '2026-05-30',
            'created_at' => '2026-05-25 09:00:00',
            'completed_at' => null,
        ]);
    }

    public function test_personal_tasks_work_without_project_link_columns(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
        ]);

        $this->authenticated()
            ->getJson('/tasks/personal?year=2026')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tasks.0.title', 'Legacy task')
            ->assertJsonPath('tasks.0.projectId', null)
            ->assertJsonPath('tasks.0.projectName', '')
            ->assertJsonPath('tasks.0.projectProgressId', null)
            ->assertJsonPath('tasks.0.taskCategory', 'uncategorised')
            ->assertJsonPath('tasks.0.effortScore', 1)
            ->assertJsonPath('tasks.0.classificationConfidence', 'low')
            ->assertJsonPath('tasks.0.aiClassificationStatus', 'not_applicable');
    }

    public function test_untagged_task_creation_works_without_project_link_columns(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
        ]);

        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => 'Untagged legacy task', 'due_date' => '2026-05-31'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tasks.0.projectId', null)
            ->assertJsonPath('tasks.0.taskCategory', 'uncategorised')
            ->assertJsonPath('tasks.0.effortScore', 1)
            ->assertJsonPath('tasks.0.aiClassificationStatus', 'not_applicable');

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 51,
            'title' => 'Untagged legacy task',
            'status' => 'Ongoing',
        ]);
    }

    public function test_tagged_task_creation_returns_clear_error_without_project_link_columns(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => 'Tagged legacy task', 'due_date' => '2026-05-31', 'project_id' => 100],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['tasks.0.project_id']);
    }

    private function authenticated()
    {
        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 51,
                'roles' => ['Staff'],
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }
}
