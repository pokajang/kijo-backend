<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientStatusInvoiceSyncFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            RequireAuth::class,
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);

        $this->createTables();
    }

    public function test_manual_refresh_marks_new_and_blank_clients_with_real_invoices_as_old(): void
    {
        DB::table('client_company')->insert([
            ['company_id' => 1, 'company_name' => 'New Client', 'client_status' => 'New', 'deleted_at' => null],
            ['company_id' => 2, 'company_name' => 'Blank Client', 'client_status' => '', 'deleted_at' => null],
            ['company_id' => 3, 'company_name' => 'Null Client', 'client_status' => null, 'deleted_at' => null],
            ['company_id' => 4, 'company_name' => 'Old Client', 'client_status' => 'Old', 'deleted_at' => null],
            ['company_id' => 5, 'company_name' => 'Void Client', 'client_status' => 'New', 'deleted_at' => null],
            ['company_id' => 6, 'company_name' => 'Cancelled Client', 'client_status' => 'New', 'deleted_at' => null],
            ['company_id' => 7, 'company_name' => 'Deleted Client', 'client_status' => 'New', 'deleted_at' => now()],
        ]);

        DB::table('invoices')->insert([
            ['client_id' => 1, 'invoice_ref_no' => 'INV-001', 'status' => 'Pending'],
            ['client_id' => 2, 'invoice_ref_no' => 'INV-002', 'status' => 'Paid'],
            ['client_id' => 3, 'invoice_ref_no' => 'INV-003', 'status' => null],
            ['client_id' => 4, 'invoice_ref_no' => 'INV-004', 'status' => 'Pending'],
            ['client_id' => 5, 'invoice_ref_no' => 'INV-005', 'status' => 'Void'],
            ['client_id' => 6, 'invoice_ref_no' => 'INV-006', 'status' => 'Cancelled'],
            ['client_id' => 7, 'invoice_ref_no' => 'INV-007', 'status' => 'Paid'],
        ]);

        $this->postJson('/client-companies/refresh-status-from-invoices')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Client statuses refreshed.')
            ->assertJsonPath('data.updated_count', 3);

        foreach ([1, 2, 3, 4] as $companyId) {
            $this->assertDatabaseHas('client_company', [
                'company_id' => $companyId,
                'client_status' => 'Old',
            ]);
        }

        foreach ([5, 6, 7] as $companyId) {
            $this->assertDatabaseHas('client_company', [
                'company_id' => $companyId,
                'client_status' => 'New',
            ]);
        }
    }

    public function test_invoice_creation_marks_linked_new_client_as_old(): void
    {
        DB::table('client_company')->insert([
            'company_id' => 10,
            'company_name' => 'Invoice Client',
            'client_status' => 'New',
            'deleted_at' => null,
        ]);

        DB::table('projects_main')->insert([
            'id' => 100,
            'client_id' => 10,
            'project_name' => 'Manual Manpower Project',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingSession()
            ->postJson('/invoices', [
                'project_id' => 100,
                'service_type' => 'Manpower Supply',
                'invoice_purpose' => 'Monthly manpower supply',
                'invoice_date' => '2026-05-26',
                'amount' => 100,
                'sst_amount' => 0,
                'grand_total' => 100,
                'payment_method' => 'Direct Payment',
                'breakdown' => [
                    [
                        'item_description' => 'Monthly manpower supply',
                        'unit' => 'Lot',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'subtotal' => 100,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('client_company', [
            'company_id' => 10,
            'client_status' => 'Old',
        ]);
    }

    private function createTables(): void
    {
        Schema::dropIfExists('invoice_payment_reminder_logs');
        Schema::dropIfExists('invoice_breakdown');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('project_progress');
        Schema::dropIfExists('projects_main');
        Schema::dropIfExists('user_activities');
        Schema::dropIfExists('client_company');

        Schema::create('client_company', function (Blueprint $table): void {
            $table->integer('company_id')->primary();
            $table->string('company_name')->nullable();
            $table->string('client_status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('client_id')->nullable();
            $table->string('project_name')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id')->nullable();
            $table->integer('client_id')->nullable();
            $table->integer('quote_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->string('invoice_loa_no')->nullable();
            $table->string('invoice_client_name')->nullable();
            $table->string('invoice_client_ssm')->nullable();
            $table->string('invoice_client_tin')->nullable();
            $table->string('invoice_client_address')->nullable();
            $table->string('invoice_client_city')->nullable();
            $table->string('invoice_client_state')->nullable();
            $table->string('invoice_client_zip')->nullable();
            $table->string('invoice_pic_name')->nullable();
            $table->string('invoice_pic_phone')->nullable();
            $table->string('invoice_pic_email')->nullable();
            $table->string('invoice_pic_position')->nullable();
            $table->string('service_type')->nullable();
            $table->string('invoice_ref_no')->nullable();
            $table->integer('invoice_running_no')->nullable();
            $table->string('invoice_purpose')->nullable();
            $table->date('invoice_date')->nullable();
            $table->integer('payment_terms_days')->nullable();
            $table->string('payment_terms_source')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('sst_amount', 12, 2)->nullable();
            $table->decimal('grand_total', 12, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('grant_approval_no')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->text('paid_remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_breakdown', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('invoice_id');
            $table->string('item_description')->nullable();
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->integer('sort_order')->nullable();
        });

        Schema::create('project_progress', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->date('progress_date');
            $table->text('progress_text');
            $table->integer('updated_by')->nullable();
            $table->timestamp('updated_on')->nullable();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function actingSession()
    {
        $this->app['session']->start();
        $this->app['session']->put([
            'user_id' => 1,
            'staff_id' => 10,
            'name_code' => 'EMP',
            '_token' => 'test-token',
        ]);

        return $this
            ->withSession([
                'user_id' => 1,
                'staff_id' => 10,
                'name_code' => 'EMP',
                '_token' => 'test-token',
            ])
            ->withCookie(config('session.cookie'), $this->app['session']->getId())
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }
}
