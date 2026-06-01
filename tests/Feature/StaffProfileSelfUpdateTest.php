<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffProfileSelfUpdateTest extends TestCase
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
            $table->boolean('total_lock')->default(false);
            $table->string('password_hash')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->unsignedInteger('staff_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('crm_position')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_profile', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->date('birth_date')->nullable();
            $table->string('nric')->nullable();
            $table->text('current_address')->nullable();
            $table->string('emergency_name1')->nullable();
            $table->string('emergency_relationship1')->nullable();
            $table->string('emergency_phone1')->nullable();
            $table->text('emergency_address1')->nullable();
            $table->string('emergency_name2')->nullable();
            $table->string('emergency_relationship2')->nullable();
            $table->string('emergency_phone2')->nullable();
            $table->text('emergency_address2')->nullable();
            $table->text('chronic_illness')->nullable();
            $table->text('allergies')->nullable();
            $table->text('disabilities')->nullable();
            $table->text('current_medication')->nullable();
            $table->text('other_concerns')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 10,
            'email' => 'jane@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
            'total_lock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('staff_general')->insert([
            'staff_id' => 10,
            'full_name' => 'Jane Staff',
            'name_code' => 'JAN',
            'email' => 'jane@example.test',
            'mobile_number' => '0123456789',
            'crm_position' => null,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('staff_profile')->insert([
            'staff_id' => 10,
            'birth_date' => '1990-01-01',
            'nric' => '900101-01-1234',
            'current_address' => '123 Main Road',
            'emergency_name1' => 'John Staff',
            'emergency_relationship1' => 'Spouse',
            'emergency_phone1' => '0199999999',
            'emergency_address1' => '123 Main Road',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_self_profile_rejects_changed_email(): void
    {
        $payload = $this->validPayload(['email' => 'changed@example.test']);

        $this->actingAsStaff()
            ->putJson('/staff/profile', $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseHas('staff_general', [
            'staff_id' => 10,
            'email' => 'jane@example.test',
        ]);
    }

    public function test_self_profile_rejects_changed_name_code(): void
    {
        $payload = $this->validPayload(['nameCode' => 'JNX']);

        $this->actingAsStaff()
            ->putJson('/staff/profile', $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['nameCode']);

        $this->assertDatabaseHas('staff_general', [
            'staff_id' => 10,
            'name_code' => 'JAN',
        ]);
    }

    public function test_self_profile_rejects_missing_required_completion_fields(): void
    {
        $payload = $this->validPayload();
        unset($payload['mobileNumber']);

        $this->actingAsStaff()
            ->putJson('/staff/profile', $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['mobileNumber']);
    }

    public function test_self_profile_allows_unchanged_readonly_identity_fields(): void
    {
        DB::table('staff_general')->where('staff_id', 10)->update([
            'name_code' => 'JA',
            'email' => 'legacy-email',
        ]);

        $payload = $this->validPayload([
            'email' => 'legacy-email',
            'nameCode' => 'JA',
        ]);

        $this->actingAsStaff()
            ->putJson('/staff/profile', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('staff_general', [
            'staff_id' => 10,
            'name_code' => 'JA',
            'email' => 'legacy-email',
        ]);
    }

    public function test_self_profile_accepts_empty_optional_health_fields(): void
    {
        $payload = $this->validPayload([
            'chronicIllness' => '',
            'allergies' => '',
            'disabilities' => '',
            'currentMedication' => '',
            'otherConcerns' => '',
        ]);

        $this->actingAsStaff()
            ->putJson('/staff/profile', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('staff_general', [
            'staff_id' => 10,
            'full_name' => 'Jane Staff Updated',
            'mobile_number' => '0123456789',
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'fullName' => 'Jane Staff Updated',
            'mobileNumber' => '0123456789',
            'birthDate' => '1990-01-01',
            'nric' => '900101-01-1234',
            'currentAddress' => '123 Main Road',
            'crmPosition' => '',
            'emergencyName1' => 'John Staff',
            'emergencyRelationship1' => 'Spouse',
            'emergencyPhone1' => '0199999999',
            'emergencyAddress1' => '123 Main Road',
            'emergencyName2' => '',
            'emergencyRelationship2' => '',
            'emergencyPhone2' => '',
            'emergencyAddress2' => '',
            'chronicIllness' => '',
            'allergies' => '',
            'disabilities' => '',
            'currentMedication' => '',
            'otherConcerns' => '',
        ], $overrides);
    }

    private function actingAsStaff(): self
    {
        return $this->withSession([
            '_token' => 'test-token',
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => ['Staff'],
            'name_code' => 'JAN',
        ])->withHeader('X-CSRF-TOKEN', 'test-token');
    }
}
