<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectVendorAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'user_activities',
            'project_progress',
            'project_vendors',
            'vendor_main_details',
            'projects_main',
            'project_collaborators',
            'system_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email')->nullable();
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('project_collaborators', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('project_id');
            $table->unsignedBigInteger('staff_id');
            $table->string('project_role')->nullable();
        });

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('project_name')->nullable();
        });

        Schema::create('vendor_main_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('vendor_id');
            $table->string('vendor_name')->nullable();
            $table->string('contact_person_name')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('email')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('bank_holder_name')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('project_vendors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('project_id');
            $table->unsignedInteger('vendor_id');
            $table->decimal('award_value', 12, 2)->nullable();
            $table->date('award_date')->nullable();
            $table->unsignedInteger('awarded_by')->nullable();
            $table->string('position')->nullable();
            $table->text('remarks')->nullable();
            $table->text('services_description')->nullable();
            $table->text('venue_details')->nullable();
            $table->text('fee_breakdown')->nullable();
            $table->text('payment_terms')->nullable();
            $table->unsignedInteger('loa_running_no')->nullable();
            $table->string('loa_ref_no')->nullable();
        });

        Schema::create('project_progress', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('project_id');
            $table->date('progress_date')->nullable();
            $table->text('progress_text')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamp('updated_on')->nullable();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('staff_id');
            $table->string('name_code')->nullable();
            $table->text('action')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('system_users')->insert([
            ['id' => 10, 'staff_id' => 10, 'email' => 'linked@example.test', 'role' => json_encode(['Staff']), 'is_active' => 1],
            ['id' => 20, 'staff_id' => 20, 'email' => 'unlinked@example.test', 'role' => json_encode(['Staff']), 'is_active' => 1],
            ['id' => 30, 'staff_id' => 30, 'email' => 'manager@example.test', 'role' => json_encode(['Manager']), 'is_active' => 1],
        ]);

        DB::table('projects_main')->insert([
            ['id' => 501, 'project_name' => 'Linked Project'],
            ['id' => 777, 'project_name' => 'Other Project'],
        ]);

        DB::table('project_collaborators')->insert([
            'project_id' => 501,
            'staff_id' => 10,
            'project_role' => 'Leader',
        ]);

        DB::table('vendor_main_details')->insert([
            'vendor_id' => 7,
            'vendor_name' => 'Vendor A',
            'contact_person_name' => 'Vendor PIC',
            'mobile_number' => '600000000',
            'email' => 'vendor@example.test',
            'bank_name' => 'Bank',
            'bank_account' => '123',
            'bank_holder_name' => 'Vendor A',
            'status' => 'Active',
            'deleted_at' => null,
        ]);

        DB::table('project_vendors')->insert([
            'project_id' => 501,
            'vendor_id' => 7,
            'award_value' => 100,
            'award_date' => '2026-05-01',
            'loa_ref_no' => 'LOA26-001AA',
        ]);
    }

    public function test_linked_user_can_list_project_vendors(): void
    {
        $this->actingSession(10)
            ->getJson('/projects/501/vendors')
            ->assertOk()
            ->assertJsonPath('vendors.0.vendor_id', 7);
    }

    public function test_unlinked_user_cannot_list_project_vendors(): void
    {
        $this->actingSession(20)
            ->getJson('/projects/501/vendors')
            ->assertStatus(403);
    }

    public function test_unlinked_user_cannot_assign_vendor_to_project(): void
    {
        $this->actingSession(20)
            ->postJson('/projects/777/vendors', $this->assignPayload())
            ->assertStatus(403);
    }

    public function test_manager_can_list_project_vendors_without_project_link(): void
    {
        $this->actingSession(30, ['Manager'])
            ->getJson('/projects/501/vendors')
            ->assertOk()
            ->assertJsonPath('vendors.0.vendor_id', 7);
    }

    private function assignPayload(): array
    {
        return [
            'vendor_id' => 7,
            'award_value' => 250,
            'award_date' => '2026-05-29',
            'position' => 'Trainer',
        ];
    }

    private function actingSession(int $staffId, array $roles = ['Staff'])
    {
        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => $staffId,
                'staff_id' => $staffId,
                'roles' => $roles,
                'name_code' => "S{$staffId}",
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }
}
