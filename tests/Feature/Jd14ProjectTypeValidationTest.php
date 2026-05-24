<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Jd14ProjectTypeValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            RequireAuth::class,
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);

        Schema::dropIfExists('user_activities');
        Schema::dropIfExists('project_progress');
        Schema::dropIfExists('invoices_jd14form');
        Schema::dropIfExists('projects_main');

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('project_type');
            $table->string('project_name')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices_jd14form', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->unsignedInteger('created_by')->nullable();
            $table->string('employer_name');
            $table->text('employer_address');
            $table->string('approval_no')->unique();
            $table->string('employer_code')->nullable();
            $table->string('group_approved')->nullable();
            $table->string('group_claimed')->nullable();
            $table->string('course_title');
            $table->text('training_venue');
            $table->date('commenced_date');
            $table->date('end_date');
            $table->unsignedInteger('no_of_pax')->nullable();
            $table->decimal('total_fee_approved', 12, 2)->nullable();
            $table->decimal('total_fee_claimed', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('project_progress', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->date('progress_date');
            $table->text('progress_text');
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamp('updated_on')->nullable();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('projects_main')->insert([
            [
                'id' => 10,
                'project_type' => 'Training',
                'project_name' => 'Training Project',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 20,
                'project_type' => 'Equipment Supply',
                'project_name' => 'Equipment Project',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_training_project_can_create_jd14(): void
    {
        $this->actingSession()
            ->postJson('/jd14-forms', $this->validPayload(['project_id' => 10]))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['form_number']);

        $this->assertDatabaseHas('invoices_jd14form', [
            'project_id' => 10,
            'approval_no' => 'JD14-APP-001',
        ]);
    }

    public function test_non_training_project_cannot_create_jd14(): void
    {
        $this->actingSession()
            ->postJson('/jd14-forms', $this->validPayload(['project_id' => 20]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['project_id'])
            ->assertJsonPath('message', 'JD14 forms can only be generated for Training projects.');

        $this->assertDatabaseMissing('invoices_jd14form', [
            'project_id' => 20,
        ]);
    }

    public function test_missing_project_cannot_create_jd14(): void
    {
        $this->actingSession()
            ->postJson('/jd14-forms', $this->validPayload(['project_id' => 999]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['project_id'])
            ->assertJsonPath('message', 'Project not found.');
    }

    public function test_jd14_project_cannot_be_changed_on_update(): void
    {
        $id = DB::table('invoices_jd14form')->insertGetId(
            $this->jd14Row(['project_id' => 10, 'approval_no' => 'JD14-APP-EDIT']),
        );

        $this->actingSession()
            ->putJson("/jd14-forms/{$id}", $this->validPayload([
                'project_id' => 20,
                'approval_no' => 'JD14-APP-EDIT',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['project_id'])
            ->assertJsonPath('message', 'JD14 project cannot be changed.');

        $this->assertDatabaseHas('invoices_jd14form', [
            'id' => $id,
            'project_id' => 10,
        ]);
    }

    public function test_jd14_linked_to_non_training_project_cannot_be_updated(): void
    {
        $id = DB::table('invoices_jd14form')->insertGetId(
            $this->jd14Row(['project_id' => 20, 'approval_no' => 'JD14-APP-INVALID']),
        );

        $this->actingSession()
            ->putJson("/jd14-forms/{$id}", $this->validPayload([
                'project_id' => 20,
                'approval_no' => 'JD14-APP-INVALID',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['project_id'])
            ->assertJsonPath('message', 'JD14 forms can only be generated for Training projects.');
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'project_id' => 10,
            'employer_name' => 'Training Client Sdn Bhd',
            'employer_address' => '1 Training Road',
            'approval_no' => 'JD14-APP-001',
            'employer_code' => 'JD14',
            'group_approved' => '10',
            'group_claimed' => '10',
            'course_title' => 'Safety Training',
            'training_venue' => 'Training Client Sdn Bhd, 1 Training Road',
            'commenced_date' => '2026-05-20',
            'end_date' => '2026-05-21',
            'no_of_pax' => 10,
            'total_fee_approved' => 1000,
            'total_fee_claimed' => 1000,
        ], $overrides);
    }

    private function jd14Row(array $overrides = []): array
    {
        return array_merge([
            'project_id' => 10,
            'created_by' => 10,
            'employer_name' => 'Training Client Sdn Bhd',
            'employer_address' => '1 Training Road',
            'approval_no' => 'JD14-APP-ROW',
            'employer_code' => 'JD14',
            'group_approved' => '10',
            'group_claimed' => '10',
            'course_title' => 'Safety Training',
            'training_venue' => 'Training Client Sdn Bhd, 1 Training Road',
            'commenced_date' => '2026-05-20',
            'end_date' => '2026-05-21',
            'no_of_pax' => 10,
            'total_fee_approved' => 1000,
            'total_fee_claimed' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
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
