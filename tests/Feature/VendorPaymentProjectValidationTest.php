<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VendorPaymentProjectValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('project_vendors');
        Schema::dropIfExists('project_collaborators');
        Schema::dropIfExists('vendor_main_details');
        Schema::dropIfExists('system_users');

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('project_collaborators', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('project_id');
            $table->unsignedBigInteger('staff_id');
            $table->string('project_role')->nullable();
        });

        Schema::create('project_vendors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('project_id');
            $table->unsignedInteger('vendor_id');
        });

        Schema::create('vendor_main_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('vendor_id');
            $table->string('vendor_name')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        DB::table('project_collaborators')->insert([
            'project_id' => 501,
            'staff_id' => 42,
            'project_role' => 'Leader',
        ]);

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 42,
            'email' => 'vendor-payments@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
        ]);

        DB::table('vendor_main_details')->insert([
            ['vendor_id' => 7, 'vendor_name' => 'Active Vendor', 'status' => 'Active', 'deleted_at' => null],
            ['vendor_id' => 8, 'vendor_name' => 'Inactive Vendor', 'status' => 'Inactive', 'deleted_at' => null],
        ]);
    }

    public function test_project_payment_requires_project_id(): void
    {
        $this->authenticated()
            ->postJson('/vendor-payments', $this->paymentPayload(['project_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);
    }

    public function test_project_payment_requires_vendor_assigned_to_project(): void
    {
        DB::table('project_vendors')->insert([
            'project_id' => 501,
            'vendor_id' => 99,
        ]);

        $this->authenticated()
            ->postJson('/vendor-payments', $this->paymentPayload([
                'project_id' => 501,
                'vendor_id' => 7,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vendor_id']);
    }

    public function test_non_project_payment_requires_active_vendor(): void
    {
        $this->authenticated()
            ->postJson('/vendor-payments', $this->paymentPayload([
                'vendor_id' => 8,
                'project_id' => null,
                'payment_context' => 'Office',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vendor_id']);
    }

    private function paymentPayload(array $overrides = []): array
    {
        return array_merge([
            'vendor_id' => 7,
            'project_id' => 501,
            'payment_context' => 'Project',
            'payment_type' => 'Deposit',
            'amount' => 100,
            'method' => 'Online Transfer',
            'remarks' => '',
        ], $overrides);
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
