<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AppNotificationSummaryFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-19 09:00:00');

        $this->withoutMiddleware([
            \App\Http\Middleware\RequireAuth::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ]);

        foreach ([
            'quote_price_exception_requests',
            'quotes_training',
            'quotes_manpower',
            'client_vendor_registration_recipients',
            'client_vendor_registrations',
            'in_app_notifications',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('client_vendor_registrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('client_id');
            $table->date('valid_from');
            $table->date('valid_until');
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('client_vendor_registration_recipients', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('registration_id');
            $table->integer('staff_id');
            $table->timestamps();
        });

        foreach (['quotes_training', 'quotes_manpower'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->increments('id');
                $table->string('status')->nullable();
            });
        }

        Schema::create('quote_price_exception_requests', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('request_type')->default('quote');
            $table->string('service_group');
            $table->integer('quote_id');
            $table->string('status')->default('pending');
            $table->integer('requested_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('in_app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('recipient_staff_id');
            $table->unsignedBigInteger('actor_staff_id')->nullable();
            $table->string('module_key');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('type');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('route')->nullable();
            $table->string('severity')->default('info');
            $table->json('metadata_json')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_exposes_user_targeted_vendor_registration_badges(): void
    {
        DB::table('client_vendor_registrations')->insert([
            [
                'id' => 1,
                'client_id' => 1,
                'valid_from' => '2025-01-01',
                'valid_until' => '2026-05-01',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'client_id' => 1,
                'valid_from' => '2025-01-01',
                'valid_until' => '2026-05-01',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'client_id' => 1,
                'valid_from' => '2026-01-01',
                'valid_until' => '2026-06-15',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ]);
        DB::table('client_vendor_registration_recipients')->insert([
            ['registration_id' => 1, 'staff_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['registration_id' => 2, 'staff_id' => 11, 'created_at' => now(), 'updated_at' => now()],
            ['registration_id' => 3, 'staff_id' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $summary = $this->withSession(['staff_id' => 10, 'roles' => ['Employee']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $summary['by_module']['client.vendor_registration'] ?? 0);
        $this->assertSame(1, $summary['by_route_group']['/client/manage'] ?? 0);
        $this->assertSame(1, $summary['by_tab']['client.vendor-registration'] ?? 0);
    }

    public function test_summary_preserves_negotiation_pending_and_ready_to_apply_badges(): void
    {
        DB::table('quotes_training')->insert([
            ['id' => 1, 'status' => 'Open'],
            ['id' => 2, 'status' => 'Pending'],
        ]);
        DB::table('quotes_manpower')->insert([
            ['id' => 1, 'status' => 'Open'],
        ]);
        DB::table('quote_price_exception_requests')->insert([
            [
                'request_type' => 'quote',
                'service_group' => 'training',
                'quote_id' => 1,
                'status' => 'pending',
                'requested_by_id' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'request_type' => 'quote',
                'service_group' => 'manpower',
                'quote_id' => 1,
                'status' => 'pending',
                'requested_by_id' => 11,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'request_type' => 'quote',
                'service_group' => 'training',
                'quote_id' => 2,
                'status' => 'approved',
                'requested_by_id' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $managerSummary = $this->withSession(['staff_id' => 30, 'roles' => ['Manager']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $managerSummary['by_module']['crm.negotiations'] ?? 0);
        $this->assertSame(2, $managerSummary['by_route_group']['/crm/price-exceptions'] ?? 0);

        $requesterSummary = $this->withSession(['staff_id' => 10, 'roles' => ['Employee']])
            ->getJson('/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $requesterSummary['by_module']['crm.negotiations'] ?? 0);
        $this->assertSame(1, $requesterSummary['by_route_group']['/crm/price-exceptions'] ?? 0);
        $this->assertSame(1, $requesterSummary['by_tab']['crm.negotiations'] ?? 0);
    }
}
