<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceOpenAuthenticatedPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('user_activities');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('system_users');

        Schema::create('system_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('email')->nullable();
            $table->text('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('status')->default('Pending');
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->text('paid_remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 10,
            'email' => 'employee@example.test',
            'role' => json_encode(['Employee']),
            'is_active' => 1,
        ]);

        DB::table('invoices')->insert([
            'id' => 1,
            'status' => 'Paid',
            'paid_date' => '2026-05-15',
            'paid_amount' => 250.75,
            'paid_remarks' => 'Bank transfer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_invoice_mutation_routes_are_authenticated_only_not_role_gated(): void
    {
        $this->patchJson('/invoices/1/mark-unpaid')->assertForbidden();

        $this->actingSession()
            ->patchJson('/invoices/1/mark-unpaid')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('invoices', [
            'id' => 1,
            'status' => 'Pending',
            'paid_date' => null,
            'paid_amount' => null,
        ]);
    }

    public function test_jd14_mutation_routes_are_authenticated_only_not_role_gated(): void
    {
        $this->postJson('/jd14-forms', [])->assertForbidden();

        $this->actingSession()
            ->postJson('/jd14-forms', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'project_id',
                'employer_name',
                'employer_address',
                'approval_no',
                'course_title',
                'training_venue',
                'commenced_date',
                'end_date',
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
