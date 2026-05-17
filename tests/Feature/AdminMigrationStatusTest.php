<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminMigrationStatusTest extends TestCase
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

        Schema::dropIfExists('migrations');
        Schema::create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 10,
            'email' => 'sysadmin@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => 1,
            'account_locked_until' => null,
            'total_lock' => 0,
        ]);
    }

    public function test_migration_status_requires_system_admin_role(): void
    {
        DB::table('system_users')->where('id', 1)->update([
            'role' => json_encode(['Manager']),
        ]);

        $this->withSession([
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => ['Manager'],
        ])
            ->getJson('/admin/migration-status')
            ->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized: required role missing.',
            ]);
    }

    public function test_stale_session_admin_role_is_rejected_against_current_database_role(): void
    {
        DB::table('system_users')->where('id', 1)->update([
            'role' => json_encode(['Manager']),
        ]);

        $this->withSession([
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => ['System Admin'],
        ])
            ->getJson('/admin/migration-status')
            ->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized: required role missing.',
            ]);
    }

    public function test_inactive_system_user_session_is_rejected(): void
    {
        DB::table('system_users')->where('id', 1)->update([
            'is_active' => 0,
        ]);

        $this->withSession([
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => ['System Admin'],
        ])
            ->getJson('/admin/migration-status')
            ->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Please log in to continue.',
            ]);
    }

    public function test_system_admin_can_view_read_only_migration_status(): void
    {
        DB::table('migrations')->insert([
            'migration' => '9999_01_01_000000_removed_legacy_table',
            'batch' => 1,
        ]);

        $response = $this->withSession([
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => ['System Admin'],
        ])->getJson('/admin/migration-status');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.authorized', true)
            ->assertJsonPath('user.can_run', false)
            ->assertJsonPath('user.read_only', true)
            ->assertJsonPath('environment.migration_source', 'laravel')
            ->assertJsonStructure([
                'summary' => [
                    'total_known',
                    'applied_count',
                    'pending_count',
                    'missing_file_count',
                ],
                'files',
                'generated_at',
            ]);

        $body = $response->json();
        $this->assertArrayNotHasKey('email', $body['user'] ?? []);
        $this->assertArrayNotHasKey('roles', $body['user'] ?? []);
        $this->assertArrayNotHasKey('app_env', $body['environment'] ?? []);
        $this->assertArrayNotHasKey('database_connection', $body['environment'] ?? []);
        $this->assertArrayNotHasKey('database_name', $body['environment'] ?? []);
        $this->assertSame(($body['summary']['total_files'] ?? 0) + 1, $body['summary']['total_known'] ?? 0);
        $this->assertArrayNotHasKey('file_modified_at', $body['files'][0] ?? []);
    }

    public function test_known_archived_applied_migrations_are_not_counted_as_missing_files(): void
    {
        DB::table('migrations')->insert([
            [
                'migration' => '2014_10_12_000000_create_users_table',
                'batch' => 1,
            ],
            [
                'migration' => '9999_01_01_000000_removed_legacy_table',
                'batch' => 1,
            ],
        ]);

        $response = $this->withSession([
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => ['System Admin'],
        ])->getJson('/admin/migration-status');

        $response
            ->assertOk()
            ->assertJsonPath('summary.archived_file_count', 1)
            ->assertJsonPath('summary.missing_file_count', 1)
            ->assertJsonPath('missing_files', ['9999_01_01_000000_removed_legacy_table']);

        $archivedRow = collect($response->json('files'))
            ->firstWhere('name', '2014_10_12_000000_create_users_table');

        $this->assertSame(true, $archivedRow['archived'] ?? false);
        $this->assertNotEmpty($archivedRow['archived_reason'] ?? '');
    }

    public function test_browser_run_migrations_endpoint_stays_disabled(): void
    {
        $this->withSession($this->authenticatedSession(['System Admin']))
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/admin/run-migrations')
            ->assertStatus(410)
            ->assertJsonPath('status', 'error');
    }

    public function test_browser_run_migrations_endpoint_rejects_non_admin_role(): void
    {
        DB::table('system_users')->where('id', 1)->update([
            'role' => json_encode(['Manager']),
        ]);

        $this->withSession($this->authenticatedSession(['Manager']))
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/admin/run-migrations')
            ->assertStatus(403)
            ->assertJsonPath('status', 'error');
    }

    private function authenticatedSession(array $roles): array
    {
        return [
            '_token' => 'test-csrf-token',
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => $roles,
        ];
    }
}
