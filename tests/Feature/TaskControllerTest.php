<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('tasks');
        Schema::dropIfExists('system_users');

        Schema::create('system_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('title');
            $table->string('status');
            $table->date('due_date');
            $table->timestamp('created_at')->nullable();
            $table->date('completed_at')->nullable();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 42,
            'email' => 'tasks@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
        ]);
    }

    public function test_batch_create_tasks_persists_multiple_tasks(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => 'Follow up clients', 'due_date' => '2026-05-14'],
                    ['title' => 'Prepare report', 'due_date' => '2026-05-15'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Follow up clients')
            ->assertJsonPath('tasks.1.title', 'Prepare report');

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Follow up clients',
            'due_date' => '2026-05-14',
            'status' => 'Ongoing',
        ]);
        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Prepare report',
            'due_date' => '2026-05-15',
            'status' => 'Ongoing',
        ]);
    }

    public function test_batch_create_tasks_validates_each_row(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => '', 'due_date' => '2026-05-14'],
                    ['title' => 'Prepare report', 'due_date' => '14-05-2026'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['tasks.0.title', 'tasks.1.due_date']);
    }

    public function test_batch_create_tasks_rejects_whitespace_only_titles(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => '   ', 'due_date' => '2026-05-14'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['tasks.0.title']);
    }

    private function authenticated()
    {
        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 42,
                'roles' => ['Staff'],
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }
}
