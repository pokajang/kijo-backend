<?php

namespace Tests\Feature;

use App\Jobs\SendHtmlMailJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffManageApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'https://kijo.amiosh.com',
            'app.url' => 'https://api.amiosh.com',
        ]);

        foreach (['staff_profile', 'staff_general', 'system_users', 'user_activities'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('staff_id');
            $table->string('email')->nullable();
            $table->string('password_hash')->nullable();
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->unsignedInteger('staff_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('position')->nullable();
            $table->string('staff_type')->nullable();
            $table->string('department')->nullable();
            $table->date('start_date')->nullable();
            $table->string('status')->nullable();
            $table->boolean('grant_access')->nullable();
            $table->json('role')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_profile', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('nric')->nullable();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 1,
            'email' => 'manager@example.test',
            'role' => json_encode(['HR']),
            'is_active' => 1,
        ]);

        DB::table('staff_general')->insert([
            [
                'staff_id' => 1,
                'full_name' => 'Active Manager',
                'name_code' => 'AM',
                'email' => 'manager@example.test',
                'status' => 'Active',
                'terminated_at' => null,
                'deleted_at' => null,
                'created_at' => '2026-05-01 00:00:00',
            ],
            [
                'staff_id' => 2,
                'full_name' => 'Inactive Staff',
                'name_code' => 'IS',
                'email' => 'inactive@example.test',
                'status' => 'Inactive',
                'terminated_at' => null,
                'deleted_at' => null,
                'created_at' => '2026-05-02 00:00:00',
            ],
            [
                'staff_id' => 3,
                'full_name' => 'Terminated Staff',
                'name_code' => 'TS',
                'email' => 'terminated@example.test',
                'status' => 'Inactive',
                'terminated_at' => '2026-05-03 00:00:00',
                'deleted_at' => '2026-05-03 00:00:00',
                'created_at' => '2026-05-03 00:00:00',
            ],
        ]);

        DB::table('staff_profile')->insert([
            ['staff_id' => 3, 'nric' => '900101-01-1234'],
        ]);
    }

    public function test_manage_staff_includes_active_inactive_and_terminated_rows(): void
    {
        $response = $this->actingAsManager()->getJson('/staff/manage');

        $response->assertOk()->assertJsonPath('status', 'success');

        $names = collect($response->json('staff'))->pluck('full_name')->all();

        $this->assertSame([
            'Active Manager',
            'Inactive Staff',
            'Terminated Staff',
        ], $names);
    }

    public function test_staff_detail_allows_terminated_staff_from_manage_table(): void
    {
        $this->actingAsManager()
            ->getJson('/staff/by-id?staff_id=3')
            ->assertOk()
            ->assertJsonPath('staff.full_name', 'Terminated Staff')
            ->assertJsonPath('staff.deleted_at', '2026-05-03 00:00:00');
    }

    public function test_update_staff_can_reactivate_terminated_staff(): void
    {
        $this->actingAsManager()
            ->putJson('/staff', [
                'staffId' => 3,
                'fullName' => 'Terminated Staff',
                'nameCode' => 'TRM',
                'email' => 'terminated@example.test',
                'mobileNumber' => '60123456789',
                'position' => 'Consultant',
                'staffType' => 'Permanent',
                'department' => 'Operations',
                'startDate' => '2026-05-01',
                'status' => 'Active',
                'grantAccess' => false,
                'systemRoles' => [],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('staff_general', [
            'staff_id' => 3,
            'status' => 'Active',
            'deleted_at' => null,
            'terminated_at' => null,
        ]);
    }

    public function test_update_staff_can_save_terminated_staff_without_reactivating(): void
    {
        $this->actingAsManager()
            ->putJson('/staff', [
                'staffId' => 3,
                'fullName' => 'Terminated Staff Updated',
                'nameCode' => 'TRM',
                'email' => 'terminated@example.test',
                'mobileNumber' => '60123456789',
                'position' => 'Consultant',
                'staffType' => 'Permanent',
                'department' => 'Operations',
                'startDate' => '2026-05-01',
                'status' => 'Inactive',
                'grantAccess' => false,
                'systemRoles' => [],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('staff_general', [
            'staff_id' => 3,
            'full_name' => 'Terminated Staff Updated',
            'status' => 'Inactive',
            'deleted_at' => '2026-05-03 00:00:00',
            'terminated_at' => '2026-05-03 00:00:00',
        ]);
    }

    public function test_granting_staff_access_queues_standardized_account_email(): void
    {
        Bus::fake([SendHtmlMailJob::class]);

        $this->actingAsManager()
            ->putJson('/staff', [
                'staffId' => 2,
                'fullName' => 'Inactive Staff',
                'nameCode' => 'ISA',
                'email' => 'inactive@example.test',
                'mobileNumber' => '60123456789',
                'position' => 'Consultant',
                'staffType' => 'Permanent',
                'department' => 'Operations',
                'startDate' => '2026-05-01',
                'status' => 'Active',
                'grantAccess' => true,
                'systemRoles' => ['Staff'],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        Bus::assertDispatched(SendHtmlMailJob::class, function (SendHtmlMailJob $job): bool {
            $body = (string) $this->jobProperty($job, 'body');
            $presentation = (array) $this->jobProperty($job, 'presentation');

            return $this->jobProperty($job, 'to') === 'inactive@example.test'
                && $this->jobProperty($job, 'subject') === 'Welcome to KIJO - Your Account Is Ready'
                && $this->jobProperty($job, 'cc') === []
                && str_contains($body, 'Login Details')
                && str_contains($body, 'Temporary password')
                && str_contains($body, 'Open KIJO')
                && str_contains($body, 'href="https://kijo.amiosh.com/login"')
                && ! str_contains($body, 'work.amiosh.com')
                && ! str_contains($body, 'https://api.amiosh.com')
                && ($presentation['headerLabel'] ?? null) === 'Account Access';
        });
    }

    private function actingAsManager(): self
    {
        return $this->withSession([
            'user_id' => 1,
            'staff_id' => 1,
            'roles' => ['HR'],
            '_token' => 'test-token',
        ])->withHeader('X-CSRF-TOKEN', 'test-token');
    }

    private function jobProperty(object $job, string $property): mixed
    {
        $reflection = new \ReflectionClass($job);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($job);
    }
}
