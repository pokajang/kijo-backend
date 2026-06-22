<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliveryOrderProjectLinkUpdateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            RequireAuth::class,
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);

        Schema::dropIfExists('user_activities');
        Schema::dropIfExists('do_breakdown');
        Schema::dropIfExists('do_details');

        Schema::create('do_details', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('do_number')->nullable();
            $table->string('client_name');
            $table->text('client_address');
            $table->text('client_contact_name');
            $table->text('client_contact_position');
            $table->text('client_contact_email');
            $table->text('client_contact_phone');
            $table->string('company_contact_name');
            $table->string('company_contact_email')->nullable();
            $table->string('company_contact_phone')->nullable();
            $table->unsignedInteger('project_id')->nullable();
            $table->string('project_name');
            $table->string('project_code');
            $table->date('project_award_date');
            $table->string('project_type')->nullable();
            $table->text('project_description')->nullable();
            $table->string('project_service_period')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('do_breakdown', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('do_id');
            $table->string('item_name');
            $table->text('description');
            $table->decimal('quantity', 10, 2);
            $table->string('unit')->nullable();
            $table->timestamps();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_update_preserves_existing_project_id_when_payload_omits_it(): void
    {
        $this->insertDeliveryOrder(['project_id' => 501]);

        $this->actingSession()
            ->putJson('/delivery-orders/1', $this->payload())
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('do_details', [
            'id' => 1,
            'project_id' => 501,
            'project_name' => 'Updated Project',
        ]);
    }

    public function test_update_saves_project_id_when_payload_supplies_it(): void
    {
        $this->insertDeliveryOrder(['project_id' => 501]);

        $this->actingSession()
            ->putJson('/delivery-orders/1', $this->payload(['project_id' => 777]))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('do_details', [
            'id' => 1,
            'project_id' => 777,
            'project_name' => 'Updated Project',
        ]);
    }

    private function insertDeliveryOrder(array $overrides = []): void
    {
        DB::table('do_details')->insert(array_merge([
            'id' => 1,
            'do_number' => 'DO26-001ACC1',
            'client_name' => 'Client A',
            'client_address' => 'Address A',
            'client_contact_name' => 'PIC A',
            'client_contact_position' => 'Manager',
            'client_contact_email' => 'pic@example.com',
            'client_contact_phone' => '0123456789',
            'company_contact_name' => 'Issuer A',
            'company_contact_email' => 'issuer@example.com',
            'company_contact_phone' => '0399999999',
            'project_id' => null,
            'project_name' => 'Original Project',
            'project_code' => 'P001',
            'project_award_date' => '2026-06-01',
            'project_type' => 'Special',
            'project_description' => 'Original scope',
            'project_service_period' => 'June 2026',
            'created_by' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function payload(array $detailsOverrides = []): array
    {
        return [
            'details' => array_merge([
                'client_name' => 'Client B',
                'client_address' => 'Address B',
                'client_contact_name' => 'PIC B',
                'client_contact_position' => 'Director',
                'client_contact_email' => 'pic-b@example.com',
                'client_contact_phone' => '0111111111',
                'company_contact_name' => 'Issuer B',
                'company_contact_email' => 'issuer-b@example.com',
                'company_contact_phone' => '0388888888',
                'project_name' => 'Updated Project',
                'project_code' => 'P002',
                'project_award_date' => '2026-06-15',
                'project_type' => 'Special Service',
                'project_description' => 'Updated scope',
                'project_service_period' => 'June to July 2026',
            ], $detailsOverrides),
            'breakdown' => [
                [
                    'item_name' => 'Updated work',
                    'description' => 'Updated description',
                    'quantity' => 1,
                    'unit' => 'Lot',
                ],
            ],
        ];
    }

    private function actingSession()
    {
        $session = ['user_id' => 1, 'staff_id' => 10, 'name_code' => 'ACC1'];

        $this->app['session']->start();
        $this->app['session']->put($session + ['_token' => 'test-token']);

        return $this
            ->withSession($session + ['_token' => 'test-token'])
            ->withCookie(config('session.cookie'), $this->app['session']->getId())
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }
}
