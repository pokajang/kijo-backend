<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthRememberMeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('auth_remember_tokens');
        Schema::dropIfExists('system_users');

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('account_locked_until')->nullable();
            $table->boolean('total_lock')->default(false);
            $table->timestamps();
        });

        Schema::create('auth_remember_tokens', function (Blueprint $table): void {
            $table->string('selector', 64)->primary();
            $table->foreignId('user_id')->index();
            $table->string('token_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 10,
            'email' => 'remember@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
            'account_locked_until' => null,
            'total_lock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_session_restores_from_valid_remember_cookie(): void
    {
        $this->insertRememberToken('selector-1', 'plain-token', now()->addDays(30));

        $this->withCredentials()
            ->withUnencryptedCookie('kijo_remember', 'selector-1:plain-token')
            ->getJson('/auth/session')
            ->assertOk()
            ->assertCookie('kijo_remember')
            ->assertJsonPath('user.staff_id', 10)
            ->assertJsonPath('user.email', 'remember@example.test')
            ->assertJsonPath('user.roles', ['Staff']);

        $this->assertDatabaseMissing('auth_remember_tokens', ['selector' => 'selector-1']);

        $rotated = DB::table('auth_remember_tokens')->where('user_id', 1)->first();
        $this->assertNotNull($rotated);
        $this->assertNotNull($rotated->last_used_at);
        $this->assertNotSame(hash('sha256', 'plain-token'), $rotated->token_hash);
    }

    public function test_expired_remember_cookie_is_rejected_and_deleted(): void
    {
        $this->insertRememberToken('selector-1', 'plain-token', now()->subMinute());

        $this->withCredentials()
            ->withUnencryptedCookie('kijo_remember', 'selector-1:plain-token')
            ->getJson('/auth/session')
            ->assertStatus(403);

        $this->assertDatabaseMissing('auth_remember_tokens', ['selector' => 'selector-1']);
    }

    public function test_remember_cookie_is_cleared_when_account_is_inactive(): void
    {
        DB::table('system_users')->where('id', 1)->update(['is_active' => 0]);
        $this->insertRememberToken('selector-1', 'plain-token', now()->addDays(30));

        $this->withCredentials()
            ->withUnencryptedCookie('kijo_remember', 'selector-1:plain-token')
            ->getJson('/auth/session')
            ->assertStatus(403)
            ->assertCookieExpired('kijo_remember');

        $this->assertDatabaseMissing('auth_remember_tokens', ['selector' => 'selector-1']);
    }

    public function test_logout_deletes_matching_remember_token(): void
    {
        $this->insertRememberToken('selector-1', 'plain-token', now()->addDays(30));

        $this->withCredentials()
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 10,
                'roles' => ['Staff'],
            ])
            ->withUnencryptedCookie('kijo_remember', 'selector-1:plain-token')
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/auth/logout')
            ->assertOk()
            ->assertCookieExpired('kijo_remember');

        $this->assertDatabaseMissing('auth_remember_tokens', ['selector' => 'selector-1']);
    }

    private function insertRememberToken(string $selector, string $token, mixed $expiresAt): void
    {
        DB::table('auth_remember_tokens')->insert([
            'selector' => $selector,
            'user_id' => 1,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
