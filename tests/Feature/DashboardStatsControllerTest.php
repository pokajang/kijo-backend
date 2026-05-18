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
                $table->string('created_by_code')->nullable();
                $table->string('created_by_name')->nullable();
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
