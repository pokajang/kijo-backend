<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthPasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('system_users');

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('account_locked_until')->nullable();
            $table->boolean('total_lock')->default(false);
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->string('password_hash')->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
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
            'email' => 'user@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
            'account_locked_until' => now()->addMinutes(10),
            'total_lock' => 0,
            'failed_attempts' => 3,
            'password_hash' => password_hash('old-password', PASSWORD_BCRYPT),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_password_reset_request_sends_link_for_active_account(): void
    {
        Mail::fake();
        config([
            'app.frontend_url' => 'https://work.example.test',
            'mail.from.address' => 'kijo@work.amiosh.com',
            'mail.from.name' => 'Kijo Alert',
            'mail.quote.from.address' => 'info.admin@amiosh.com',
            'mail.quote.from.name' => 'AMIOSH Admin',
        ]);

        $this->postJson('/auth/password/forgot', ['email' => ' USER@example.test '])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'message',
                'If an active account exists for that email, a password reset link has been sent.',
            );

        $row = DB::table('password_reset_tokens')->where('email', 'user@example.test')->first();
        $this->assertNotNull($row);
        $this->assertNotSame('', (string) $row->token);

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail): bool {
            $from = $mail->envelope()->from;

            return str_starts_with($mail->resetUrl, 'https://work.example.test/reset-password/')
                && str_contains($mail->resetUrl, 'email=user%40example.test')
                && $from?->address === 'kijo@work.amiosh.com'
                && $from?->name === 'Kijo Alert';
        });
    }

    public function test_password_reset_request_uses_local_frontend_origin_when_not_configured(): void
    {
        Mail::fake();
        config([
            'app.frontend_url' => null,
            'app.url' => 'http://localhost/kijoV2/backend-laravel/public',
        ]);

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/auth/password/forgot', ['email' => 'user@example.test'])
            ->assertOk();

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail): bool {
            return str_starts_with($mail->resetUrl, 'http://localhost:3000/reset-password/')
                && ! str_contains($mail->resetUrl, 'backend-laravel')
                && ! str_contains($mail->resetUrl, 'public');
        });
    }

    public function test_password_reset_request_strips_backend_path_from_app_url_fallback(): void
    {
        Mail::fake();
        config([
            'app.frontend_url' => null,
            'app.url' => 'http://localhost/kijoV2/backend-laravel/public',
        ]);

        $this->postJson('/auth/password/forgot', ['email' => 'user@example.test'])
            ->assertOk();

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail): bool {
            return str_starts_with($mail->resetUrl, 'http://localhost/reset-password/')
                && ! str_contains($mail->resetUrl, 'kijoV2')
                && ! str_contains($mail->resetUrl, 'backend-laravel')
                && ! str_contains($mail->resetUrl, 'public');
        });
    }

    public function test_password_reset_request_does_not_reveal_unknown_email(): void
    {
        Mail::fake();

        $this->postJson('/auth/password/forgot', ['email' => 'missing@example.test'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseCount('password_reset_tokens', 0);
        Mail::assertNothingSent();
    }

    public function test_password_reset_updates_password_and_consumes_token(): void
    {
        DB::table('sessions')->insert([
            'id' => 'existing-session',
            'user_id' => 1,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => 'user@example.test',
            'token' => hash('sha256', 'plain-token'),
            'created_at' => now(),
        ]);

        $this->postJson('/auth/password/reset', [
            'email' => 'user@example.test',
            'token' => 'plain-token',
            'newPassword' => 'new-password',
            'confirmPassword' => 'new-password',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user = DB::table('system_users')->where('id', 1)->first();
        $this->assertTrue(password_verify('new-password', (string) $user->password_hash));
        $this->assertSame(0, (int) $user->failed_attempts);
        $this->assertNull($user->account_locked_until);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'user@example.test']);
        $this->assertDatabaseMissing('sessions', ['id' => 'existing-session']);
    }

    public function test_password_reset_rejects_expired_or_invalid_token(): void
    {
        DB::table('password_reset_tokens')->insert([
            'email' => 'user@example.test',
            'token' => hash('sha256', 'plain-token'),
            'created_at' => now()->subMinutes(61),
        ]);

        $this->postJson('/auth/password/reset', [
            'email' => 'user@example.test',
            'token' => 'plain-token',
            'newPassword' => 'new-password',
            'confirmPassword' => 'new-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This password reset link is invalid or has expired.');
    }

    public function test_password_reset_rejects_short_new_password(): void
    {
        $this->postJson('/auth/password/reset', [
            'email' => 'user@example.test',
            'token' => 'plain-token',
            'newPassword' => 'short',
            'confirmPassword' => 'short',
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['newPassword']);
    }

    public function test_password_reset_rejects_overlong_new_password(): void
    {
        $longPassword = str_repeat('a', 129);

        $this->postJson('/auth/password/reset', [
            'email' => 'user@example.test',
            'token' => 'plain-token',
            'newPassword' => $longPassword,
            'confirmPassword' => $longPassword,
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['newPassword']);
    }

    public function test_password_reset_mismatch_returns_field_errors(): void
    {
        $this->postJson('/auth/password/reset', [
            'email' => 'user@example.test',
            'token' => 'plain-token',
            'newPassword' => 'new-password-123',
            'confirmPassword' => 'different-password-123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['confirmPassword']);
    }
}
