<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CsrfProtectionTest extends TestCase
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
            $table->timestamp('account_locked_until')->nullable();
            $table->boolean('total_lock')->default(false);
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 10,
            'email' => 'user@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
            'account_locked_until' => null,
            'total_lock' => 0,
        ]);
    }

    public function test_unsafe_authenticated_api_request_without_csrf_token_is_rejected(): void
    {
        $this->withSession([
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => ['Staff'],
        ])
            ->postJson('/auth/logout')
            ->assertStatus(419)
            ->assertJson([
                'status' => 'error',
                'message' => 'CSRF token mismatch.',
            ]);
    }

    public function test_unsafe_authenticated_api_request_with_csrf_token_is_allowed(): void
    {
        $this->withSession([
            '_token' => 'test-csrf-token',
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => ['Staff'],
        ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/auth/logout')
            ->assertOk()
            ->assertJson([
                'status' => 'success',
                'message' => 'Logged out.',
            ]);
    }

    public function test_unauthenticated_unsafe_api_request_still_returns_login_failure(): void
    {
        $this->postJson('/auth/logout')
            ->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Please log in to continue.',
            ]);
    }
}
