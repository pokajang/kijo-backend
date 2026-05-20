<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientRoiReportFeatureTest extends TestCase
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

    public function test_client_roi_report_aggregates_revenue_costs_and_manual_debtors(): void
    {
        $response = $this->getJson('/client-companies/roi?start=2026-05-01&end=2026-05-31')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $rows = collect($response->json('data.rows'));
        $alpha = $rows->firstWhere('company_id', 1);
        $beta = $rows->firstWhere('company_id', 2);

        $this->assertNotNull($alpha);
        $this->assertSame(1, $alpha['awarded_project_count']);
        $this->assertSame(1000.0, (float) $alpha['awarded_value']);
        $this->assertSame(3, $alpha['invoice_count']);
        $this->assertSame(1100.0, (float) $alpha['invoiced_total']);
        $this->assertSame(2, $alpha['received_count']);
        $this->assertSame(750.0, (float) $alpha['received_total']);
        $this->assertSame(250.0, (float) $alpha['vendor_cost']);
        $this->assertSame(25.0, (float) $alpha['expense_cost']);
        $this->assertSame(275.0, (float) $alpha['total_cost']);
        $this->assertSame(475.0, (float) $alpha['actual_profit']);
        $this->assertSame(725.0, (float) $alpha['projected_profit']);
        $this->assertSame(172.73, (float) $alpha['actual_roi_percent']);
        $this->assertSame(263.64, (float) $alpha['projected_roi_percent']);
        $this->assertSame(63.33, (float) $alpha['actual_margin_percent']);
        $this->assertSame(72.5, (float) $alpha['projected_margin_percent']);
        $this->assertSame(8.0, (float) $alpha['average_payment_days']);
        $this->assertSame('2026-05-20', $alpha['last_paid_date']);

        $this->assertNotNull($beta);
        $this->assertSame(100.0, (float) $beta['received_total']);
        $this->assertSame(1.0, (float) $beta['average_payment_days']);
        $this->assertNull($beta['actual_roi_percent']);
        $this->assertNull($beta['projected_roi_percent']);
    }

    public function test_client_roi_report_rejects_invalid_date_range(): void
    {
        $this->getJson('/client-companies/roi?start=2026-06-01&end=2026-05-01')
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->getJson('/client-companies/roi?start=2026-99-99')
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    private function createTables(): void
    {
        Schema::create('client_company', function (Blueprint $table): void {
            $table->integer('company_id')->primary();
            $table->string('company_name')->nullable();
            $table->string('client_status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('client_id')->nullable();
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
    }

    private function seedRows(): void
    {
        DB::table('client_company')->insert([
            ['company_id' => 1, 'company_name' => 'Alpha Client', 'client_status' => 'New'],
            ['company_id' => 2, 'company_name' => 'Beta Client', 'client_status' => 'Old'],
        ]);

        DB::table('projects_main')->insert([
            ['id' => 10, 'client_id' => 1, 'project_name' => 'Alpha May', 'quote_value' => 1000, 'award_date' => '2026-05-05', 'status' => 'Active'],
            ['id' => 11, 'client_id' => 1, 'project_name' => 'Alpha April', 'quote_value' => 500, 'award_date' => '2026-04-01', 'status' => 'Active'],
            ['id' => 12, 'client_id' => 1, 'project_name' => 'Alpha Terminated', 'quote_value' => 999, 'award_date' => '2026-05-07', 'status' => 'Terminated'],
            ['id' => 20, 'client_id' => 2, 'project_name' => 'Beta May', 'quote_value' => 100, 'award_date' => '2026-05-10', 'status' => 'Active'],
        ]);

        DB::table('invoices')->insert([
            ['client_id' => 1, 'project_id' => 10, 'invoice_ref_no' => 'INV-001', 'invoice_date' => '2026-05-10', 'grand_total' => 700, 'status' => 'Paid', 'paid_date' => '2026-05-20', 'paid_amount' => 650],
            ['client_id' => 1, 'project_id' => 10, 'invoice_ref_no' => 'INV-002', 'invoice_date' => '2026-05-21', 'grand_total' => 300, 'status' => 'Pending', 'paid_date' => null, 'paid_amount' => null],
            ['client_id' => 1, 'project_id' => 11, 'invoice_ref_no' => 'INV-003', 'invoice_date' => '2026-04-15', 'grand_total' => 400, 'status' => 'Paid', 'paid_date' => '2026-04-25', 'paid_amount' => 400],
            ['client_id' => 2, 'project_id' => 20, 'invoice_ref_no' => 'INV-004', 'invoice_date' => '2026-05-11', 'grand_total' => 100, 'status' => 'Paid', 'paid_date' => '2026-05-12', 'paid_amount' => 100],
        ]);

        DB::table('manual_debtors')->insert([
            ['client_id' => 1, 'invoice_ref_no' => 'MAN-001', 'invoice_date' => '2026-05-12', 'grand_total' => 100, 'status' => 'Paid', 'paid_date' => '2026-05-18', 'paid_amount' => 100],
            ['client_id' => null, 'invoice_ref_no' => 'MAN-002', 'invoice_date' => '2026-05-12', 'grand_total' => 999, 'status' => 'Paid', 'paid_date' => '2026-05-18', 'paid_amount' => 999],
            ['client_id' => 1, 'invoice_ref_no' => 'MAN-003', 'invoice_date' => '2026-05-12', 'grand_total' => 888, 'status' => 'Void', 'paid_date' => null, 'paid_amount' => null],
        ]);

        DB::table('vendor_payments')->insert([
            ['project_id' => 10, 'amount' => 200, 'status' => 'Approved', 'deleted_at' => null],
            ['project_id' => 11, 'amount' => 50, 'status' => 'Paid', 'deleted_at' => null],
            ['project_id' => 10, 'amount' => 999, 'status' => 'Pending', 'deleted_at' => null],
            ['project_id' => 10, 'amount' => 999, 'status' => 'Approved', 'deleted_at' => now()],
        ]);

        DB::table('project_expenses')->insert([
            ['project_id' => 10, 'amount' => 25],
        ]);
    }
}
