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
        Schema::dropIfExists('receivable_payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('system_users');

        Schema::create('system_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('invoice_ref_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->unsignedInteger('staff_id');
            $table->string('email')->nullable();
            $table->text('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('receivable_payments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('source_type');
            $table->unsignedInteger('source_id');
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->string('payment_method')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->text('remarks')->nullable();
            $table->string('request_token')->unique();
            $table->unsignedInteger('recorded_by_staff_id')->nullable();
            $table->string('recorded_by_code')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedInteger('reversed_by_staff_id')->nullable();
            $table->string('reversed_by_code')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('invoice_ref_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('grand_total', 12, 2)->default(0);
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
            'invoice_ref_no' => 'INV-OPEN-POLICY',
            'invoice_date' => '2026-05-01',
            'grand_total' => 250.75,
            'staff_id' => 10,
            'email' => 'employee@example.test',
            'role' => json_encode(['Employee']),
            'is_active' => 1,
        ]);

        DB::table('invoices')->insert([
            'id' => 1,
            'invoice_ref_no' => 'INV-OPEN-POLICY',
            'invoice_date' => '2026-05-01',
            'grand_total' => 250.75,
            'status' => 'Paid',
            'paid_date' => '2026-05-15',
            'paid_amount' => 250.75,
            'paid_remarks' => 'Bank transfer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoices')->insert([
            'id' => 2,
            'invoice_ref_no' => 'INV-PARTIAL-POLICY',
            'invoice_date' => '2026-05-01',
            'grand_total' => 300,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('receivable_payments')->insert([
            'source_type' => 'invoice',
            'source_id' => 1,
            'amount' => 250.75,
            'payment_date' => '2026-05-15',
            'request_token' => '8cddf962-1d92-4ccb-83ca-f8b04c364a52',
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

    public function test_receivable_payment_routes_are_authenticated_only_not_role_gated(): void
    {
        $payload = [
            'payment_type' => 'partial',
            'amount' => 100,
            'payment_date' => '2026-05-20',
            'request_token' => '79ef0f58-bd13-4ab4-8370-56702694c1f1',
        ];

        $this->postJson('/receivables/invoice/2/payments', $payload)->assertForbidden();

        $this->actingSession()
            ->postJson('/receivables/invoice/2/payments', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('summary.paymentStatus', 'Partially Paid')
            ->assertJsonPath('summary.outstandingAmount', 200);

        $this->assertDatabaseHas('receivable_payments', [
            'source_type' => 'invoice',
            'source_id' => 2,
            'amount' => 100,
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
