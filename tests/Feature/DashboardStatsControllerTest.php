<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DashboardStatsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-11 12:00:00'));
        $this->registerSqliteDateFormat();
        $this->createDashboardTables();
        $this->seedDashboardFacts();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sales_separates_system_and_revenue_complete_manual_closed_entries(): void
    {
        $response = $this->authenticatedPost('/stats/monthly-sales', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $may = collect($response->json('monthlySales'))->firstWhere('month', '2026-05');

        $this->assertSame(5000.0, (float) $may['systemAmount']);
        $this->assertSame(1200.0, (float) $may['manualAmount']);
        $this->assertSame(6200.0, (float) $may['amount']);
        $this->assertSame(2, (int) $may['systemCount']);
        $this->assertSame(1, (int) $may['manualCount']);
        $this->assertSame(3, (int) $may['count']);
    }

    public function test_manual_closed_save_requires_revenue_complete_fields_and_normalizes_individual(): void
    {
        $this->authenticatedPost('/stats/monitoring-manual-pipeline-entry', [
            'entry_type' => 'closed',
            'entry_date' => '2026-05-09',
            'source' => 'WhatsApp Personal',
            'prospect_name' => 'Incomplete Closed',
            'service_category' => 'training',
            'estimated_rm' => '0',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Closed manual entries require Estimated RM greater than zero.');

        $this->authenticatedPost('/stats/monitoring-manual-pipeline-entry', [
            'entry_type' => 'closed',
            'entry_date' => '2026-05-09',
            'source' => 'WhatsApp Personal',
            'prospect_name' => 'Complete Closed',
            'service_category' => 'training',
            'estimated_rm' => '2500',
            'segment_type' => '',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('monitoring_manual_pipeline_entries', [
            'prospect_name' => 'Complete Closed',
            'entry_type' => 'closed',
            'segment_type' => 'individual',
            'service_category' => 'training',
        ]);
    }

    public function test_conversion_uses_quote_created_cohort_and_awarded_or_won_status(): void
    {
        $response = $this->authenticatedPost('/stats/conversion-rate-by-source', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $rows = collect($response->json('conversionRateBySource'));
        $email = $rows->firstWhere('sourceName', 'Email');
        $whatsapp = $rows->firstWhere('sourceName', 'WhatsApp');

        $this->assertSame(1, (int) $email['convertedCount']);
        $this->assertSame(1, (int) $email['totalQuotes']);
        $this->assertSame(100.0, (float) $email['conversionRate']);
        $this->assertSame(1, (int) $whatsapp['convertedCount']);
        $this->assertSame(2, (int) $whatsapp['totalQuotes']);
        $this->assertSame(50.0, (float) $whatsapp['conversionRate']);
    }

    public function test_inquiry_source_count_and_value_share_quote_fact_source(): void
    {
        $countResponse = $this->authenticatedPost('/stats/inquiry', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]);
        $valueResponse = $this->authenticatedPost('/stats/inquiry-by-values', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]);

        $countResponse->assertOk()->assertJsonPath('status', 'success');
        $valueResponse->assertOk()->assertJsonPath('status', 'success');

        $whatsappCount = collect($countResponse->json('inquiryStats'))->firstWhere('source', 'WhatsApp');
        $whatsappValue = collect($valueResponse->json('inquiryStatsByValues'))->firstWhere('source', 'WhatsApp');

        $this->assertSame(2, (int) $whatsappCount['count']);
        $this->assertSame(3999.0, (float) $whatsappValue['totalValue']);
    }

    public function test_monitoring_pipeline_tools_combines_crm_and_manual_negotiations(): void
    {
        DB::table('monitoring_manual_pipeline_entries')->insert([
            'entry_type' => 'negotiation',
            'prospect_name' => 'Manual Negotiation',
            'entry_date' => '2026-05-08',
            'source' => 'WhatsApp Personal',
            'segment_type' => 'individual',
            'service_category' => null,
            'estimated_rm' => null,
            'owner_staff_id' => 1,
            'owner_staff_code' => 'AZA',
            'owner_staff_name' => 'Azam Bin Husain',
            'created_by' => 1,
            'created_by_code' => 'AZA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertPriceExceptionRequest([
            'id' => 1,
            'quote_ref_no' => 'TR-Q-001',
            'created_at' => '2026-05-08 10:00:00',
            'requested_by_code' => 'AZA',
            'requested_by_name' => 'Azam Bin Husain',
        ]);

        $response = $this->authenticatedPost('/stats/monitoring-pipeline-tools', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $negotiation = collect($response->json('rows'))->firstWhere('label', 'NEGOTIATION');

        $this->assertSame(2, (int) $negotiation['weekly']['W2']);
        $this->assertSame(2, (int) $negotiation['total']);
        $this->assertSame(2, (int) $negotiation['individualQty']);
        $this->assertSame(0.0, (float) $negotiation['individualRm']);
        $this->assertSame(
            ['manual', 'negotiation'],
            collect($negotiation['details']['weekly']['W2']['items'])->pluck('sourceType')->sort()->values()->all()
        );
        $this->assertSame(
            ['manual', 'negotiation'],
            collect($negotiation['details']['segments']['individual']['qty']['items'])->pluck('sourceType')->sort()->values()->all()
        );
    }

    public function test_monitoring_pipeline_tools_filters_crm_negotiations_by_requester_staff_code(): void
    {
        $this->insertPriceExceptionRequest([
            'id' => 1,
            'quote_ref_no' => 'TR-Q-001',
            'created_at' => '2026-05-08 10:00:00',
            'requested_by_code' => 'AZA',
            'requested_by_name' => 'Azam Bin Husain',
        ]);
        $this->insertPriceExceptionRequest([
            'id' => 2,
            'quote_ref_no' => 'MP-Q-001',
            'service_group' => 'manpower',
            'created_at' => '2026-05-08 11:00:00',
            'requested_by_code' => 'BOB',
            'requested_by_name' => 'Bob Tester',
        ]);

        $response = $this->authenticatedPost('/stats/monitoring-pipeline-tools', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'staff_code' => 'AZA',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $negotiation = collect($response->json('rows'))->firstWhere('label', 'NEGOTIATION');
        $items = collect($negotiation['details']['weekly']['W2']['items']);

        $this->assertSame(1, (int) $negotiation['weekly']['W2']);
        $this->assertSame(['AZA'], $items->pluck('ownerStaffCode')->values()->all());
    }

    public function test_monitoring_pipeline_tools_excludes_crm_negotiations_outside_selected_month(): void
    {
        $this->insertPriceExceptionRequest([
            'id' => 1,
            'quote_ref_no' => 'TR-Q-001',
            'created_at' => '2026-05-08 10:00:00',
        ]);
        $this->insertPriceExceptionRequest([
            'id' => 2,
            'quote_ref_no' => 'TR-Q-002',
            'created_at' => '2026-04-30 10:00:00',
        ]);
        $this->insertPriceExceptionRequest([
            'id' => 3,
            'quote_ref_no' => 'TR-Q-003',
            'created_at' => '2026-06-01 10:00:00',
        ]);

        $response = $this->authenticatedPost('/stats/monitoring-pipeline-tools', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $negotiation = collect($response->json('rows'))->firstWhere('label', 'NEGOTIATION');

        $this->assertSame(1, (int) $negotiation['weekly']['W2']);
        $this->assertSame(['TR-Q-001'], collect($negotiation['details']['weekly']['W2']['items'])->pluck('quoteRefNo')->values()->all());
    }

    public function test_financial_open_receivables_are_scoped_by_as_of_date(): void
    {
        $totalsResponse = $this->authenticatedPost('/stats/monthly-income-statement', [
            'start_date' => '2026-01-01',
            'end_date' => '2026-05-11',
        ]);
        $debtorsResponse = $this->authenticatedPost('/stats/debtors', [
            'start_date' => '2026-01-01',
            'end_date' => '2026-05-11',
        ]);

        $totalsResponse->assertOk()->assertJsonPath('status', 'success');
        $debtorsResponse->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(3000.0, (float) $totalsResponse->json('outstandingAmount'));
        $this->assertSame(1, (int) $totalsResponse->json('outstandingCount'));
        $this->assertSame('2026-05-11', $debtorsResponse->json('asOfDate'));
        $this->assertSame(['INV-OPEN-001'], collect($debtorsResponse->json('debtors'))->pluck('invoice_ref_no')->all());
        $this->assertSame(30, (int) $debtorsResponse->json('debtors.0.payment_terms_days'));
        $this->assertSame('system_default', $debtorsResponse->json('debtors.0.payment_terms_source'));
    }

    public function test_financial_monthly_invoiced_received_trend_uses_invoice_and_paid_dates(): void
    {
        $response = $this->authenticatedPost('/stats/monthly-invoiced-received-trend', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $may = collect($response->json('monthlyInvoicedReceivedTrend'))->firstWhere('month', '2026-05');

        $this->assertSame(7700.0, (float) $may['invoiced']);
        $this->assertSame(700.0, (float) $may['received']);
        $this->assertSame(3, (int) $may['invoiceCount']);
        $this->assertSame(1, (int) $may['receivedCount']);
        $this->assertSame(7000.0, (float) $may['netMovement']);
    }

    public function test_period_presets_use_calendar_windows_and_custom_ranges_normalize(): void
    {
        $this->assertMonthlySalesMonths(['2026-05'], ['period' => 'currentMonth']);
        $this->assertMonthlySalesMonths(['2026-04'], ['period' => 'previousMonth']);
        $this->assertMonthlySalesMonths(['2026-01', '2026-03', '2026-04', '2026-05'], ['period' => 'currentYear']);
        $this->assertMonthlySalesMonths(['2026-03', '2026-04', '2026-05'], ['period' => '3months']);
        $this->assertMonthlySalesMonths(['2025-12', '2026-01', '2026-03', '2026-04', '2026-05'], ['period' => '6months']);
        $this->assertMonthlySalesMonths(['2026-05'], [
            'start_date' => '2026-05-31',
            'end_date' => '2026-05-01',
        ]);
    }

    private function authenticatedPost(string $uri, array $payload)
    {
        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 1,
                'name_code' => 'AZA',
                'full_name' => 'Azam Bin Husain',
                'roles' => ['System Admin'],
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson($uri, $payload);
    }

    private function assertMonthlySalesMonths(array $expectedMonths, array $payload): void
    {
        $response = $this->authenticatedPost('/stats/monthly-sales', $payload);

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertSame($expectedMonths, collect($response->json('monthlySales'))->pluck('month')->values()->all());
    }

    private function registerSqliteDateFormat(): void
    {
        $pdo = DB::connection()->getPdo();
        if (!method_exists($pdo, 'sqliteCreateFunction')) {
            return;
        }

        $pdo->sqliteCreateFunction('DATE_FORMAT', static function ($date, $format) {
            if (!$date) {
                return null;
            }

            $timestamp = strtotime((string) $date);
            if ($timestamp === false) {
                return null;
            }

            return match ($format) {
                '%Y-%m' => date('Y-m', $timestamp),
                default => date('Y-m-d', $timestamp),
            };
        }, 2);
        $pdo->sqliteCreateFunction('CONCAT', static fn(...$parts) => implode('', array_map(
            static fn($part) => (string) $part,
            $parts
        )), -1);
    }

    private function createDashboardTables(): void
    {
        foreach ([
            'monitoring_manual_pipeline_entries',
            'all_quotes',
            'invoices',
            'client_company',
            'quote_inquiry_sources',
            'quotes_training',
            'quotes_ih',
            'quotes_manpower',
            'quotes_equipment',
            'quotes_special',
            'projects_main',
            'staff_general',
            'system_users',
            'quote_price_exception_requests',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('staff_general', function (Blueprint $table) {
            $table->integer('staff_id')->primary();
            $table->string('name_code')->nullable();
            $table->string('full_name')->nullable();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 1,
            'email' => 'dashboard-admin@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => 1,
        ]);

        Schema::create('all_quotes', function (Blueprint $table) {
            $table->string('service_group');
            $table->integer('quote_id');
            $table->dateTime('created_at')->nullable();
            $table->date('award_date')->nullable();
            $table->integer('staff_id')->nullable();
            $table->string('staff_name')->nullable();
            $table->string('staff_code')->nullable();
            $table->integer('client_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('quote_status')->nullable();
            $table->decimal('value', 15, 2)->nullable();
            $table->string('inquiry_source')->nullable();
        });

        Schema::create('monitoring_manual_pipeline_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->string('entry_type', 32);
            $table->string('prospect_name');
            $table->date('entry_date');
            $table->string('source')->nullable();
            $table->string('segment_type')->nullable();
            $table->string('service_category')->nullable();
            $table->decimal('estimated_rm', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('photo_original_name')->nullable();
            $table->string('photo_mime_type')->nullable();
            $table->integer('owner_staff_id')->nullable();
            $table->string('owner_staff_code')->nullable();
            $table->string('owner_staff_name')->nullable();
            $table->integer('created_by')->nullable();
            $table->string('created_by_code')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_price_exception_requests', function (Blueprint $table) {
            $table->id();
            $table->string('service_group', 50)->default('training');
            $table->string('request_type', 30)->default('quote');
            $table->unsignedBigInteger('quote_id');
            $table->string('quote_ref_no', 100)->nullable();
            $table->unsignedInteger('revision_no_at_request')->default(0);
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->string('requested_by_name', 255)->nullable();
            $table->string('requested_by_code', 50)->nullable();
            $table->decimal('base_unit_cost', 12, 2)->default(0);
            $table->decimal('current_unit_cost', 12, 2)->default(0);
            $table->decimal('requested_unit_cost', 12, 2)->default(0);
            $table->decimal('requested_discount_amount', 12, 2)->default(0);
            $table->decimal('requested_discount_percent', 8, 4)->default(0);
            $table->decimal('current_total_amount', 12, 2)->nullable();
            $table->decimal('requested_final_total', 12, 2)->nullable();
            $table->decimal('approved_unit_cost_floor', 12, 2)->nullable();
            $table->decimal('approved_discount_amount', 12, 2)->nullable();
            $table->decimal('approved_discount_percent', 8, 4)->nullable();
            $table->decimal('approved_final_total', 12, 2)->nullable();
            $table->text('client_negotiation_reason')->nullable();
            $table->text('requester_remarks')->nullable();
            $table->text('approval_remarks')->nullable();
            $table->text('request_payload')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->string('approved_by_name', 255)->nullable();
            $table->string('approved_by_code', 50)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('used_revision_quote_id')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('decision_email_sent_at')->nullable();
            $table->timestamp('request_email_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_company', function (Blueprint $table) {
            $table->integer('company_id')->primary();
            $table->string('company_name')->nullable();
        });

        Schema::create('projects_main', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('project_name')->nullable();
            $table->integer('quote_id')->nullable();
            $table->string('project_type')->nullable();
            $table->decimal('quote_value', 15, 2)->nullable();
            $table->date('award_date')->nullable();
            $table->string('status')->nullable();
            $table->integer('created_by')->nullable();
        });

        foreach (['quotes_training', 'quotes_ih', 'quotes_manpower', 'quotes_equipment', 'quotes_special'] as $quoteTable) {
            Schema::create($quoteTable, function (Blueprint $table) {
                $table->integer('id')->primary();
                $table->string('quote_ref_no')->nullable();
                $table->string('training_title')->nullable();
                $table->string('service_title')->nullable();
                $table->integer('created_by_id')->nullable();
                $table->string('created_by_code')->nullable();
                $table->string('created_by_name')->nullable();
                $table->string('client_name')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->date('award_date')->nullable();
                $table->string('status')->nullable();
                $table->decimal('grand_total', 15, 2)->nullable();
                $table->string('attach_proposal')->nullable();
                $table->text('remarks')->nullable();
                $table->text('inquiry_remarks')->nullable();
                $table->text('status_remarks')->nullable();
                $table->text('general_remarks')->nullable();
            });
        }

        Schema::create('quote_inquiry_sources', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('quote_id');
            $table->string('service_type');
            $table->string('source')->nullable();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->string('invoice_ref_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('grand_total', 15, 2)->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->string('status')->nullable();
            $table->integer('client_id')->nullable();
            $table->integer('project_id')->nullable();
            $table->string('invoice_client_name')->nullable();
            $table->string('invoice_purpose')->nullable();
            $table->string('invoice_pic_name')->nullable();
            $table->string('invoice_pic_phone')->nullable();
            $table->string('invoice_pic_email')->nullable();
        });
    }

    private function insertPriceExceptionRequest(array $overrides = []): void
    {
        $id = $overrides['id'] ?? null;
        unset($overrides['id']);

        DB::table('quote_price_exception_requests')->insert([
            'id' => $id,
            'service_group' => 'training',
            'request_type' => 'quote',
            'quote_id' => 1,
            'quote_ref_no' => 'TR-Q-001',
            'revision_no_at_request' => 0,
            'requested_by_id' => 1,
            'requested_by_name' => 'Azam Bin Husain',
            'requested_by_code' => 'AZA',
            'base_unit_cost' => 0,
            'current_unit_cost' => 0,
            'requested_unit_cost' => 0,
            'requested_discount_amount' => 100,
            'requested_discount_percent' => 5,
            'current_total_amount' => 2000,
            'requested_final_total' => 1900,
            'approved_unit_cost_floor' => null,
            'approved_discount_amount' => null,
            'approved_discount_percent' => null,
            'approved_final_total' => null,
            'client_negotiation_reason' => 'Client requested better pricing',
            'requester_remarks' => 'Follow up with client',
            'approval_remarks' => null,
            'request_payload' => null,
            'status' => 'pending',
            'approved_by_id' => null,
            'approved_by_name' => null,
            'approved_by_code' => null,
            'approved_at' => null,
            'used_revision_quote_id' => null,
            'used_at' => null,
            'decision_email_sent_at' => null,
            'request_email_sent_at' => null,
            'created_at' => '2026-05-08 10:00:00',
            'updated_at' => '2026-05-08 10:00:00',
            ...$overrides,
        ]);
    }

    private function seedDashboardFacts(): void
    {
        DB::table('staff_general')->insert([
            'staff_id' => 1,
            'name_code' => 'AZA',
            'full_name' => 'Azam Bin Husain',
        ]);

        DB::table('all_quotes')->insert([
            [
                'service_group' => 'Training',
                'quote_id' => 1,
                'created_at' => '2026-03-05 10:00:00',
                'award_date' => '2026-05-05',
                'staff_id' => 1,
                'staff_name' => 'Azam Bin Husain',
                'staff_code' => 'AZA',
                'client_id' => 1,
                'client_name' => 'Client A',
                'quote_status' => 'Awarded',
                'value' => 3000,
                'inquiry_source' => 'WhatsApp',
            ],
            [
                'service_group' => 'IH',
                'quote_id' => 2,
                'created_at' => '2026-03-10 10:00:00',
                'award_date' => '2026-05-06',
                'staff_id' => 1,
                'staff_name' => 'Azam Bin Husain',
                'staff_code' => 'AZA',
                'client_id' => 2,
                'client_name' => 'Client B',
                'quote_status' => 'WON',
                'value' => 2000,
                'inquiry_source' => 'Email',
            ],
            [
                'service_group' => 'Training',
                'quote_id' => 3,
                'created_at' => '2026-03-15 10:00:00',
                'award_date' => null,
                'staff_id' => 1,
                'staff_name' => 'Azam Bin Husain',
                'staff_code' => 'AZA',
                'client_id' => 3,
                'client_name' => 'Client C',
                'quote_status' => 'Pending',
                'value' => 999,
                'inquiry_source' => 'WhatsApp',
            ],
            [
                'service_group' => 'Training',
                'quote_id' => 4,
                'created_at' => '2026-02-01 10:00:00',
                'award_date' => '2026-04-20',
                'staff_id' => 1,
                'staff_name' => 'Azam Bin Husain',
                'staff_code' => 'AZA',
                'client_id' => 4,
                'client_name' => 'Client D',
                'quote_status' => 'Awarded',
                'value' => 400,
                'inquiry_source' => 'Referral',
            ],
            [
                'service_group' => 'Training',
                'quote_id' => 5,
                'created_at' => '2025-12-03 10:00:00',
                'award_date' => '2025-12-20',
                'staff_id' => 1,
                'staff_name' => 'Azam Bin Husain',
                'staff_code' => 'AZA',
                'client_id' => 5,
                'client_name' => 'Client E',
                'quote_status' => 'Awarded',
                'value' => 500,
                'inquiry_source' => 'Referral',
            ],
            [
                'service_group' => 'Training',
                'quote_id' => 6,
                'created_at' => '2026-01-03 10:00:00',
                'award_date' => '2026-01-15',
                'staff_id' => 1,
                'staff_name' => 'Azam Bin Husain',
                'staff_code' => 'AZA',
                'client_id' => 6,
                'client_name' => 'Client F',
                'quote_status' => 'Awarded',
                'value' => 600,
                'inquiry_source' => 'Referral',
            ],
            [
                'service_group' => 'Training',
                'quote_id' => 7,
                'created_at' => '2026-03-20 10:00:00',
                'award_date' => '2026-03-25',
                'staff_id' => 1,
                'staff_name' => 'Azam Bin Husain',
                'staff_code' => 'AZA',
                'client_id' => 7,
                'client_name' => 'Client G',
                'quote_status' => 'Awarded',
                'value' => 300,
                'inquiry_source' => 'Website',
            ],
        ]);

        DB::table('quotes_training')->insert([
            ['id' => 1, 'created_by_code' => 'AZA', 'created_by_name' => 'Azam Bin Husain'],
            ['id' => 3, 'created_by_code' => 'AZA', 'created_by_name' => 'Azam Bin Husain'],
            ['id' => 4, 'created_by_code' => 'AZA', 'created_by_name' => 'Azam Bin Husain'],
            ['id' => 5, 'created_by_code' => 'AZA', 'created_by_name' => 'Azam Bin Husain'],
            ['id' => 6, 'created_by_code' => 'AZA', 'created_by_name' => 'Azam Bin Husain'],
            ['id' => 7, 'created_by_code' => 'AZA', 'created_by_name' => 'Azam Bin Husain'],
        ]);
        DB::table('quotes_ih')->insert([
            ['id' => 2, 'created_by_code' => 'AZA', 'created_by_name' => 'Azam Bin Husain'],
        ]);

        DB::table('quote_inquiry_sources')->insert([
            ['quote_id' => 1, 'service_type' => 'Training', 'source' => 'WhatsApp'],
            ['quote_id' => 2, 'service_type' => 'Industrial Hygiene', 'source' => 'Email'],
            ['quote_id' => 3, 'service_type' => 'Training', 'source' => 'WhatsApp'],
            ['quote_id' => 4, 'service_type' => 'Training', 'source' => 'Referral'],
            ['quote_id' => 5, 'service_type' => 'Training', 'source' => 'Referral'],
            ['quote_id' => 6, 'service_type' => 'Training', 'source' => 'Referral'],
            ['quote_id' => 7, 'service_type' => 'Training', 'source' => 'Website'],
        ]);

        DB::table('projects_main')->insert([
            [
                'id' => 101,
                'project_name' => 'Training Project A',
                'quote_id' => 1,
                'project_type' => 'Training',
                'quote_value' => 3000,
                'award_date' => '2026-05-05',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 102,
                'project_name' => 'IH Project B',
                'quote_id' => 2,
                'project_type' => 'Industrial Hygiene',
                'quote_value' => 2000,
                'award_date' => '2026-05-06',
                'status' => 'completed',
                'created_by' => 1,
            ],
            [
                'id' => 103,
                'project_name' => 'Training Project D',
                'quote_id' => 4,
                'project_type' => 'Training',
                'quote_value' => 400,
                'award_date' => '2026-04-20',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 104,
                'project_name' => 'Training Project E',
                'quote_id' => 5,
                'project_type' => 'Training',
                'quote_value' => 500,
                'award_date' => '2025-12-20',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 105,
                'project_name' => 'Training Project F',
                'quote_id' => 6,
                'project_type' => 'Training',
                'quote_value' => 600,
                'award_date' => '2026-01-15',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 106,
                'project_name' => 'Training Project G',
                'quote_id' => 7,
                'project_type' => 'Training',
                'quote_value' => 300,
                'award_date' => '2026-03-25',
                'status' => 'active',
                'created_by' => 1,
            ],
        ]);

        DB::table('monitoring_manual_pipeline_entries')->insert([
            [
                'entry_type' => 'closed',
                'prospect_name' => 'Manual Complete',
                'entry_date' => '2026-05-08',
                'source' => 'Referral',
                'segment_type' => 'individual',
                'service_category' => 'training',
                'estimated_rm' => 1200,
                'owner_staff_id' => 1,
                'owner_staff_code' => 'AZA',
                'owner_staff_name' => 'Azam Bin Husain',
                'created_by' => 1,
                'created_by_code' => 'AZA',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entry_type' => 'closed',
                'prospect_name' => 'Manual Missing Service',
                'entry_date' => '2026-05-08',
                'source' => 'Referral',
                'segment_type' => 'individual',
                'service_category' => null,
                'estimated_rm' => 800,
                'owner_staff_id' => 1,
                'owner_staff_code' => 'AZA',
                'owner_staff_name' => 'Azam Bin Husain',
                'created_by' => 1,
                'created_by_code' => 'AZA',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entry_type' => 'closed',
                'prospect_name' => 'Manual Zero RM',
                'entry_date' => '2026-05-08',
                'source' => 'Referral',
                'segment_type' => 'individual',
                'service_category' => 'training',
                'estimated_rm' => 0,
                'owner_staff_id' => 1,
                'owner_staff_code' => 'AZA',
                'owner_staff_name' => 'Azam Bin Husain',
                'created_by' => 1,
                'created_by_code' => 'AZA',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entry_type' => 'proposal',
                'prospect_name' => 'Manual Proposal',
                'entry_date' => '2026-05-08',
                'source' => 'Referral',
                'segment_type' => 'individual',
                'service_category' => 'training',
                'estimated_rm' => 1000,
                'owner_staff_id' => 1,
                'owner_staff_code' => 'AZA',
                'owner_staff_name' => 'Azam Bin Husain',
                'created_by' => 1,
                'created_by_code' => 'AZA',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('client_company')->insert([
            'company_id' => 1,
            'company_name' => 'Open Client',
        ]);
        DB::table('projects_main')->insert([
            'id' => 1,
            'project_name' => 'Open Project',
            'created_by' => 1,
        ]);
        DB::table('invoices')->insert([
            [
                'invoice_ref_no' => 'INV-OPEN-001',
                'invoice_date' => '2026-05-05',
                'paid_date' => null,
                'grand_total' => 3000,
                'paid_amount' => null,
                'status' => 'Unpaid',
                'client_id' => 1,
                'project_id' => 1,
                'invoice_client_name' => 'Open Client',
                'invoice_purpose' => 'Open Project',
                'invoice_pic_name' => 'Client PIC',
                'invoice_pic_phone' => '60120000000',
                'invoice_pic_email' => 'pic@example.test',
            ],
            [
                'invoice_ref_no' => 'INV-FUTURE-001',
                'invoice_date' => '2026-05-12',
                'paid_date' => null,
                'grand_total' => 4000,
                'paid_amount' => null,
                'status' => 'Unpaid',
                'client_id' => 1,
                'project_id' => 1,
                'invoice_client_name' => null,
                'invoice_purpose' => null,
                'invoice_pic_name' => null,
                'invoice_pic_phone' => null,
                'invoice_pic_email' => null,
            ],
            [
                'invoice_ref_no' => 'INV-PAID-001',
                'invoice_date' => '2026-05-02',
                'paid_date' => '2026-05-10',
                'grand_total' => 700,
                'paid_amount' => 700,
                'status' => 'Paid',
                'client_id' => 1,
                'project_id' => 1,
                'invoice_client_name' => null,
                'invoice_purpose' => null,
                'invoice_pic_name' => null,
                'invoice_pic_phone' => null,
                'invoice_pic_email' => null,
            ],
        ]);
    }
}
