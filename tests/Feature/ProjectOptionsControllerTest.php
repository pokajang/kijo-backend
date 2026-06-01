<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectOptionsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('project_collaborators');
        Schema::dropIfExists('client_company');
        Schema::dropIfExists('projects_main');
        Schema::dropIfExists('system_users');

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('project_name');
            $table->string('project_type')->nullable();
            $table->decimal('quote_value', 12, 2)->nullable();
            $table->unsignedInteger('client_id')->nullable();
            $table->string('status');
            $table->date('service_start_date')->nullable();
            $table->date('service_end_date')->nullable();
            $table->date('award_date')->nullable();
        });

        Schema::create('client_company', function (Blueprint $table): void {
            $table->increments('company_id');
            $table->string('company_name');
        });

        Schema::create('project_collaborators', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('project_id');
            $table->unsignedBigInteger('staff_id');
            $table->string('project_role');
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 42,
            'email' => 'projects@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
        ]);

        DB::table('client_company')->insert([
            ['company_id' => 1, 'company_name' => 'Client A'],
            ['company_id' => 2, 'company_name' => 'Client B'],
            ['company_id' => 3, 'company_name' => 'Client C'],
            ['company_id' => 4, 'company_name' => 'Client D'],
        ]);

        DB::table('projects_main')->insert([
            [
                'id' => 100,
                'project_name' => 'Prior Year Active',
                'project_type' => 'Training',
                'quote_value' => 1000,
                'client_id' => 1,
                'status' => 'Active',
                'service_start_date' => '2025-11-01',
                'service_end_date' => '2026-06-30',
                'award_date' => '2025-10-15',
            ],
            [
                'id' => 101,
                'project_name' => 'Completed Project',
                'project_type' => 'Equipment Supply',
                'quote_value' => 2000,
                'client_id' => 2,
                'status' => 'Completed',
                'service_start_date' => '2026-01-01',
                'service_end_date' => '2026-02-28',
                'award_date' => '2026-01-01',
            ],
            [
                'id' => 102,
                'project_name' => 'Current Active',
                'project_type' => 'Industrial Hygiene',
                'quote_value' => 3000,
                'client_id' => 3,
                'status' => 'Active',
                'service_start_date' => '2026-04-01',
                'service_end_date' => '2026-12-31',
                'award_date' => '2026-03-20',
            ],
            [
                'id' => 103,
                'project_name' => 'Unrelated Active',
                'project_type' => 'Manpower Supply',
                'quote_value' => 4000,
                'client_id' => 4,
                'status' => 'Active',
                'service_start_date' => '2026-04-01',
                'service_end_date' => '2026-12-31',
                'award_date' => '2026-03-20',
            ],
            [
                'id' => 104,
                'project_name' => 'Owner Active',
                'project_type' => 'Special Service',
                'quote_value' => 5000,
                'client_id' => 1,
                'status' => 'Active',
                'service_start_date' => '2026-04-01',
                'service_end_date' => '2026-12-31',
                'award_date' => '2026-03-20',
            ],
        ]);

        DB::table('project_collaborators')->insert([
            ['project_id' => 100, 'staff_id' => 42, 'project_role' => 'Leader'],
            ['project_id' => 102, 'staff_id' => 42, 'project_role' => 'assistant '],
            ['project_id' => 103, 'staff_id' => 99, 'project_role' => 'Collaborator'],
            ['project_id' => 104, 'staff_id' => 42, 'project_role' => 'Owner'],
        ]);
    }

    public function test_project_options_returns_active_projects_without_year_filter(): void
    {
        $this->authenticated()
            ->getJson('/projects/options?status=active')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.projectName', 'Current Active')
            ->assertJsonPath('data.0.clientName', 'Client C')
            ->assertJsonPath('data.0.projectType', 'Industrial Hygiene')
            ->assertJsonPath('data.0.quoteValue', 3000)
            ->assertJsonPath('data.1.projectName', 'Owner Active')
            ->assertJsonPath('data.2.projectName', 'Prior Year Active')
            ->assertJsonPath('data.3.projectName', 'Unrelated Active')
            ->assertJsonMissing(['projectName' => 'Completed Project']);
    }

    public function test_project_options_scope_mine_returns_only_related_active_projects(): void
    {
        $this->authenticated()
            ->getJson('/projects/options?status=active&scope=mine')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.projectName', 'Current Active')
            ->assertJsonPath('data.1.projectName', 'Owner Active')
            ->assertJsonPath('data.2.projectName', 'Prior Year Active')
            ->assertJsonMissing(['projectName' => 'Unrelated Active'])
            ->assertJsonMissing(['projectName' => 'Completed Project']);
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
