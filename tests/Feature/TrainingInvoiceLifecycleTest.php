<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrainingInvoiceLifecycleTest extends TestCase
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

    public function test_training_store_rejects_reconciled_total_only_if_hrd_is_not_excluded_from_tax_math(): void
    {
        $this->seedInvoiceProjectContext();
        $this->actingSession();

        $response = $this->postJson('/invoices', [
            'project_id' => 100,
            'service_type' => 'Training',
            'quote_id' => 501,
            'invoice_purpose' => 'Training program',
            'invoice_date' => '2026-07-01',
            'amount' => 100,
            'sst_amount' => 0,
            'grand_total' => 110,
            'payment_method' => 'HRD Grant',
            'grant_approval_no' => 'HRD-001',
            'invoice_client_name' => 'Training Client',
            'invoice_pic_name' => 'Billing Team',
            'breakdown' => [
                [
                    'item_description' => 'Training Fee',
                    'unit' => 'Lot',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'subtotal' => 100,
                ],
                [
                    'item_description' => '10% HRD Charge',
                    'unit' => 'Lot',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'subtotal' => 10,
                ],
            ],
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');
        $invoiceRef = $response->json('invoice_ref_no');

        $this->assertDatabaseHas('invoices', [
            'invoice_ref_no' => $invoiceRef,
            'service_type' => 'Training',
            'grant_approval_no' => 'HRD-001',
        ]);
        $this->assertDatabaseHas('invoice_breakdown', [
            'invoice_id' => DB::table('invoices')->where('invoice_ref_no', $invoiceRef)->value('id'),
            'item_description' => '10% HRD Charge',
            'unit_price' => 10,
        ]);
    }

    public function test_training_store_strips_system_hrd_rows_only_for_direct_payment(): void
    {
        $this->seedInvoiceProjectContext();
        $this->actingSession();

        $response = $this->postJson('/invoices', [
            'project_id' => 100,
            'service_type' => 'Training',
            'quote_id' => 502,
            'invoice_purpose' => 'Direct training invoice',
            'invoice_date' => '2026-07-02',
            'amount' => 57,
            'sst_amount' => 0,
            'grand_total' => 57,
            'payment_method' => 'Direct Payment',
            'invoice_client_name' => 'Training Client',
            'invoice_pic_name' => 'Billing Team',
            'breakdown' => [
                ['item_description' => 'Training Fee', 'unit' => 'Lot', 'quantity' => 1, 'unit_price' => 50],
                ['item_description' => '10% HRD Charge', 'unit' => 'Lot', 'quantity' => 1, 'unit_price' => 10],
                ['item_description' => 'Custom HRD Chargeback', 'unit' => 'Lot', 'quantity' => 1, 'unit_price' => 7],
            ],
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');
        $invoiceId = DB::table('invoices')->where('invoice_ref_no', $response->json('invoice_ref_no'))->value('id');

        $this->assertDatabaseMissing('invoice_breakdown', [
            'invoice_id' => $invoiceId,
            'item_description' => '10% HRD Charge',
        ]);
        $this->assertDatabaseHas('invoice_breakdown', [
            'invoice_id' => $invoiceId,
            'item_description' => 'Custom HRD Chargeback',
            'unit_price' => 7,
        ]);
    }

    public function test_training_update_keeps_additional_lines_and_required_hrd_rules(): void
    {
        $this->seedInvoiceProjectContext();
        $this->actingSession();

        DB::table('invoices')->insert([
            'id' => 1,
            'project_id' => 100,
            'client_id' => 10,
            'created_by' => 20,
            'service_type' => 'Training',
            'invoice_ref_no' => 'INV-TRAIN-1',
            'invoice_purpose' => 'Primary training invoice',
            'invoice_date' => '2026-07-01',
            'amount' => 120,
            'sst_amount' => 0,
            'grand_total' => 120,
            'payment_method' => 'HRD Grant',
            'grant_approval_no' => 'HRD-UNIQUE-001',
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoices')->insert([
            'id' => 2,
            'project_id' => 100,
            'client_id' => 10,
            'created_by' => 20,
            'service_type' => 'Training',
            'invoice_ref_no' => 'INV-TRAIN-2',
            'invoice_purpose' => 'Secondary training invoice',
            'invoice_date' => '2026-07-05',
            'amount' => 50,
            'sst_amount' => 0,
            'grand_total' => 50,
            'payment_method' => 'Direct Payment',
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_breakdown')->insert([
            [
                'id' => 1,
                'invoice_id' => 1,
                'item_description' => 'Training Fee',
                'unit' => 'Lot',
                'quantity' => 1,
                'unit_price' => 120,
                'subtotal' => 120,
                'sort_order' => 1,
            ],
            [
                'id' => 2,
                'invoice_id' => 2,
                'item_description' => 'Training Fee',
                'unit' => 'Lot',
                'quantity' => 1,
                'unit_price' => 50,
                'subtotal' => 50,
                'sort_order' => 1,
            ],
        ]);

        $duplicateResponse = $this->putJson('/invoices', [
            'invoice_ref_no' => 'INV-TRAIN-2',
            'invoice_date' => '2026-07-06',
            'status' => 'Pending',
            'payment_method' => 'HRD Grant',
            'grant_approval_no' => 'HRD-UNIQUE-001',
            'invoice_client_name' => 'Training Client',
            'invoice_pic_name' => 'Billing Team',
            'amount' => 50,
            'sst_amount' => 0,
            'grand_total' => 50,
            'breakdown' => [
                ['item_description' => 'Training Fee', 'unit' => 'Lot', 'quantity' => 1, 'unit_price' => 50],
            ],
        ]);
        $duplicateResponse->assertOk()
            ->assertJsonPath('status', 'exists')
            ->assertJsonPath('message', 'This HRD Grant Approval No. is already used.');

        $this->putJson('/invoices', [
            'invoice_ref_no' => 'INV-TRAIN-2',
            'invoice_date' => '2026-07-06',
            'status' => 'Pending',
            'payment_method' => 'Direct Payment',
            'invoice_client_name' => 'Training Client',
            'invoice_pic_name' => 'Billing Team',
            'amount' => 62,
            'sst_amount' => 0,
            'grand_total' => 62,
            'breakdown' => [
                ['item_description' => 'Training Fee', 'unit' => 'Lot', 'quantity' => 1, 'unit_price' => 50],
                ['item_description' => '10% HRD Charge', 'unit' => 'Lot', 'quantity' => 1, 'unit_price' => 10],
                ['item_description' => 'Custom Workbook', 'unit' => 'Lot', 'quantity' => 1, 'unit_price' => 5],
                ['item_description' => 'Custom HRD Chargeback', 'unit' => 'Lot', 'quantity' => 1, 'unit_price' => 7],
            ],
        ]);
        $invoiceId = (int) DB::table('invoices')->where('invoice_ref_no', 'INV-TRAIN-2')->value('id');
        $this->assertDatabaseMissing('invoice_breakdown', [
            'invoice_id' => $invoiceId,
            'item_description' => '10% HRD Charge',
        ]);
        $this->assertDatabaseHas('invoice_breakdown', [
            'invoice_id' => $invoiceId,
            'item_description' => 'Custom Workbook',
            'unit_price' => 5,
        ]);
        $this->assertDatabaseHas('invoice_breakdown', [
            'invoice_id' => $invoiceId,
            'item_description' => 'Custom HRD Chargeback',
            'unit_price' => 7,
        ]);

        $hddResponse = $this->putJson('/invoices', [
            'invoice_ref_no' => 'INV-TRAIN-2',
            'invoice_date' => '2026-07-06',
            'status' => 'Pending',
            'payment_method' => 'HRD Grant',
            'grant_approval_no' => '',
            'invoice_client_name' => 'Training Client',
            'invoice_pic_name' => 'Billing Team',
            'amount' => 50,
            'sst_amount' => 0,
            'grand_total' => 50,
            'breakdown' => [
                ['item_description' => 'Training Fee', 'unit' => 'Lot', 'quantity' => 1, 'unit_price' => 50],
            ],
        ]);
        $hddResponse->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'HRD Grant Approval No. is required for HRD payment.');
    }

    private function actingSession(): self
    {
        $session = ['user_id' => 1, 'staff_id' => 20, 'name_code' => 'ACC1'];
        $this->app['session']->start();
        $this->app['session']->put($session + ['_token' => 'test-token']);

        return $this
            ->withSession($session + ['_token' => 'test-token'])
            ->withCookie(config('session.cookie'), $this->app['session']->getId())
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }

    private function seedInvoiceProjectContext(): void
    {
        DB::table('client_company')->insert([
            'company_id' => 10,
            'company_name' => 'Training Client',
            'payment_terms_days' => null,
            'deleted_at' => null,
        ]);

        DB::table('projects_main')->insert([
            'id' => 100,
            'client_id' => 10,
            'project_name' => 'Onboarding Training',
            'project_type' => 'Training',
            'status' => 'Active',
            'quote_value' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTables(): void
    {
        Schema::dropIfExists('user_activities');
        Schema::dropIfExists('invoice_breakdown');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('projects_main');
        Schema::dropIfExists('client_company');

        Schema::create('client_company', function (Blueprint $table): void {
            $table->integer('company_id')->primary();
            $table->string('company_name')->nullable();
            $table->integer('payment_terms_days')->nullable();
            $table->string('client_status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('client_id')->nullable();
            $table->string('project_name')->nullable();
            $table->string('project_type')->nullable();
            $table->string('status')->nullable();
            $table->decimal('quote_value', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id')->nullable();
            $table->integer('client_id')->nullable();
            $table->integer('quote_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->string('invoice_ref_no')->nullable();
            $table->string('service_type')->nullable();
            $table->string('invoice_purpose')->nullable();
            $table->date('invoice_date')->nullable();
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
            $table->string('invoice_loa_no')->nullable();
            $table->integer('invoice_running_no')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('sst_amount', 12, 2)->nullable();
            $table->decimal('grand_total', 12, 2)->nullable();
            $table->integer('payment_terms_days')->nullable();
            $table->string('payment_terms_source')->nullable();
            $table->date('due_date')->nullable();
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

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
