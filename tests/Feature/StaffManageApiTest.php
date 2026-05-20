<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffManageApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['staff_profile', 'staff_general', 'system_users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('staff_id');
            $table->string('email')->nullable();
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

    private function actingAsManager(): self
    {
        return $this->withSession([
            'user_id' => 1,
            'staff_id' => 1,
            'roles' => ['HR'],
            '_token' => 'test-token',
        ])->withHeader('X-CSRF-TOKEN', 'test-token');
    }
}
