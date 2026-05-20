<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientCommercialHistoryFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \App\Http\Middleware\RequireAuth::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ]);

        $this->createTables();
        $this->seedRows();
    }

    public function test_client_commercial_history_returns_period_scoped_rows_and_matching_roi_summary(): void
    {
        $historyResponse = $this->getJson('/client-companies/1/commercial-history?start=2026-05-01&end=2026-05-31')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $data = $historyResponse->json('data');
        $this->assertSame('Alpha Client', $data['client']['company_name']);
        $this->assertSame('negotiated', $data['client']['payment_terms_source']);
        $this->assertSame(1, $data['summary']['awarded_project_count']);
        $this->assertSame(1000.0, (float) $data['summary']['awarded_value']);
        $this->assertSame(1100.0, (float) $data['summary']['invoiced_total']);
        $this->assertSame(750.0, (float) $data['summary']['received_total']);
        $this->assertSame(475.0, (float) $data['summary']['actual_profit']);

        $payments = collect($data['payments']);
        $this->assertCount(2, $payments);
        $this->assertTrue($payments->contains(fn ($row) => $row['source_type'] === 'system_invoice' && $row['invoice_ref_no'] === 'INV-001'));
        $this->assertTrue($payments->contains(fn ($row) => $row['source_type'] === 'manual_debtor' && $row['invoice_ref_no'] === 'MAN-001'));
        $this->assertFalse($payments->contains('invoice_ref_no', 'MAN-UNLINKED'));
        $this->assertFalse($payments->contains('invoice_ref_no', 'INV-OLD'));

        $invoices = collect($data['invoices']);
        $this->assertCount(4, $invoices);
        $this->assertTrue($invoices->contains('invoice_ref_no', 'INV-VOID'));
        $this->assertFalse($invoices->contains('invoice_ref_no', 'MAN-UNLINKED'));

        $quotes = collect($data['quotes']);
        $this->assertCount(1, $quotes);
        $this->assertSame('QT-001', $quotes->first()['quote_ref_no']);

        $roiResponse = $this->getJson('/client-companies/roi?start=2026-05-01&end=2026-05-31')
            ->assertOk();
        $roiRow = collect($roiResponse->json('data.rows'))->firstWhere('company_id', 1);
        $this->assertSame($roiRow['actual_profit'], $data['summary']['actual_profit']);
        $this->assertSame($roiRow['actual_roi_percent'], $data['summary']['actual_roi_percent']);
    }

    public function test_client_commercial_history_all_time_is_unbounded_and_rejects_bad_dates(): void
    {
        $response = $this->getJson('/client-companies/1/commercial-history')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $data = $response->json('data');
        $this->assertTrue(collect($data['payments'])->contains('invoice_ref_no', 'INV-OLD'));
        $this->assertTrue(collect($data['quotes'])->contains('quote_ref_no', 'QE-OLD'));
        $this->assertSame(1500.0, (float) $data['summary']['awarded_value']);

        $this->getJson('/client-companies/1/commercial-history?start=2026-99-99')
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_quote_history_returns_one_row_per_quote_when_multiple_projects_are_linked(): void
    {
        DB::table('projects_main')->insert([
            'id' => 12,
            'client_id' => 1,
            'quote_id' => 100,
            'project_type' => 'Training',
            'project_name' => 'Alpha May Reaward',
            'quote_value' => 1200,
            'award_date' => '2026-05-25',
            'status' => 'Active',
        ]);

        $response = $this->getJson('/client-companies/1/commercial-history?start=2026-05-01&end=2026-05-31')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $quotes = collect($response->json('data.quotes'))->where('quote_ref_no', 'QT-001')->values();
        $this->assertCount(1, $quotes);
        $this->assertSame(12, (int) $quotes->first()['project_id']);
        $this->assertSame('Alpha May Reaward', $quotes->first()['project_name']);
        $this->assertSame(2, (int) $quotes->first()['project_count']);
    }

    private function createTables(): void
    {
        Schema::create('client_company', function (Blueprint $table): void {
            $table->integer('company_id')->primary();
            $table->string('company_name')->nullable();
            $table->string('client_status')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->string('payment_terms_source')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('client_id')->nullable();
            $table->integer('quote_id')->nullable();
            $table->string('project_type')->nullable();
            $table->string('project_name')->nullable();
            $table->decimal('quote_value', 15, 2)->nullable();
            $table->date('award_date')->nullable();
            $table->string('status')->nullable();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('client_id')->nullable();
            $table->integer('project_id')->nullable();
            $table->string('invoice_ref_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('grand_total', 15, 2)->nullable();
            $table->string('status')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->string('payment_method')->nullable();
        });

        Schema::create('manual_debtors', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('client_id')->nullable();
            $table->string('invoice_ref_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('grand_total', 15, 2)->nullable();
            $table->string('status')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->string('payment_method')->nullable();
        });

        Schema::create('vendor_payments', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('project_expenses', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
        });

        foreach (['quotes_training', 'quotes_equipment'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('client_id')->nullable();
                $table->string('quote_ref_no')->nullable();
                $table->string('status')->nullable();
                $table->decimal('grand_total', 15, 2)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    private function seedRows(): void
    {
        DB::table('client_company')->insert([
            ['company_id' => 1, 'company_name' => 'Alpha Client', 'client_status' => 'New', 'payment_terms_days' => 14, 'payment_terms_source' => 'negotiated'],
            ['company_id' => 2, 'company_name' => 'Beta Client', 'client_status' => 'Old', 'payment_terms_days' => null, 'payment_terms_source' => null],
        ]);

        DB::table('quotes_training')->insert([
            ['id' => 100, 'client_id' => 1, 'quote_ref_no' => 'QT-001', 'status' => 'Awarded', 'grand_total' => 1000, 'created_at' => '2026-05-01 08:00:00', 'updated_at' => '2026-05-02 08:00:00'],
        ]);

        DB::table('quotes_equipment')->insert([
            ['id' => 200, 'client_id' => 1, 'quote_ref_no' => 'QE-OLD', 'status' => 'Awarded', 'grand_total' => 500, 'created_at' => '2026-04-01 08:00:00', 'updated_at' => '2026-04-02 08:00:00'],
        ]);

        DB::table('projects_main')->insert([
            ['id' => 10, 'client_id' => 1, 'quote_id' => 100, 'project_type' => 'Training', 'project_name' => 'Alpha May', 'quote_value' => 1000, 'award_date' => '2026-05-05', 'status' => 'Active'],
            ['id' => 11, 'client_id' => 1, 'quote_id' => 200, 'project_type' => 'Equipment Supply', 'project_name' => 'Alpha April', 'quote_value' => 500, 'award_date' => '2026-04-01', 'status' => 'Active'],
        ]);

        DB::table('invoices')->insert([
            ['client_id' => 1, 'project_id' => 10, 'invoice_ref_no' => 'INV-001', 'invoice_date' => '2026-05-10', 'grand_total' => 700, 'status' => 'Paid', 'paid_date' => '2026-05-20', 'paid_amount' => 650, 'payment_method' => 'Bank Transfer'],
            ['client_id' => 1, 'project_id' => 10, 'invoice_ref_no' => 'INV-002', 'invoice_date' => '2026-05-21', 'grand_total' => 300, 'status' => 'Pending', 'paid_date' => null, 'paid_amount' => null, 'payment_method' => null],
            ['client_id' => 1, 'project_id' => 10, 'invoice_ref_no' => 'INV-VOID', 'invoice_date' => '2026-05-22', 'grand_total' => 999, 'status' => 'Void', 'paid_date' => null, 'paid_amount' => null, 'payment_method' => null],
            ['client_id' => 1, 'project_id' => 11, 'invoice_ref_no' => 'INV-OLD', 'invoice_date' => '2026-04-15', 'grand_total' => 400, 'status' => 'Paid', 'paid_date' => '2026-04-25', 'paid_amount' => 400, 'payment_method' => 'Cash'],
        ]);

        DB::table('manual_debtors')->insert([
            ['client_id' => 1, 'invoice_ref_no' => 'MAN-001', 'invoice_date' => '2026-05-12', 'grand_total' => 100, 'status' => 'Paid', 'paid_date' => '2026-05-18', 'paid_amount' => 100, 'payment_method' => 'Bank Transfer'],
            ['client_id' => null, 'invoice_ref_no' => 'MAN-UNLINKED', 'invoice_date' => '2026-05-12', 'grand_total' => 999, 'status' => 'Paid', 'paid_date' => '2026-05-18', 'paid_amount' => 999, 'payment_method' => 'Bank Transfer'],
        ]);

        DB::table('vendor_payments')->insert([
            ['project_id' => 10, 'amount' => 200, 'status' => 'Approved', 'deleted_at' => null],
            ['project_id' => 11, 'amount' => 50, 'status' => 'Paid', 'deleted_at' => null],
        ]);

        DB::table('project_expenses')->insert([
            ['project_id' => 10, 'amount' => 25],
        ]);
    }
}
