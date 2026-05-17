<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthSessionSecurityTest extends TestCase
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
            $table->string('password_hash')->nullable();
            $table->timestamps();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 10,
            'email' => 'user@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => 1,
            'account_locked_until' => null,
            'total_lock' => 0,
            'password_hash' => password_hash('old-password', PASSWORD_BCRYPT),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_auth_session_rejects_inactive_user(): void
    {
        DB::table('system_users')->where('id', 1)->update(['is_active' => 0]);

        $this->withSession($this->sessionPayload(['System Admin']))
            ->getJson('/auth/session')
            ->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Please log in to continue.',
            ]);
    }

    public function test_auth_session_rejects_staff_id_mismatch(): void
    {
        $this->withSession([
            'user_id' => 1,
            'staff_id' => 99,
            'roles' => ['System Admin'],
        ])
            ->getJson('/auth/session')
            ->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Please log in to continue.',
            ]);
    }

    public function test_auth_session_rejects_locked_user(): void
    {
        DB::table('system_users')->where('id', 1)->update(['total_lock' => 1]);

        $this->withSession($this->sessionPayload(['System Admin']))
            ->getJson('/auth/session')
            ->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Please log in to continue.',
            ]);
    }

    public function test_auth_session_writes_current_database_roles_to_response(): void
    {
        DB::table('system_users')->where('id', 1)->update([
            'role' => json_encode([' System Admin ', 'Manager', 'Manager']),
        ]);

        $this->withSession($this->sessionPayload(['Old Role']))
            ->getJson('/auth/session')
            ->assertOk()
            ->assertJsonPath('user.roles', ['System Admin', 'Manager'])
            ->assertJsonPath('csrf_token', fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    public function test_role_downgrade_rejects_stale_admin_session(): void
    {
        Schema::dropIfExists('migrations');
        Schema::create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });

        DB::table('system_users')->where('id', 1)->update([
            'role' => json_encode(['Manager']),
        ]);

        $this->withSession($this->sessionPayload(['System Admin']))
            ->getJson('/admin/migration-status')
            ->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized: required role missing.',
            ]);
    }

    public function test_password_update_invalidates_other_database_sessions(): void
    {
        Schema::dropIfExists('sessions');
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        DB::table('sessions')->insert([
            'id' => 'other-session-id',
            'user_id' => 1,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $this->withSession($this->sessionPayload(['Staff']))
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/auth/password', [
                'currentPassword' => 'old-password',
                'newPassword' => 'new-password',
                'confirmPassword' => 'new-password',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('csrf_token', fn (mixed $value): bool => is_string($value) && $value !== '');

        $hash = (string) DB::table('system_users')->where('id', 1)->value('password_hash');
        $this->assertTrue(password_verify('new-password', $hash));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session-id']);
    }

    private function sessionPayload(array $roles): array
    {
        return [
            '_token' => 'test-csrf-token',
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => $roles,
        ];
    }
}
