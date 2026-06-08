<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectCloseFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            RequireAuth::class,
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);

        $this->createTables();
    }

    public function test_active_project_can_be_completed_from_manage_project_route(): void
    {
        DB::table('projects_main')->insert([
            'id' => 200,
            'project_name' => 'Active Close Project',
            'status' => 'Active',
        ]);

        $this->actingSession()
            ->postJson('/projects/200/close', [
                'closeDate' => '2026-06-08',
                'closeType' => 'Completed',
                'reason' => 'All deliverables accepted.',
                'claims' => true,
                'vendors' => true,
                'services' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('projects_main', [
            'id' => 200,
            'status' => 'Completed',
        ]);
        $this->assertDatabaseHas('project_closing_details', [
            'project_id' => 200,
            'close_date' => '2026-06-08',
            'close_type' => 'Completed',
            'reason' => 'All deliverables accepted.',
            'claims_ok' => 1,
            'vendors_ok' => 1,
            'services_ok' => 1,
            'closed_by' => 10,
        ]);
        $this->assertDatabaseHas('project_progress', [
            'project_id' => 200,
            'progress_text' => 'Project marked as Completed by EMP.',
        ]);
    }

    public function test_closed_project_cannot_be_reclosed_or_changed_to_terminated(): void
    {
        DB::table('projects_main')->insert([
            'id' => 201,
            'project_name' => 'Already Completed Project',
            'status' => 'Completed',
        ]);

        $this->actingSession()
            ->postJson('/projects/201/close', [
                'closeDate' => '2026-06-08',
                'closeType' => 'Terminated',
                'reason' => 'Attempted status change.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Project is already closed.');

        $this->assertDatabaseHas('projects_main', [
            'id' => 201,
            'status' => 'Completed',
        ]);
        $this->assertDatabaseCount('project_closing_details', 0);
        $this->assertDatabaseCount('project_progress', 0);
    }

    private function createTables(): void
    {
        Schema::dropIfExists('user_activities');
        Schema::dropIfExists('project_closing_details');
        Schema::dropIfExists('project_progress');
        Schema::dropIfExists('projects_main');
        Schema::dropIfExists('staff_general');

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('project_name')->nullable();
            $table->string('status')->nullable();
        });

        Schema::create('project_closing_details', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->date('close_date');
            $table->string('close_type');
            $table->text('reason');
            $table->boolean('claims_ok')->default(false);
            $table->boolean('vendors_ok')->default(false);
            $table->boolean('services_ok')->default(false);
            $table->integer('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
        });

        Schema::create('project_progress', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->date('progress_date');
            $table->text('progress_text');
            $table->integer('updated_by')->nullable();
            $table->timestamp('updated_on')->nullable();
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->integer('staff_id')->primary();
            $table->string('name_code')->nullable();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('staff_general')->insert([
            'staff_id' => 10,
            'name_code' => 'EMP',
        ]);
    }

    private function actingSession()
    {
        $this->app['session']->start();
        $this->app['session']->put([
            'user_id' => 1,
            'staff_id' => 10,
            'name_code' => 'EMP',
            '_token' => 'test-token',
        ]);

        return $this
            ->withSession([
                'user_id' => 1,
                'staff_id' => 10,
                'name_code' => 'EMP',
                '_token' => 'test-token',
            ])
            ->withCookie(config('session.cookie'), $this->app['session']->getId())
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }
}
