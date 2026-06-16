<?php

namespace Tests\Feature;

use App\Mail\MonthlyDashboardReportMail;
use App\Services\Stats\WorkloadSnapshotHealthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    public function test_sales_realized_value_uses_current_project_value_when_present(): void
    {
        DB::table('projects_main')->insert([
            'id' => 121,
            'project_name' => 'Revised Value Project',
            'quote_id' => null,
            'project_type' => 'Training',
            'quote_value' => 100,
            'current_project_value' => 150,
            'award_date' => '2026-05-10',
            'status' => 'active',
            'created_by' => 2,
        ]);

        $response = $this->authenticatedPost('/stats/monthly-sales', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $may = collect($response->json('monthlySales'))->firstWhere('month', '2026-05');

        $this->assertSame(5150.0, (float) $may['systemAmount']);
        $this->assertSame(6350.0, (float) $may['amount']);
    }

    public function test_awarded_value_by_person_uses_staff_from_realized_project_source(): void
    {
        DB::table('projects_main')->insert([
            'id' => 120,
            'project_name' => 'Direct Realized Project',
            'quote_id' => null,
            'project_type' => 'Training',
            'quote_value' => 800,
            'award_date' => '2026-05-10',
            'status' => 'active',
            'created_by' => 2,
        ]);

        $response = $this->authenticatedPost('/stats/awarded-value-by-person', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $rows = collect($response->json('awardValueByPerson'));

        $this->assertNull($rows->firstWhere('staffCode', 'UNASSIGNED'));

        $aza = $rows->firstWhere('staffCode', 'AZA');
        $this->assertNotNull($aza);
        $this->assertSame('Azam Bin Husain', $aza['staffName']);
        $this->assertSame(5000.0, (float) $aza['systemAwarded']);
        $this->assertSame(1200.0, (float) $aza['manualAwarded']);
        $this->assertSame(6200.0, (float) $aza['totalAwarded']);

        $bob = $rows->firstWhere('staffCode', 'BOB');
        $this->assertNotNull($bob);
        $this->assertSame('Bob Tester', $bob['staffName']);
        $this->assertSame(800.0, (float) $bob['systemAwarded']);
        $this->assertSame(0.0, (float) $bob['manualAwarded']);
        $this->assertSame(800.0, (float) $bob['totalAwarded']);
    }

    public function test_awarded_value_by_service_normalizes_realized_service_group_aliases(): void
    {
        DB::table('projects_main')->insert([
            [
                'id' => 140,
                'project_name' => 'Canonical Manpower Project',
                'quote_id' => null,
                'project_type' => 'Manpower Supply',
                'quote_value' => 1000,
                'award_date' => '2026-05-10',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 141,
                'project_name' => 'Legacy Man Power Project',
                'quote_id' => null,
                'project_type' => 'MAN POWER',
                'quote_value' => 700,
                'award_date' => '2026-05-10',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 142,
                'project_name' => 'Canonical Special Project',
                'quote_id' => null,
                'project_type' => 'Special Service',
                'quote_value' => 500,
                'award_date' => '2026-05-10',
                'status' => 'completed',
                'created_by' => 1,
            ],
            [
                'id' => 143,
                'project_name' => 'Legacy Special Project',
                'quote_id' => null,
                'project_type' => 'Special',
                'quote_value' => 300,
                'award_date' => '2026-05-10',
                'status' => 'completed',
                'created_by' => 1,
            ],
            [
                'id' => 144,
                'project_name' => 'Missing Service Project',
                'quote_id' => null,
                'project_type' => '',
                'quote_value' => 200,
                'award_date' => '2026-05-10',
                'status' => 'active',
                'created_by' => 1,
            ],
        ]);

        DB::table('monitoring_manual_pipeline_entries')->insert([
            'entry_type' => 'closed',
            'prospect_name' => 'Manual Man Power Closed',
            'entry_date' => '2026-05-10',
            'source' => 'Referral',
            'segment_type' => 'individual',
            'service_category' => 'man_power',
            'estimated_rm' => 100,
            'owner_staff_id' => 1,
            'owner_staff_code' => 'AZA',
            'owner_staff_name' => 'Azam Bin Husain',
            'created_by' => 1,
            'created_by_code' => 'AZA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->authenticatedPost('/stats/awarded-value-by-service', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $rows = collect($response->json('awardValueByService'));

        $this->assertNull($rows->firstWhere('serviceGroup', 'MAN POWER'));
        $this->assertNull($rows->firstWhere('serviceGroup', 'Special'));

        $manpower = $rows->firstWhere('serviceGroup', 'Manpower Supply');
        $this->assertNotNull($manpower);
        $this->assertSame(1700.0, (float) $manpower['systemAwarded']);
        $this->assertSame(100.0, (float) $manpower['manualAwarded']);
        $this->assertSame(1800.0, (float) $manpower['awardedValue']);

        $special = $rows->firstWhere('serviceGroup', 'Special Service');
        $this->assertNotNull($special);
        $this->assertSame(800.0, (float) $special['systemAwarded']);
        $this->assertSame(800.0, (float) $special['awardedValue']);

        $unclassified = $rows->firstWhere('serviceGroup', 'Unclassified');
        $this->assertNotNull($unclassified);
        $this->assertSame(200.0, (float) $unclassified['awardedValue']);
    }

    public function test_conversion_count_and_realized_value_by_source_share_award_date_projects(): void
    {
        DB::table('all_quotes')->insert(collect(range(8, 13))->map(fn (int $quoteId) => [
            'service_group' => 'Training',
            'quote_id' => $quoteId,
            'created_at' => '2026-05-12 10:00:00',
            'award_date' => $quoteId <= 12 ? '2026-05-18' : null,
            'staff_id' => 1,
            'staff_name' => 'Azam Bin Husain',
            'staff_code' => 'AZA',
            'client_id' => $quoteId,
            'client_name' => 'Client '.$quoteId,
            'quote_status' => $quoteId <= 12 ? 'Awarded' : 'Pending',
            'value' => $quoteId * 1000,
            'inquiry_source' => 'WhatsApp Training',
        ])->all());
        DB::table('quotes_training')->insert(collect(range(8, 13))->map(fn (int $quoteId) => [
            'id' => $quoteId,
            'created_by_code' => 'AZA',
            'created_by_name' => 'Azam Bin Husain',
        ])->all());
        DB::table('quote_inquiry_sources')->insert(collect(range(8, 13))->map(fn (int $quoteId) => [
            'quote_id' => $quoteId,
            'service_type' => 'Training',
            'source' => 'WhatsApp Training',
        ])->all());
        DB::table('projects_main')->insert([
            [
                'id' => 130,
                'project_name' => 'WhatsApp Training Project 1',
                'quote_id' => 8,
                'project_type' => 'Training Programme',
                'quote_value' => 7000,
                'award_date' => '2026-05-18',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 131,
                'project_name' => 'WhatsApp Training Project 2',
                'quote_id' => 9,
                'project_type' => 'Training',
                'quote_value' => 8000,
                'award_date' => '2026-05-19',
                'status' => 'completed',
                'created_by' => 1,
            ],
            [
                'id' => 132,
                'project_name' => 'WhatsApp Training Project 3',
                'quote_id' => 10,
                'project_type' => 'Training',
                'quote_value' => 9000,
                'award_date' => '2026-05-20',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 133,
                'project_name' => 'WhatsApp Training Project 4',
                'quote_id' => 11,
                'project_type' => 'Training',
                'quote_value' => 10000,
                'award_date' => '2026-05-21',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 134,
                'project_name' => 'WhatsApp Training Outside Range',
                'quote_id' => 12,
                'project_type' => 'Training',
                'quote_value' => 11000,
                'award_date' => '2026-06-01',
                'status' => 'active',
                'created_by' => 1,
            ],
        ]);

        $dateRange = [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ];

        $conversionResponse = $this->authenticatedPost('/stats/conversion-rate-by-source', $dateRange);
        $awardedResponse = $this->authenticatedPost('/stats/awarded-value-by-source', $dateRange);

        $conversionResponse->assertOk()->assertJsonPath('status', 'success');
        $awardedResponse->assertOk()->assertJsonPath('status', 'success');

        $conversion = collect($conversionResponse->json('conversionRateBySource'))
            ->firstWhere('sourceName', 'WhatsApp Training');
        $awarded = collect($awardedResponse->json('awardValueBySource'))
            ->firstWhere('sourceName', 'WhatsApp Training');

        $this->assertSame(4, (int) $conversion['convertedCount']);
        $this->assertSame(6, (int) $conversion['totalQuotes']);
        $this->assertSame(66.7, (float) $conversion['conversionRate']);
        $this->assertSame(34000.0, (float) $awarded['systemAwarded']);
        $this->assertSame(34000.0, (float) $awarded['awardedValue']);
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

    public function test_monitoring_revenue_uses_realized_project_value_and_proposal_value_stays_quote_based(): void
    {
        DB::table('quotes_training')->where('id', 1)->update([
            'quote_ref_no' => 'TR-Q-001',
            'training_title' => 'Training A',
            'client_name' => 'Client A',
            'created_at' => '2026-05-01 10:00:00',
            'award_date' => '2026-05-05',
            'status' => 'Awarded',
            'grand_total' => 3500,
        ]);
        DB::table('quotes_ih')->where('id', 2)->update([
            'quote_ref_no' => 'IH-Q-002',
            'service_title' => 'IH Service B',
            'client_name' => 'Client B',
            'created_at' => '2026-05-02 10:00:00',
            'award_date' => '2026-05-06',
            'status' => 'WON',
            'grand_total' => 2500,
        ]);
        DB::table('quotes_training')->insert([
            'id' => 20,
            'quote_ref_no' => 'TR-Q-ORPHAN',
            'training_title' => 'Awarded Quote Without Project',
            'client_name' => 'Quote Only Client',
            'created_by_code' => 'AZA',
            'created_by_name' => 'Azam Bin Husain',
            'created_at' => '2026-05-03 10:00:00',
            'award_date' => '2026-05-07',
            'status' => 'Awarded',
            'grand_total' => 9000,
        ]);
        DB::table('quotes_training')->insert([
            'id' => 21,
            'quote_ref_no' => 'TR-Q-TERM',
            'training_title' => 'Terminated Project Quote',
            'client_name' => 'Terminated Client',
            'created_by_code' => 'AZA',
            'created_by_name' => 'Azam Bin Husain',
            'created_at' => '2026-05-04 10:00:00',
            'award_date' => '2026-05-08',
            'status' => 'Awarded',
            'grand_total' => 700,
        ]);
        DB::table('projects_main')->insert([
            'id' => 121,
            'project_name' => 'Terminated Project',
            'quote_id' => 21,
            'project_type' => 'Training',
            'quote_value' => 700,
            'award_date' => '2026-05-08',
            'status' => 'terminated',
            'created_by' => 1,
        ]);

        $statusResponse = $this->authenticatedPost('/stats/monitoring-pipeline-status', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);
        $statusResponse->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(6200.0, (float) $statusResponse->json('totals.totalRm'));
        $this->assertSame(6200.0, (float) $statusResponse->json('companyTotalRm'));
        $this->assertSame(7500.0, (float) $statusResponse->json('yearToDateCompanyTotalRm'));

        $training = collect($statusResponse->json('rows'))->firstWhere('label', 'TRAINING');
        $this->assertSame(4200.0, (float) $training['totalRm']);
        $this->assertSame(['manual', 'project'], collect($training['details']['total']['rm']['items'])->pluck('sourceType')->sort()->values()->all());

        $salesResponse = $this->authenticatedPost('/stats/monthly-sales', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);
        $salesResponse->assertOk()->assertJsonPath('status', 'success');
        $salesMay = collect($salesResponse->json('monthlySales'))->firstWhere('month', '2026-05');

        $this->assertSame(5000.0, (float) $salesMay['systemAmount']);
        $this->assertSame(1200.0, (float) $salesMay['manualAmount']);
        $this->assertSame(6200.0, (float) $salesMay['amount']);

        $toolsResponse = $this->authenticatedPost('/stats/monitoring-pipeline-tools', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);
        $toolsResponse->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(6200.0, (float) $toolsResponse->json('companyTotalRm'));
        $this->assertSame(6200.0, (float) $toolsResponse->json('currentTotalRm'));

        $trendResponse = $this->authenticatedPost('/stats/monitoring-trends', [
            'end_date' => '2026-05-31',
            'trend_period' => 'last6',
        ]);
        $trendResponse->assertOk()->assertJsonPath('status', 'success');

        $may = collect($trendResponse->json('series'))->firstWhere('month', '2026-05');

        $this->assertSame(16700.0, (float) $may['proposalRm']);
        $this->assertSame(6200.0, (float) $may['revenueRm']);
    }

    public function test_monitoring_ytd_current_month_cuts_off_at_today(): void
    {
        DB::table('quotes_training')->insert([
            'id' => 30,
            'quote_ref_no' => 'TR-Q-FUTURE',
            'training_title' => 'Future Current Month Project',
            'client_name' => 'Future Client',
            'created_by_code' => 'AZA',
            'created_by_name' => 'Azam Bin Husain',
            'created_at' => '2026-05-10 10:00:00',
            'award_date' => '2026-05-20',
            'status' => 'Awarded',
            'grand_total' => 1111,
        ]);
        DB::table('projects_main')->insert([
            'id' => 130,
            'project_name' => 'Future Current Month Project',
            'quote_id' => 30,
            'project_type' => 'Training',
            'quote_value' => 1111,
            'award_date' => '2026-05-20',
            'status' => 'active',
            'created_by' => 1,
        ]);

        $currentMonthResponse = $this->authenticatedPost('/stats/monitoring-pipeline-status', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);
        $currentMonthResponse->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(7311.0, (float) $currentMonthResponse->json('totals.totalRm'));
        $this->assertSame(7500.0, (float) $currentMonthResponse->json('yearToDateCompanyTotalRm'));

        $historicalResponse = $this->authenticatedPost('/stats/monitoring-pipeline-status', [
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
        ]);
        $historicalResponse->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(1300.0, (float) $historicalResponse->json('yearToDateCompanyTotalRm'));
    }

    public function test_conversion_views_share_quote_cohort_cutoff_semantics(): void
    {
        $dateRange = [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ];

        $response = $this->authenticatedPost('/stats/conversion-rate-by-source', $dateRange);

        $response->assertOk()->assertJsonPath('status', 'success');

        $rows = collect($response->json('conversionRateBySource'));
        $email = $rows->firstWhere('sourceName', 'Email');
        $whatsapp = $rows->firstWhere('sourceName', 'WhatsApp');
        $website = $rows->firstWhere('sourceName', 'Website');

        $this->assertSame(0, (int) $email['convertedCount']);
        $this->assertSame(1, (int) $email['totalQuotes']);
        $this->assertSame(0.0, (float) $email['conversionRate']);
        $this->assertSame(0, (int) $whatsapp['convertedCount']);
        $this->assertSame(2, (int) $whatsapp['totalQuotes']);
        $this->assertSame(0.0, (float) $whatsapp['conversionRate']);
        $this->assertSame(1, (int) $website['convertedCount']);
        $this->assertSame(1, (int) $website['totalQuotes']);
        $this->assertSame(100.0, (float) $website['conversionRate']);

        $serviceResponse = $this->authenticatedPost('/stats/conversion-rate-by-service', $dateRange);
        $serviceResponse->assertOk()->assertJsonPath('status', 'success');

        $serviceRows = collect($serviceResponse->json('conversionRateByService'));
        $training = $serviceRows->firstWhere('serviceGroup', 'Training');
        $industrialHygiene = $serviceRows->firstWhere('serviceGroup', 'Industrial Hygiene');

        $this->assertSame(1, (int) $training['convertedCount']);
        $this->assertSame(3, (int) $training['totalQuotes']);
        $this->assertSame(33.3, (float) $training['conversionRate']);
        $this->assertSame(0, (int) $industrialHygiene['convertedCount']);
        $this->assertSame(1, (int) $industrialHygiene['totalQuotes']);
        $this->assertSame(0.0, (float) $industrialHygiene['conversionRate']);

        $staffResponse = $this->authenticatedPost('/stats/conversion-rate-by-staff', $dateRange);
        $staffResponse->assertOk()->assertJsonPath('status', 'success');

        $staffRows = collect($staffResponse->json('conversionRateByStaff'));
        $azam = $staffRows->firstWhere('staffCode', 'AZA');

        $this->assertSame(1, (int) $azam['convertedCount']);
        $this->assertSame(4, (int) $azam['totalQuotes']);
        $this->assertSame(25.0, (float) $azam['conversionRate']);
        $this->assertSame(2, (int) $staffResponse->json('activeStaffCount'));
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

    public function test_crm_quotation_stats_share_quote_fact_source(): void
    {
        $dateRange = [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ];

        $monthlyValueResponse = $this->authenticatedPost('/stats/monthly-quote-value', $dateRange);
        $monthlyCountResponse = $this->authenticatedPost('/stats/monthly-quote-count', $dateRange);
        $serviceMonthResponse = $this->authenticatedPost('/stats/monthly-quote-value-by-service', $dateRange);
        $serviceValueResponse = $this->authenticatedPost('/stats/quote-value-by-service', $dateRange);
        $staffCountResponse = $this->authenticatedPost('/stats/quote-count-by-person', $dateRange);
        $staffValueResponse = $this->authenticatedPost('/stats/quote-value-by-person', $dateRange);
        $inquiryCountResponse = $this->authenticatedPost('/stats/inquiry', $dateRange);
        $inquiryValueResponse = $this->authenticatedPost('/stats/inquiry-by-values', $dateRange);

        foreach ([
            $monthlyValueResponse,
            $monthlyCountResponse,
            $serviceMonthResponse,
            $serviceValueResponse,
            $staffCountResponse,
            $staffValueResponse,
            $inquiryCountResponse,
            $inquiryValueResponse,
        ] as $response) {
            $response->assertOk()->assertJsonPath('status', 'success');
        }

        $marchValue = collect($monthlyValueResponse->json('monthlyQuoteValue'))->firstWhere('month', '2026-03');
        $marchCount = collect($monthlyCountResponse->json('monthlyQuoteCount'))->firstWhere('month', '2026-03');

        $this->assertSame(6299.0, (float) $marchValue['amount']);
        $this->assertSame(4, (int) $marchCount['count']);

        $this->assertSame(['2026-03'], $serviceMonthResponse->json('months'));
        $serviceMonths = collect($serviceMonthResponse->json('monthlyStats'));
        $trainingMonth = $serviceMonths->firstWhere('serviceGroup', 'Training');
        $ihMonth = $serviceMonths->firstWhere('serviceGroup', 'Industrial Hygiene');

        $this->assertSame([4299.0], array_map('floatval', $trainingMonth['monthlyValues']));
        $this->assertSame(4299.0, (float) $trainingMonth['totalValue']);
        $this->assertSame([2000.0], array_map('floatval', $ihMonth['monthlyValues']));
        $this->assertSame(2000.0, (float) $ihMonth['totalValue']);

        $serviceValues = collect($serviceValueResponse->json('quoteValueByService'));
        $this->assertSame(4299.0, (float) $serviceValues->firstWhere('serviceGroup', 'Training')['totalValue']);
        $this->assertSame(2000.0, (float) $serviceValues->firstWhere('serviceGroup', 'Industrial Hygiene')['totalValue']);

        $staffCount = collect($staffCountResponse->json('quoteCountByPerson'))->firstWhere('staffCode', 'AZA');
        $staffValue = collect($staffValueResponse->json('quoteValueByPerson'))->firstWhere('staffCode', 'AZA');

        $this->assertSame(4, (int) $staffCount['quoteCount']);
        $this->assertSame(6299.0, (float) $staffValue['totalValue']);

        $inquiryCounts = collect($inquiryCountResponse->json('inquiryStats'));
        $inquiryValues = collect($inquiryValueResponse->json('inquiryStatsByValues'));

        $this->assertSame(2, (int) $inquiryCounts->firstWhere('source', 'WhatsApp')['count']);
        $this->assertSame(1, (int) $inquiryCounts->firstWhere('source', 'Email')['count']);
        $this->assertSame(3999.0, (float) $inquiryValues->firstWhere('source', 'WhatsApp')['totalValue']);
        $this->assertSame(2000.0, (float) $inquiryValues->firstWhere('source', 'Email')['totalValue']);

        $this->assertSame(
            (float) collect($serviceValueResponse->json('quoteValueByService'))->sum('totalValue'),
            (float) collect($staffValueResponse->json('quoteValueByPerson'))->sum('totalValue')
        );
        $this->assertSame(
            (float) collect($monthlyValueResponse->json('monthlyQuoteValue'))->sum('amount'),
            (float) collect($inquiryValueResponse->json('inquiryStatsByValues'))->sum('totalValue')
        );
        $this->assertSame(
            (int) collect($monthlyCountResponse->json('monthlyQuoteCount'))->sum('count'),
            (int) collect($staffCountResponse->json('quoteCountByPerson'))->sum('quoteCount')
        );
        $this->assertSame(
            (int) collect($monthlyCountResponse->json('monthlyQuoteCount'))->sum('count'),
            (int) collect($inquiryCountResponse->json('inquiryStats'))->sum('count')
        );
    }

    public function test_crm_quote_facts_normalize_aliases_staff_and_latest_source(): void
    {
        DB::table('all_quotes')->insert([
            [
                'service_group' => 'MAN POWER',
                'quote_id' => 70,
                'created_at' => '2026-05-12 09:00:00',
                'award_date' => null,
                'staff_id' => 1,
                'staff_name' => 'Legacy Name',
                'staff_code' => 'aza',
                'client_id' => 70,
                'client_name' => 'Client MP',
                'quote_status' => 'Pending',
                'value' => 1000,
                'inquiry_source' => 'Old Source',
            ],
            [
                'service_group' => 'Manpower Supply',
                'quote_id' => 70,
                'created_at' => '2026-05-12 09:00:00',
                'award_date' => null,
                'staff_id' => 1,
                'staff_name' => 'Another Legacy Name',
                'staff_code' => 'AZA',
                'client_id' => 70,
                'client_name' => 'Client MP',
                'quote_status' => 'Pending',
                'value' => 1000,
                'inquiry_source' => 'Older Source',
            ],
            [
                'service_group' => 'man_power',
                'quote_id' => 71,
                'created_at' => '2026-05-13 09:00:00',
                'award_date' => null,
                'staff_id' => 1,
                'staff_name' => 'Typo Name',
                'staff_code' => 'AZA',
                'client_id' => 71,
                'client_name' => 'Client MP 2',
                'quote_status' => 'Pending',
                'value' => 500,
                'inquiry_source' => '',
            ],
            [
                'service_group' => 'Special',
                'quote_id' => 72,
                'created_at' => '2026-05-14 09:00:00',
                'award_date' => null,
                'staff_id' => null,
                'staff_name' => '',
                'staff_code' => '',
                'client_id' => 72,
                'client_name' => 'Client Special',
                'quote_status' => 'Pending',
                'value' => 300,
                'inquiry_source' => '',
            ],
            [
                'service_group' => '',
                'quote_id' => 73,
                'created_at' => '2026-05-15 09:00:00',
                'award_date' => null,
                'staff_id' => null,
                'staff_name' => null,
                'staff_code' => null,
                'client_id' => 73,
                'client_name' => 'Client Missing',
                'quote_status' => 'Pending',
                'value' => 200,
                'inquiry_source' => null,
            ],
        ]);

        DB::table('quote_inquiry_sources')->insert([
            ['quote_id' => 70, 'service_type' => 'Manpower Supply', 'source' => 'First Source'],
            ['quote_id' => 70, 'service_type' => 'Manpower Supply', 'source' => 'Latest Source'],
        ]);

        DB::table('projects_main')->insert([
            'id' => 170,
            'project_name' => 'Legacy Man Power Project',
            'quote_id' => 70,
            'project_type' => 'MAN POWER',
            'quote_value' => 1000,
            'award_date' => '2026-05-18',
            'status' => 'active',
            'created_by' => 1,
        ]);

        $dateRange = [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ];

        $serviceResponse = $this->authenticatedPost('/stats/quote-value-by-service', $dateRange);
        $staffCountResponse = $this->authenticatedPost('/stats/quote-count-by-person', $dateRange);
        $staffValueResponse = $this->authenticatedPost('/stats/quote-value-by-person', $dateRange);
        $inquiryResponse = $this->authenticatedPost('/stats/inquiry', $dateRange);
        $sourceResponse = $this->authenticatedPost('/stats/awarded-value-by-source', $dateRange);

        foreach ([
            $serviceResponse,
            $staffCountResponse,
            $staffValueResponse,
            $inquiryResponse,
            $sourceResponse,
        ] as $response) {
            $response->assertOk()->assertJsonPath('status', 'success');
        }

        $serviceRows = collect($serviceResponse->json('quoteValueByService'));
        $this->assertNull($serviceRows->firstWhere('serviceGroup', 'MAN POWER'));
        $this->assertNull($serviceRows->firstWhere('serviceGroup', 'man_power'));
        $this->assertSame(1500.0, (float) $serviceRows->firstWhere('serviceGroup', 'Manpower Supply')['totalValue']);
        $this->assertSame(300.0, (float) $serviceRows->firstWhere('serviceGroup', 'Special Service')['totalValue']);
        $this->assertSame(200.0, (float) $serviceRows->firstWhere('serviceGroup', 'Unclassified')['totalValue']);

        $staffCount = collect($staffCountResponse->json('quoteCountByPerson'));
        $staffValue = collect($staffValueResponse->json('quoteValueByPerson'));
        $azamCount = $staffCount->firstWhere('staffCode', 'AZA');
        $azamValue = $staffValue->firstWhere('staffCode', 'AZA');
        $this->assertSame('Azam Bin Husain', $azamValue['staffName']);
        $this->assertSame(2, (int) $azamCount['quoteCount']);
        $this->assertSame(1500.0, (float) $azamValue['totalValue']);
        $this->assertSame(2, (int) $staffCount->firstWhere('staffCode', 'UNASSIGNED')['quoteCount']);

        $inquiryRows = collect($inquiryResponse->json('inquiryStats'));
        $this->assertSame(1, (int) $inquiryRows->firstWhere('source', 'Latest Source')['count']);
        $this->assertSame(3, (int) $inquiryRows->firstWhere('source', 'Unattributed')['count']);

        $sourceRows = collect($sourceResponse->json('awardValueBySource'));
        $this->assertSame(1000.0, (float) $sourceRows->firstWhere('sourceName', 'Latest Source')['awardedValue']);
    }

    public function test_conversion_excludes_future_null_awards_and_manual_closed_entries(): void
    {
        DB::table('all_quotes')->insert(collect(range(80, 83))->map(fn (int $quoteId) => [
            'service_group' => 'Training',
            'quote_id' => $quoteId,
            'created_at' => '2026-05-12 10:00:00',
            'award_date' => null,
            'staff_id' => 1,
            'staff_name' => 'Azam Bin Husain',
            'staff_code' => 'AZA',
            'client_id' => $quoteId,
            'client_name' => 'Conversion Client '.$quoteId,
            'quote_status' => 'Pending',
            'value' => 1000,
            'inquiry_source' => 'Fallback Source',
        ])->all());
        DB::table('quote_inquiry_sources')->insert(collect(range(80, 83))->map(fn (int $quoteId) => [
            'quote_id' => $quoteId,
            'service_type' => 'Training',
            'source' => 'Conversion Source',
        ])->all());
        DB::table('projects_main')->insert([
            [
                'id' => 180,
                'project_name' => 'Converted Inside Cutoff',
                'quote_id' => 80,
                'project_type' => 'Training',
                'quote_value' => 1000,
                'award_date' => '2026-05-20',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 181,
                'project_name' => 'Future Converted',
                'quote_id' => 81,
                'project_type' => 'Training',
                'quote_value' => 1000,
                'award_date' => '2026-06-01',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 182,
                'project_name' => 'Missing Award Date',
                'quote_id' => 82,
                'project_type' => 'Training',
                'quote_value' => 1000,
                'award_date' => null,
                'status' => 'active',
                'created_by' => 1,
            ],
        ]);
        DB::table('monitoring_manual_pipeline_entries')->insert([
            'entry_type' => 'closed',
            'prospect_name' => 'Manual Closed Not Quote Conversion',
            'entry_date' => '2026-05-20',
            'source' => 'Conversion Source',
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
        ]);

        $dateRange = [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ];

        $sourceResponse = $this->authenticatedPost('/stats/conversion-rate-by-source', $dateRange);
        $serviceResponse = $this->authenticatedPost('/stats/conversion-rate-by-service', $dateRange);
        $staffResponse = $this->authenticatedPost('/stats/conversion-rate-by-staff', $dateRange);

        foreach ([$sourceResponse, $serviceResponse, $staffResponse] as $response) {
            $response->assertOk()->assertJsonPath('status', 'success');
        }

        $source = collect($sourceResponse->json('conversionRateBySource'))->firstWhere('sourceName', 'Conversion Source');
        $service = collect($serviceResponse->json('conversionRateByService'))->firstWhere('serviceGroup', 'Training');
        $staff = collect($staffResponse->json('conversionRateByStaff'))->firstWhere('staffCode', 'AZA');

        foreach ([$source, $service, $staff] as $row) {
            $this->assertSame(1, (int) $row['convertedCount']);
            $this->assertSame(4, (int) $row['totalQuotes']);
            $this->assertSame(25.0, (float) $row['conversionRate']);
        }
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

    public function test_free_legal_compliance_assessments_count_as_meeting_pitching_per_assessor(): void
    {
        $this->insertLegalComplianceAssessment([
            'id' => 10,
            'assessment_date' => '2026-05-16',
            'selected_assessors' => [
                [
                    'value' => 1,
                    'label' => 'Azam Bin Husain (AZA)',
                    'data' => [
                        'staff_id' => 1,
                        'full_name' => 'Azam Bin Husain',
                        'name_code' => 'AZA',
                        'email' => 'azam@example.test',
                    ],
                ],
                [
                    'value' => 2,
                    'label' => 'Bob Tester (BOB)',
                    'data' => [
                        'staff_id' => 2,
                        'full_name' => 'Bob Tester',
                        'name_code' => 'BOB',
                        'email' => 'bob@example.test',
                    ],
                ],
            ],
        ]);
        $this->insertLegalComplianceAssessment([
            'id' => 11,
            'assessment_date' => '2026-05-16',
            'company_name' => 'Paid Assessment Sdn Bhd',
            'template_snapshot' => ['assessment_tier' => 'paid'],
        ]);
        $this->insertLegalComplianceAssessment([
            'id' => 12,
            'assessment_date' => '2026-05-16',
            'company_name' => 'Project Assessment Sdn Bhd',
            'project_id' => 101,
        ]);

        $response = $this->authenticatedPost('/stats/monitoring-pipeline-tools', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $meeting = collect($response->json('rows'))->firstWhere('label', 'MEETING/ PITCHING');
        $items = collect($meeting['details']['weekly']['W3']['items']);

        $this->assertSame(2, (int) $meeting['weekly']['W3']);
        $this->assertSame(2, (int) $meeting['total']);
        $this->assertSame(['AZA', 'BOB'], $items->pluck('ownerStaffCode')->sort()->values()->all());
        $this->assertSame(['legal_compliance'], $items->pluck('sourceType')->unique()->values()->all());
    }

    public function test_legal_compliance_assessments_respect_staff_filter_and_appear_in_records(): void
    {
        $this->insertLegalComplianceAssessment([
            'id' => 20,
            'assessment_date' => '2026-05-16',
            'company_name' => 'Filtered Legal Prospect',
            'selected_assessors' => [
                [
                    'value' => 1,
                    'label' => 'Azam Bin Husain (AZA)',
                    'data' => [
                        'staff_id' => 1,
                        'full_name' => 'Azam Bin Husain',
                        'name_code' => 'AZA',
                        'email' => 'azam@example.test',
                    ],
                ],
                [
                    'value' => 2,
                    'label' => 'Bob Tester (BOB)',
                    'data' => [
                        'staff_id' => 2,
                        'full_name' => 'Bob Tester',
                        'name_code' => 'BOB',
                        'email' => 'bob@example.test',
                    ],
                ],
            ],
        ]);

        $pipelineResponse = $this->authenticatedPost('/stats/monitoring-pipeline-tools', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'staff_code' => 'BOB',
        ]);
        $recordsResponse = $this->authenticatedPost('/stats/monitoring-manual-pipeline-entries', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'staff_code' => 'BOB',
            'entry_type' => 'meeting_pitching',
        ]);
        $staffResponse = $this->authenticatedPost('/stats/monitoring-staff-options', []);

        $pipelineResponse->assertOk()->assertJsonPath('status', 'success');
        $recordsResponse->assertOk()->assertJsonPath('status', 'success');
        $staffResponse->assertOk()->assertJsonPath('status', 'success');

        $meeting = collect($pipelineResponse->json('rows'))->firstWhere('label', 'MEETING/ PITCHING');
        $record = collect($recordsResponse->json('entries'))->firstWhere('recordSource', 'legal_compliance');

        $this->assertSame(1, (int) $meeting['weekly']['W3']);
        $this->assertSame(['BOB'], collect($meeting['details']['weekly']['W3']['items'])->pluck('ownerStaffCode')->values()->all());
        $this->assertNotNull($record);
        $this->assertSame('meeting_pitching', $record['entryType']);
        $this->assertSame('Free Legal Compliance Assessment', $record['source']);
        $this->assertFalse((bool) $record['canUpdate']);
        $this->assertFalse((bool) $record['canDelete']);
        $this->assertContains('BOB', collect($staffResponse->json('staffOptions'))->pluck('value')->all());

        $detailResponse = $this->authenticatedGet(
            '/stats/monitoring-manual-pipeline-entry/'.rawurlencode((string) $record['id'])
        );

        $detailResponse->assertOk()->assertJsonPath('status', 'success');
        $this->assertSame('legal_compliance', $detailResponse->json('entry.recordSource'));
        $this->assertSame(20, (int) $detailResponse->json('entry.legalAssessmentId'));
    }

    public function test_management_users_can_manage_manual_pipeline_entries_for_other_staff(): void
    {
        $createResponse = $this->authenticatedPost('/stats/monitoring-manual-pipeline-entry', [
            'entry_type' => 'lead',
            'entry_date' => '2026-05-18',
            'source' => 'WhatsApp Personal',
            'owner_staff_code' => 'BOB',
            'segment_type' => '',
            'service_category' => null,
            'estimated_rm' => null,
            'prospect_name' => 'Bob Manual Lead',
            'notes' => 'Created by admin for BOB',
        ]);

        $createResponse->assertOk()->assertJsonPath('status', 'success');

        $entryId = (int) DB::table('monitoring_manual_pipeline_entries')
            ->where('prospect_name', 'Bob Manual Lead')
            ->value('id');
        $this->assertGreaterThan(0, $entryId);
        $this->assertDatabaseHas('monitoring_manual_pipeline_entries', [
            'id' => $entryId,
            'owner_staff_id' => 2,
            'owner_staff_code' => 'BOB',
            'created_by' => 1,
            'created_by_code' => 'AZA',
        ]);

        $recordsResponse = $this->authenticatedPost('/stats/monitoring-manual-pipeline-entries', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'staff_code' => 'BOB',
        ]);

        $recordsResponse->assertOk()->assertJsonPath('status', 'success');
        $record = collect($recordsResponse->json('entries'))->firstWhere('id', $entryId);
        $this->assertNotNull($record);
        $this->assertTrue((bool) $record['canUpdate']);
        $this->assertTrue((bool) $record['canDelete']);

        $detailResponse = $this->authenticatedGet("/stats/monitoring-manual-pipeline-entry/{$entryId}");

        $detailResponse->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('entry.id', $entryId)
            ->assertJsonPath('entry.canUpdate', true)
            ->assertJsonPath('entry.canDelete', true);

        $updateResponse = $this->authenticatedPost("/stats/monitoring-manual-pipeline-entry/{$entryId}", [
            'entry_type' => 'qualified',
            'entry_date' => '2026-05-19',
            'source' => 'Email Personal',
            'segment_type' => 'tender',
            'service_category' => '',
            'estimated_rm' => '',
            'prospect_name' => 'Bob Manual Lead Updated',
            'notes' => 'Updated by admin',
        ]);

        $updateResponse->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('monitoring_manual_pipeline_entries', [
            'id' => $entryId,
            'entry_type' => 'qualified',
            'prospect_name' => 'Bob Manual Lead Updated',
            'entry_date' => '2026-05-19',
            'source' => 'Email Personal',
            'segment_type' => 'tender',
            'notes' => 'Updated by admin',
        ]);

        $deleteResponse = $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 1,
                'name_code' => 'AZA',
                'full_name' => 'Azam Bin Husain',
                'roles' => ['System Admin'],
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->deleteJson("/stats/monitoring-manual-pipeline-entry/{$entryId}");

        $deleteResponse->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseMissing('monitoring_manual_pipeline_entries', [
            'id' => $entryId,
        ]);
    }

    public function test_non_management_users_cannot_manage_manual_pipeline_entries_for_other_staff(): void
    {
        DB::table('system_users')->insert([
            'id' => 2,
            'staff_id' => 2,
            'email' => 'dashboard-staff@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
        ]);

        $session = [
            '_token' => 'test-csrf-token',
            'user_id' => 2,
            'staff_id' => 2,
            'name_code' => 'BOB',
            'full_name' => 'Bob Tester',
            'roles' => ['Staff'],
        ];

        $this
            ->withSession($session)
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/stats/monitoring-manual-pipeline-entry', [
                'entry_type' => 'lead',
                'entry_date' => '2026-05-18',
                'source' => 'WhatsApp Personal',
                'owner_staff_code' => 'AZA',
                'prospect_name' => 'Forbidden Aza Manual Lead',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to add manual entries for another staff member.');

        DB::table('monitoring_manual_pipeline_entries')->insert([
            'entry_type' => 'lead',
            'prospect_name' => 'Aza Private Manual Lead',
            'entry_date' => '2026-05-18',
            'source' => 'WhatsApp Personal',
            'segment_type' => null,
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

        $entryId = (int) DB::table('monitoring_manual_pipeline_entries')
            ->where('prospect_name', 'Aza Private Manual Lead')
            ->value('id');

        $this
            ->withSession($session)
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/stats/monitoring-manual-pipeline-entries', [
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-31',
                'staff_code' => 'AZA',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to view monitoring data for another staff member.');

        $this
            ->withSession($session)
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->getJson("/stats/monitoring-manual-pipeline-entry/{$entryId}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Pipeline entry not found.');

        $this
            ->withSession($session)
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/stats/monitoring-manual-pipeline-entry/{$entryId}", [
                'entry_type' => 'qualified',
                'entry_date' => '2026-05-19',
                'source' => 'Email Personal',
                'segment_type' => '',
                'service_category' => '',
                'estimated_rm' => '',
                'prospect_name' => 'Aza Private Manual Lead Updated',
                'notes' => 'Should not update',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to update this manual entry.');

        $this
            ->withSession($session)
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->deleteJson("/stats/monitoring-manual-pipeline-entry/{$entryId}")
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to delete this manual entry.');

        $this->assertDatabaseHas('monitoring_manual_pipeline_entries', [
            'id' => $entryId,
            'prospect_name' => 'Aza Private Manual Lead',
        ]);
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

    public function test_financial_awarded_not_invoiced_uses_remaining_project_value_as_of_date(): void
    {
        DB::table('invoices')->delete();
        DB::table('projects_main')->delete();

        DB::table('projects_main')->insert([
            [
                'id' => 201,
                'project_name' => 'Uninvoiced Project',
                'quote_id' => null,
                'project_type' => 'Training',
                'quote_value' => 1000,
                'award_date' => '2026-05-01',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 202,
                'project_name' => 'Partially Invoiced Project',
                'quote_id' => null,
                'project_type' => 'Training',
                'quote_value' => 2000,
                'award_date' => '2026-05-02',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 203,
                'project_name' => 'Fully Invoiced Project',
                'quote_id' => null,
                'project_type' => 'Training',
                'quote_value' => 3000,
                'award_date' => '2026-05-03',
                'status' => 'completed',
                'created_by' => 1,
            ],
            [
                'id' => 204,
                'project_name' => 'Over Invoiced Project',
                'quote_id' => null,
                'project_type' => 'Training',
                'quote_value' => 400,
                'award_date' => '2026-05-04',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 205,
                'project_name' => 'Future Invoice Project',
                'quote_id' => null,
                'project_type' => 'Training',
                'quote_value' => 1500,
                'award_date' => '2026-05-05',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 206,
                'project_name' => 'Cancelled Invoice Project',
                'quote_id' => null,
                'project_type' => 'Training',
                'quote_value' => 800,
                'award_date' => '2026-05-06',
                'status' => 'active',
                'created_by' => 1,
            ],
            [
                'id' => 207,
                'project_name' => 'Future Awarded Project',
                'quote_id' => null,
                'project_type' => 'Training',
                'quote_value' => 900,
                'award_date' => '2026-05-12',
                'status' => 'active',
                'created_by' => 1,
            ],
        ]);

        DB::table('invoices')->insert([
            [
                'invoice_ref_no' => 'INV-PARTIAL-001',
                'invoice_date' => '2026-05-07',
                'grand_total' => 750,
                'status' => 'Pending',
                'project_id' => 202,
            ],
            [
                'invoice_ref_no' => 'INV-FULL-001',
                'invoice_date' => '2026-05-08',
                'grand_total' => 3000,
                'status' => 'Pending',
                'project_id' => 203,
            ],
            [
                'invoice_ref_no' => 'INV-OVER-001',
                'invoice_date' => '2026-05-09',
                'grand_total' => 500,
                'status' => 'Pending',
                'project_id' => 204,
            ],
            [
                'invoice_ref_no' => 'INV-FUTURE-REDUCTION-001',
                'invoice_date' => '2026-05-12',
                'grand_total' => 1500,
                'status' => 'Pending',
                'project_id' => 205,
            ],
            [
                'invoice_ref_no' => 'INV-CANCELLED-001',
                'invoice_date' => '2026-05-10',
                'grand_total' => 800,
                'status' => 'Cancelled',
                'project_id' => 206,
            ],
        ]);

        $response = $this->authenticatedPost('/stats/monthly-income-statement', [
            'start_date' => '2026-01-01',
            'end_date' => '2026-05-11',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(4550.0, (float) $response->json('uninvoicedAwardedAmount'));
        $this->assertSame(4, (int) $response->json('uninvoicedAwardedCount'));
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

    public function test_monitoring_one_month_range_returns_weekly_period_columns(): void
    {
        $response = $this->authenticatedPost('/stats/monitoring-pipeline-status', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $columns = collect($response->json('periodColumns'));

        $this->assertSame(['W1', 'W2', 'W3', 'W4', 'W5'], $columns->pluck('key')->all());
        $this->assertSame(['week'], $columns->pluck('type')->unique()->values()->all());
        $this->assertSame(['May 2026'], $columns->pluck('groupLabel')->unique()->values()->all());
    }

    public function test_monitoring_two_month_range_returns_weekly_columns_for_both_months(): void
    {
        $response = $this->authenticatedPost('/stats/monitoring-pipeline-tools', [
            'start_date' => '2026-04-01',
            'end_date' => '2026-05-31',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $columns = collect($response->json('periodColumns'));

        $this->assertCount(10, $columns);
        $this->assertSame(['week'], $columns->pluck('type')->unique()->values()->all());
        $this->assertSame(
            ['Apr 2026', 'May 2026'],
            $columns->pluck('groupLabel')->unique()->values()->all()
        );
        $this->assertTrue($columns->pluck('key')->contains('2026-04-W1'));
        $this->assertTrue($columns->pluck('key')->contains('2026-05-W5'));
    }

    public function test_monitoring_long_range_compacts_previous_months_and_keeps_anchor_month_weekly(): void
    {
        $response = $this->authenticatedPost('/stats/monitoring-pipeline-status', [
            'period' => 'currentYear',
            'start_date' => '2026-01-01',
            'end_date' => '2026-05-11',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $columns = collect($response->json('periodColumns'));

        $this->assertSame(
            ['month-2026-01', 'month-2026-02', 'month-2026-03', 'month-2026-04', '2026-05-W1', '2026-05-W2'],
            $columns->pluck('key')->all()
        );
        $this->assertSame('Current Year', $response->json('rangeLabel'));
        $this->assertSame('2026-05-11', $columns->last()['end']);

        $totalRmFromColumns = collect($response->json('totals.periodic'))->sum('rm');
        $this->assertSame((float) $response->json('totals.totalRm'), (float) $totalRmFromColumns);
    }

    public function test_monitoring_custom_partial_range_clips_first_and_last_columns(): void
    {
        $response = $this->authenticatedPost('/stats/monitoring-pipeline-tools', [
            'period' => 'custom',
            'start_date' => '2026-03-10',
            'end_date' => '2026-05-11',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $columns = collect($response->json('periodColumns'));

        $this->assertSame('month-2026-03', $columns->first()['key']);
        $this->assertSame('2026-03-10', $columns->first()['start']);
        $this->assertSame('2026-03-31', $columns->first()['end']);
        $this->assertSame('2026-05-W2', $columns->last()['key']);
        $this->assertSame('2026-05-11', $columns->last()['end']);
    }

    public function test_monitoring_all_time_derives_available_range_without_cap(): void
    {
        $response = $this->authenticatedPost('/stats/monitoring-pipeline-status', [
            'period' => 'allTime',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $columns = collect($response->json('periodColumns'));

        $this->assertSame('All Time', $response->json('rangeLabel'));
        $this->assertSame('2025-12-03', $response->json('rangeStart'));
        $this->assertSame('2026-05-08', $response->json('rangeEnd'));
        $this->assertSame('month-2025-12', $columns->first()['key']);
        $this->assertSame('2025-12-03', $columns->first()['start']);
        $this->assertSame('2026-05-W2', $columns->last()['key']);
        $this->assertSame('2026-05-08', $columns->last()['end']);
    }

    public function test_workload_stats_groups_project_progress_by_updater_and_keeps_other_tasks_untagged(): void
    {
        DB::table('projects_main')->insert([
            'id' => 501,
            'project_name' => 'Workload Project',
            'client_id' => 501,
            'status' => 'Active',
            'created_by' => 1,
        ]);
        DB::table('client_company')->insert([
            'company_id' => 501,
            'company_name' => 'Workload Client',
        ]);
        DB::table('project_collaborators')->insert([
            'project_id' => 501,
            'staff_id' => 1,
            'project_role' => 'Leader ',
            'role_description' => 'Project owner',
        ]);

        $projectTaskId = DB::table('tasks')->insertGetId([
            'staff_id' => 1,
            'project_id' => 501,
            'project_progress_id' => 1,
            'title' => 'Project tagged overdue task',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_confidence' => 'high',
            'classification_source' => 'system',
            'user_override' => false,
            'matched_pattern' => 'prepare report',
            'status' => 'Ongoing',
            'due_date' => '2026-05-08',
            'created_at' => '2026-05-01 09:00:00',
            'completed_at' => null,
        ]);

        $completedProjectTaskId = DB::table('tasks')->insertGetId([
            'staff_id' => 1,
            'project_id' => 501,
            'project_progress_id' => 5,
            'title' => 'Completed tagged task',
            'status' => 'Completed',
            'due_date' => '2026-05-09',
            'created_at' => '2026-05-02 09:00:00',
            'completed_at' => '2026-05-10',
        ]);

        DB::table('tasks')->insert([
            [
                'staff_id' => 2,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => 'Untagged due soon task',
                'status' => 'Ongoing',
                'due_date' => '2026-05-13',
                'created_at' => '2026-05-10 09:00:00',
                'completed_at' => null,
            ],
            [
                'staff_id' => 2,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => 'Untagged completed task',
                'status' => 'Completed',
                'due_date' => '2026-05-05',
                'created_at' => '2026-04-30 09:00:00',
                'completed_at' => '2026-05-10',
            ],
            [
                'staff_id' => 2,
                'project_id' => 501,
                'project_progress_id' => null,
                'title' => 'Non collaborator tagged task',
                'status' => 'Ongoing',
                'due_date' => '2026-05-20',
                'created_at' => '2026-05-11 09:00:00',
                'completed_at' => null,
            ],
            [
                'staff_id' => 1,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => 'Old active task outside period',
                'status' => 'Ongoing',
                'due_date' => '2026-05-20',
                'created_at' => '2026-04-15 09:00:00',
                'completed_at' => null,
            ],
        ]);

        DB::table('project_progress')->insert([
            [
                'id' => 1,
                'project_id' => 501,
                'progress_date' => '2026-05-01',
                'progress_text' => 'Ongoing task: Project tagged overdue task',
                'updated_by' => 1,
                'updated_on' => '2026-05-01 09:00:00',
                'source_type' => 'task',
                'source_task_id' => $projectTaskId,
            ],
            [
                'id' => 5,
                'project_id' => 501,
                'progress_date' => '2026-05-10',
                'progress_text' => 'Completed task: Completed tagged task',
                'updated_by' => 1,
                'updated_on' => '2026-05-10 09:00:00',
                'source_type' => 'task',
                'source_task_id' => $completedProjectTaskId,
            ],
            [
                'id' => 2,
                'project_id' => 501,
                'progress_date' => '2026-05-09',
                'progress_text' => 'Manual project update',
                'updated_by' => 1,
                'updated_on' => '2026-05-09 09:00:00',
                'source_type' => null,
                'source_task_id' => null,
            ],
            [
                'id' => 3,
                'project_id' => 501,
                'progress_date' => '2026-05-08',
                'progress_text' => 'Bob project update',
                'updated_by' => 2,
                'updated_on' => '2026-05-08 09:00:00',
                'source_type' => null,
                'source_task_id' => null,
            ],
            [
                'id' => 4,
                'project_id' => 501,
                'progress_date' => '2026-04-30',
                'progress_text' => 'Outside period update',
                'updated_by' => 1,
                'updated_on' => '2026-04-30 09:00:00',
                'source_type' => null,
                'source_task_id' => null,
            ],
        ]);

        $response = $this->authenticatedGet('/stats/workload?start_date=2026-05-01&end_date=2026-05-31');

        $response->assertOk()->assertJsonPath('status', 'success');
        $response
            ->assertJsonPath('asOfDate', '2026-05-31')
            ->assertJsonPath('completedWindow.startDate', '2026-05-01')
            ->assertJsonPath('completedWindow.endDate', '2026-05-31');

        $rows = collect($response->json('staff'));
        $this->assertSame(['AZA', 'BOB'], $rows->pluck('staffCode')->values()->all());

        $aza = $rows->firstWhere('staffCode', 'AZA');
        $this->assertSame(8.0, (float) $aza['score']);
        $this->assertSame(
            [
                ['label' => 'Non-project tasks', 'points' => 1],
                ['label' => 'Project responsibility', 'points' => 5],
                ['label' => 'Deadline pressure', 'points' => 2],
            ],
            $aza['scoreBreakdown']
        );
        $this->assertSame(2, (int) $aza['activeTasks']);
        $this->assertSame(2, (int) $aza['overdueTasks']);
        $this->assertSame(1, (int) $aza['projectTaggedActiveTasks']);
        $this->assertSame(1, (int) $aza['projectGroupCount']);
        $this->assertSame(['Old active task outside period'], collect($aza['otherTasks'])->pluck('title')->all());
        $this->assertSame('Workload Client', $aza['projectGroups'][0]['clientName']);
        $this->assertSame('leader', $aza['projectGroups'][0]['projectRole']);
        $this->assertSame(1.0, (float) $aza['projectGroups'][0]['roleWeight']);
        $this->assertSame(0, (int) $aza['projectGroups'][0]['valueBand']);
        $this->assertSame(1, (int) $aza['projectGroups'][0]['scoreableProgressCount']);
        $this->assertSame(3.0, (float) $aza['projectGroups'][0]['projectTaskPoints']);
        $this->assertSame(1.0, (float) $aza['projectGroups'][0]['projectBasePoints']);
        $this->assertSame(1.0, (float) $aza['projectGroups'][0]['projectProgressPoints']);
        $this->assertSame(0.0, (float) $aza['projectGroups'][0]['projectValuePoints']);
        $this->assertSame(2.0, (float) $aza['projectGroups'][0]['projectOverheadPoints']);
        $this->assertSame(5.0, (float) $aza['projectGroups'][0]['scoreContribution']);
        $this->assertSame(['Project tagged overdue task'], collect($aza['projectGroups'][0]['activeTasks'])->pluck('title')->all());
        $this->assertSame([], collect($aza['projectGroups'][0]['completedTasks'])->pluck('title')->all());
        $this->assertSame('real_effort', $aza['projectGroups'][0]['activeTasks'][0]['taskCategory']);
        $this->assertSame(3.0, (float) $aza['projectGroups'][0]['activeTasks'][0]['effortScore']);
        $this->assertSame('high', $aza['projectGroups'][0]['activeTasks'][0]['classificationConfidence']);
        $this->assertSame(
            ['Manual project update'],
            collect($aza['projectGroups'][0]['progressUpdates'])->pluck('progressText')->all()
        );

        $bob = $rows->firstWhere('staffCode', 'BOB');
        $this->assertSame(2.7, (float) $bob['score']);
        $this->assertSame(2, (int) $bob['activeTasks']);
        $this->assertSame(2, (int) $bob['overdueTasks']);
        $this->assertSame(0, (int) $bob['dueSoonTasks']);
        $this->assertSame(0, (int) $bob['projectTaggedActiveTasks']);
        $this->assertSame(0, (int) $bob['projectGroupCount']);
        $this->assertSame([], $bob['projectGroups']);
        $this->assertSame([], collect($bob['completedTasks'])->pluck('title')->all());
        $this->assertEqualsCanonicalizing(
            ['Non collaborator tagged task', 'Untagged due soon task'],
            collect($bob['otherTasks'])->pluck('title')->all()
        );
        $this->assertSame(
            'Non collaborator tagged task',
            collect($bob['otherTasks'])->pluck('title')->first()
        );
        $this->assertNotContains(
            'Bob project update',
            collect($rows)->flatMap(fn ($row) => collect($row['projectGroups'])->flatMap(fn ($group) => collect($group['progressUpdates'])->pluck('progressText')))->all()
        );
    }

    public function test_workload_payload_exposes_displayed_project_group_count_separately_from_tagged_task_count(): void
    {
        DB::table('client_company')->insert([
            ['company_id' => 701, 'company_name' => 'Count Client A'],
            ['company_id' => 702, 'company_name' => 'Count Client B'],
        ]);
        DB::table('projects_main')->insert([
            [
                'id' => 701,
                'project_name' => 'Tagged Task Project',
                'client_id' => 701,
                'status' => 'Active',
                'created_by' => 1,
            ],
            [
                'id' => 702,
                'project_name' => 'Manual Progress Project',
                'client_id' => 702,
                'status' => 'Active',
                'created_by' => 1,
            ],
        ]);
        DB::table('project_collaborators')->insert([
            ['project_id' => 701, 'staff_id' => 1, 'project_role' => 'Leader'],
            ['project_id' => 702, 'staff_id' => 1, 'project_role' => 'Leader'],
        ]);
        DB::table('tasks')->insert([
            'staff_id' => 1,
            'project_id' => 701,
            'project_progress_id' => null,
            'title' => 'Prepare tagged project report',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_confidence' => 'high',
            'classification_source' => 'system',
            'user_override' => false,
            'matched_pattern' => 'report writing',
            'status' => 'Ongoing',
            'due_date' => '2026-05-20',
            'created_at' => '2026-05-10 09:00:00',
            'completed_at' => null,
        ]);
        DB::table('project_progress')->insert([
            'project_id' => 702,
            'progress_date' => '2026-05-12',
            'progress_text' => 'Manual update for second displayed project',
            'updated_by' => 1,
            'updated_on' => '2026-05-12 09:00:00',
            'source_type' => null,
            'source_task_id' => null,
        ]);

        $response = $this->authenticatedGet('/stats/workload?start_date=2026-05-01&end_date=2026-05-31');

        $response->assertOk()->assertJsonPath('status', 'success');
        $aza = collect($response->json('staff'))->firstWhere('staffCode', 'AZA');
        $this->assertSame(1, (int) $aza['projectTaggedActiveTasks']);
        $this->assertSame(2, (int) $aza['projectGroupCount']);
        $this->assertEqualsCanonicalizing(
            ['Tagged Task Project', 'Manual Progress Project'],
            collect($aza['projectGroups'])->pluck('projectName')->all()
        );
    }

    public function test_workload_pdf_exports_the_selected_snapshot(): void
    {
        DB::table('projects_main')->insert([
            'id' => 990,
            'project_name' => 'PDF Workload Project',
            'quote_value' => 12345,
            'client_id' => 990,
            'status' => 'Active',
            'created_by' => 1,
        ]);

        DB::table('project_collaborators')->insert([
            'project_id' => 990,
            'staff_id' => 1,
            'project_role' => 'Leader',
        ]);

        DB::table('tasks')->insert([
            [
                'staff_id' => 1,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => 'PDF workload task',
                'status' => 'Ongoing',
                'due_date' => '2026-05-15',
                'created_at' => '2026-05-10 09:00:00',
                'completed_at' => null,
            ],
            [
                'staff_id' => 1,
                'project_id' => 990,
                'project_progress_id' => null,
                'title' => 'PDF project workload task',
                'status' => 'Ongoing',
                'due_date' => '2026-05-20',
                'created_at' => '2026-05-09 09:00:00',
                'completed_at' => null,
            ],
        ]);

        DB::table('project_progress')->insert([
            'id' => 990,
            'project_id' => 990,
            'progress_date' => '2026-05-12',
            'progress_text' => 'PDF project progress update',
            'updated_by' => 1,
            'updated_on' => '2026-05-12 09:00:00',
            'source_type' => null,
            'source_task_id' => null,
        ]);

        $response = $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 1,
                'name_code' => 'AZA',
                'full_name' => 'Azam Bin Husain',
                'roles' => ['System Admin'],
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->get('/stats/workload/pdf?start_date=2026-05-01&end_date=2026-05-31');

        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertSame(
            'inline; filename="workload-report-2026-05-31.pdf"',
            $response->headers->get('Content-Disposition')
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_monthly_dashboard_report_route_requires_management_role(): void
    {
        DB::table('system_users')->insert([
            'id' => 2,
            'staff_id' => 2,
            'email' => 'staff-dashboard@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
        ]);

        $this->authenticatedGetAs('/stats/monthly-dashboard-report/pdf?month=2026-05', ['Staff'], 2, 2)
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized: required role missing.');
    }

    public function test_monthly_dashboard_report_generates_missing_pdf_and_is_idempotent(): void
    {
        Storage::fake('private');

        $this->authenticatedGet('/stats/monthly-dashboard-report/pdf?month=2026-13')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid report month. Use YYYY-MM.');

        $response = $this->authenticatedGet('/stats/monthly-dashboard-report/pdf?month=2026-05');

        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertSame(
            'inline; filename="monthly-dashboard-management-report-2026-05.pdf"',
            $response->headers->get('Content-Disposition')
        );
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        Storage::disk('private')->assertExists('dashboard-monthly-reports/2026-05.pdf');
        $this->assertDatabaseHas('dashboard_monthly_reports', [
            'report_month' => '2026-05',
            'start_date' => '2026-01-01',
            'end_date' => '2026-05-31',
            'status' => 'generated',
        ]);
        $report = DB::table('dashboard_monthly_reports')->where('report_month', '2026-05')->first();
        $this->assertNotEmpty($report->payload_json);
        $this->assertSame(hash('sha256', (string) $report->payload_json), $report->payload_hash);
        $this->assertGreaterThanOrEqual(0, (int) $report->generation_duration_ms);
        $payload = json_decode((string) $report->payload_json, true);
        $this->assertIsArray($payload['decisionSummary'] ?? null);
        $this->assertArrayHasKey('cashSignals', $payload['decisionSummary']);
        $this->assertArrayHasKey('pipelineSignals', $payload['decisionSummary']);
        $this->assertArrayHasKey('driverSignals', $payload['decisionSummary']);
        $this->assertArrayHasKey('decisionPoints', $payload['decisionSummary']);

        $this->authenticatedGet('/stats/monthly-dashboard-report/pdf?month=2026-05')->assertOk();
        $this->assertSame(1, DB::table('dashboard_monthly_reports')->where('report_month', '2026-05')->count());
    }

    public function test_monthly_dashboard_report_view_uses_management_semantics_and_empty_states(): void
    {
        $html = view('pdf.monthly-dashboard-report', [
            'title' => 'Year-to-Date Dashboard Management Report',
            'reportMonth' => '2026-05',
            'periodLabel' => '01 Jan 2026 to 31 May 2026',
            'generatedAtLabel' => '01 Jun 2026, 08:30 AM',
            'summaryCards' => [
                ['label' => 'YTD Awarded Sales Value', 'value' => 'RM 0.00', 'detail' => '0 awarded sales items'],
                ['label' => 'YTD Quotation Value', 'value' => 'RM 0.00', 'detail' => '0 quotations issued'],
                ['label' => 'YTD Payment Received', 'value' => 'RM 0.00', 'detail' => 'RM 0.00 invoiced'],
                ['label' => 'Outstanding Receivables', 'value' => 'RM 0.00', 'detail' => '0 outstanding invoices'],
                ['label' => 'Active Workload Items', 'value' => '0', 'detail' => '0 overdue items'],
            ],
            'decisionSummary' => [
                'cashSignals' => [
                    ['label' => 'Collection rate', 'value' => '0.0%', 'detail' => 'RM 0.00 received from RM 0.00 invoiced'],
                ],
                'pipelineSignals' => [
                    ['label' => 'Quotation pipeline', 'value' => 'RM 0.00', 'detail' => '0 quotations issued YTD'],
                ],
                'driverSignals' => [
                    ['label' => 'Top awarded service', 'value' => '-', 'detail' => 'RM 0.00'],
                ],
                'decisionPoints' => ['Collections: no outstanding receivable exposure is shown for the period.'],
                'opportunities' => ['Build quotation activity so service-line demand can be compared.'],
            ],
            'staffPerformanceRows' => [],
            'sales' => [
                'byService' => [],
                'byPerson' => [],
                'bySource' => [],
                'conversionStaff' => [],
                'conversionSource' => [],
                'conversionService' => [],
            ],
            'crm' => [
                'monthlyQuoteTrend' => [],
                'quoteActivityByStaff' => [],
                'quoteValueByService' => [],
                'monthlyQuoteServiceRows' => [],
                'inquirySourceMix' => [],
            ],
            'financial' => [
                'totalInvoiced' => 0,
                'totalReceived' => 0,
                'outstandingAmount' => 0,
                'outstandingCount' => 0,
                'uninvoicedAwardedAmount' => 0,
                'uninvoicedAwardedCount' => 0,
                'outstandingSummary' => 'RM 0.00 across 0 invoices',
                'uninvoicedAwardedSummary' => 'RM 0.00 across 0 items',
                'trend' => [],
                'debtors' => [],
            ],
            'monitoring' => ['trendRows' => [], 'pipelineStages' => [], 'serviceRevenue' => [], 'staffMatrix' => []],
            'workload' => ['asOfDate' => '', 'topStaff' => [], 'historyRows' => []],
            'logoDataUri' => null,
            'fontFaceCss' => '',
        ])->render();

        $this->assertStringContainsString('Year-to-Date Dashboard Management Report', $html);
        $this->assertStringContainsString('Executive Decision Summary', $html);
        $this->assertStringContainsString('Grow Profitably', $html);
        $this->assertStringContainsString('Pipeline Strength', $html);
        $this->assertStringContainsString('Performance Drivers', $html);
        $this->assertStringContainsString('Decision Points', $html);
        $this->assertStringContainsString('Opportunities to Develop', $html);
        $this->assertStringContainsString('Company-Wide Snapshot', $html);
        $this->assertStringContainsString('Staff-by-Staff YTD Performance Matrix', $html);
        $this->assertStringContainsString('YTD Awarded Sales Value', $html);
        $this->assertStringContainsString('Conversion by Source', $html);
        $this->assertStringContainsString('Conversion by Service', $html);
        $this->assertStringContainsString('Monthly Quotation Trend', $html);
        $this->assertStringContainsString('Service Mix Over Time', $html);
        $this->assertStringContainsString('Top Outstanding Debtors', $html);
        $this->assertStringContainsString('Monthly Monitoring Trend', $html);
        $this->assertStringContainsString('Staff Pipeline Stage Matrix', $html);
        $this->assertStringContainsString('Staff Segment Revenue Matrix', $html);
        $this->assertStringContainsString('Workload Pressure by Staff', $html);
        $this->assertStringContainsString('Workload Score Trend', $html);
        $this->assertStringContainsString('No records for this reporting period.', $html);
        $this->assertStringNotContainsString('Management Attention Summary', $html);
        $this->assertStringNotContainsString('Recommended Review Focus', $html);
        $this->assertStringNotContainsString('Requires management attention', $html);
        $this->assertStringNotContainsString('No data for this period.', $html);
    }

    public function test_system_admin_monthly_report_test_trigger_sends_only_ui_recipients(): void
    {
        Storage::fake('private');
        Mail::fake();
        config()->set('dashboard_reports.monthly_recipients', 'prod@example.test');

        $this->authenticatedGet('/admin/monthly-dashboard-report-test/status')
            ->assertOk()
            ->assertJsonPath('data.configuredRecipientCount', 1)
            ->assertJsonPath('data.previousMonth', '2026-04')
            ->assertJsonPath('data.logs', []);

        $response = $this->authenticatedPost('/admin/monthly-dashboard-report-test/trigger', [
            'month' => '2026-05',
            'recipients' => 'Tester One <test-one@example.test>, test-two@example.test',
            'force' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.sent', 2)
            ->assertJsonPath('data.recipientCount', 2)
            ->assertJsonPath('data.reportMonth', '2026-05');
        $this->assertStringContainsString('/stats/monthly-dashboard-report/public/', (string) $response->json('data.url'));

        Mail::assertSent(MonthlyDashboardReportMail::class, 2);
        Mail::assertSent(MonthlyDashboardReportMail::class, fn ($mail) => $mail->hasTo('test-one@example.test'));
        Mail::assertSent(MonthlyDashboardReportMail::class, fn ($mail) => $mail->hasTo('test-two@example.test'));
        Mail::assertNotSent(MonthlyDashboardReportMail::class, fn ($mail) => $mail->hasTo('prod@example.test'));
        $this->assertDatabaseHas('dashboard_monthly_reports', [
            'report_month' => '2026-05',
            'status' => 'emailed',
        ]);
        $this->assertDatabaseHas('dashboard_monthly_report_test_logs', [
            'report_month' => '2026-05',
            'recipient_email' => 'test-one@example.test, test-two@example.test',
            'status' => 'sent',
            'response_message' => 'Year-to-date dashboard report test email sent.',
        ]);
        $this->assertDatabaseHas('dashboard_monthly_report_email_logs', [
            'report_month' => '2026-05',
            'recipient_email' => 'test-one@example.test',
            'recipient_name' => 'Tester One',
            'send_type' => 'test',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('dashboard_monthly_report_email_logs', [
            'report_month' => '2026-05',
            'recipient_email' => 'test-two@example.test',
            'send_type' => 'test',
            'status' => 'sent',
        ]);

        $this->authenticatedGet('/admin/monthly-dashboard-report-test/status')
            ->assertOk()
            ->assertJsonPath('data.logs.0.reportMonth', '2026-05')
            ->assertJsonPath('data.logs.0.recipient', 'test-one@example.test, test-two@example.test')
            ->assertJsonPath('data.logs.0.status', 'sent');
    }

    public function test_system_admin_monthly_report_test_trigger_validates_recipients_and_role(): void
    {
        Storage::fake('private');
        Mail::fake();
        DB::table('system_users')->insert([
            'id' => 3,
            'staff_id' => 3,
            'email' => 'manager-dashboard@example.test',
            'role' => json_encode(['Manager']),
            'is_active' => 1,
        ]);

        $this->authenticatedGetAs('/admin/monthly-dashboard-report-test/status', ['Manager'], 3, 3)
            ->assertForbidden();

        $this->authenticatedPost('/admin/monthly-dashboard-report-test/trigger', [
            'month' => '2026-05',
            'recipients' => '',
            'force' => false,
        ])->assertStatus(422);

        $this->authenticatedPost('/admin/monthly-dashboard-report-test/trigger', [
            'month' => '2026-05',
            'recipients' => 'not-an-email',
            'force' => false,
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_system_admin_monthly_report_test_trigger_accepts_single_email_recipient(): void
    {
        Storage::fake('private');
        Mail::fake();

        $this->authenticatedPost('/admin/monthly-dashboard-report-test/trigger', [
            'month' => '2026-05',
            'recipients' => 'single@example.test',
            'force' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.sent', 1)
            ->assertJsonPath('data.recipientCount', 1)
            ->assertJsonPath('data.reportMonth', '2026-05');

        Mail::assertSent(MonthlyDashboardReportMail::class, 1);
        Mail::assertSent(MonthlyDashboardReportMail::class, fn ($mail) => $mail->hasTo('single@example.test'));
        $this->assertDatabaseHas('dashboard_monthly_report_email_logs', [
            'report_month' => '2026-05',
            'recipient_email' => 'single@example.test',
            'send_type' => 'test',
            'status' => 'sent',
        ]);
    }

    public function test_system_admin_monthly_report_test_trigger_records_send_failure(): void
    {
        Storage::fake('private');
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \RuntimeException('SMTP down'));

        $this->authenticatedPost('/admin/monthly-dashboard-report-test/trigger', [
            'month' => '2026-05',
            'recipients' => 'test@example.test',
            'force' => true,
        ])
            ->assertStatus(500)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('dashboard_monthly_reports', [
            'report_month' => '2026-05',
            'status' => 'failed',
            'error_message' => 'SMTP down',
        ]);
        $this->assertDatabaseHas('dashboard_monthly_report_test_logs', [
            'report_month' => '2026-05',
            'recipient_email' => 'test@example.test',
            'status' => 'failed',
            'response_message' => 'SMTP down',
        ]);
        $this->assertDatabaseHas('dashboard_monthly_report_email_logs', [
            'report_month' => '2026-05',
            'recipient_email' => 'test@example.test',
            'send_type' => 'test',
            'status' => 'failed',
            'error_message' => 'SMTP down',
        ]);
    }

    public function test_system_admin_can_update_monthly_report_email_schedule(): void
    {
        $response = $this->authenticatedPut('/admin/monthly-dashboard-report-test/schedule', [
            'enabled' => true,
            'intervalValue' => 2,
            'intervalUnit' => 'months',
            'startDate' => '2026-05-01',
            'sendTime' => '08:45',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.schedule.enabled', true)
            ->assertJsonPath('data.schedule.intervalValue', 2)
            ->assertJsonPath('data.schedule.intervalUnit', 'months')
            ->assertJsonPath('data.schedule.startDate', '2026-05-01')
            ->assertJsonPath('data.schedule.sendTime', '08:45')
            ->assertJsonPath('data.schedule.nextSendAt', '2026-07-01 08:45:00');

        $this->assertDatabaseHas('dashboard_monthly_report_schedule_settings', [
            'id' => 1,
            'interval_value' => 2,
            'interval_unit' => 'months',
            'start_date' => '2026-05-01',
            'send_time' => '08:45',
        ]);
    }

    public function test_scheduled_monthly_report_command_sends_when_due_and_reschedules(): void
    {
        Storage::fake('private');
        Mail::fake();
        config()->set('dashboard_reports.monthly_recipients', 'ops@example.test');

        DB::table('dashboard_monthly_report_schedule_settings')->insert([
            'id' => 1,
            'enabled' => true,
            'interval_value' => 1,
            'interval_unit' => 'days',
            'start_date' => '2026-05-10',
            'send_time' => '08:30',
            'next_send_at' => '2026-05-11 08:30:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('dashboard:monthly-report', ['--scheduled' => true])
            ->assertExitCode(0);

        Mail::assertSent(MonthlyDashboardReportMail::class, 1);
        Mail::assertSent(MonthlyDashboardReportMail::class, fn ($mail) => $mail->hasTo('ops@example.test'));
        $this->assertDatabaseHas('dashboard_monthly_reports', [
            'report_month' => '2026-04',
            'status' => 'emailed',
        ]);
        $this->assertDatabaseHas('dashboard_monthly_report_email_logs', [
            'report_month' => '2026-04',
            'recipient_email' => 'ops@example.test',
            'send_type' => 'production',
            'status' => 'sent',
        ]);

        $settings = DB::table('dashboard_monthly_report_schedule_settings')->where('id', 1)->first();
        $this->assertSame('sent', $settings->last_status);
        $this->assertSame('2026-05-12 08:30:00', Carbon::parse($settings->next_send_at)->toDateTimeString());
    }

    public function test_scheduled_monthly_report_skips_when_schedule_lock_is_held(): void
    {
        Storage::fake('private');
        Mail::fake();
        config()->set('dashboard_reports.monthly_recipients', 'ops@example.test');

        DB::table('dashboard_monthly_report_schedule_settings')->insert([
            'id' => 1,
            'enabled' => true,
            'interval_value' => 1,
            'interval_unit' => 'days',
            'start_date' => '2026-05-10',
            'send_time' => '08:30',
            'next_send_at' => '2026-05-11 08:30:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lock = Cache::lock('dashboard-monthly-report:scheduled-send', 600);
        $this->assertTrue($lock->get());

        try {
            $result = app(\App\Services\Stats\MonthlyDashboardReportService::class)->runScheduledSend();
        } finally {
            $lock->release();
        }

        $this->assertFalse((bool) $result['due']);
        $this->assertTrue((bool) $result['skipped']);
        $this->assertSame('schedule_locked', $result['reason']);
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('dashboard_monthly_reports', [
            'report_month' => '2026-04',
        ]);
        $settings = DB::table('dashboard_monthly_report_schedule_settings')->where('id', 1)->first();
        $this->assertNull($settings->last_status);
        $this->assertSame('2026-05-11 08:30:00', Carbon::parse($settings->next_send_at)->toDateTimeString());
    }

    public function test_monthly_dashboard_report_command_emails_public_link_without_attachment(): void
    {
        Storage::fake('private');
        Mail::fake();
        config()->set('dashboard_reports.monthly_recipients', 'CEO <ceo@example.test>, ops@example.test');

        $this->artisan('dashboard:monthly-report', ['--month' => '2026-05', '--send' => true])
            ->assertExitCode(0);

        $downloadUrl = '';
        Mail::assertSent(MonthlyDashboardReportMail::class, 2);
        Mail::assertSent(MonthlyDashboardReportMail::class, function (MonthlyDashboardReportMail $mail) use (&$downloadUrl) {
            $downloadUrl = $mail->downloadUrl;

            return str_contains($mail->downloadUrl, '/stats/monthly-dashboard-report/public/');
        });

        $path = parse_url($downloadUrl, PHP_URL_PATH);
        $this->get($path)->assertOk();
        $this->assertSame(2, DB::table('dashboard_monthly_report_email_logs')
            ->where('report_month', '2026-05')
            ->where('send_type', 'production')
            ->where('status', 'sent')
            ->count());

        DB::table('dashboard_monthly_reports')
            ->where('report_month', '2026-05')
            ->update(['public_token_expires_at' => '2026-05-10 00:00:00']);

        $this->get($path)
            ->assertStatus(410)
            ->assertJsonPath('message', 'Report link has expired.');
    }

    public function test_monthly_dashboard_report_send_with_empty_recipients_still_generates(): void
    {
        Storage::fake('private');
        Mail::fake();
        config()->set('dashboard_reports.monthly_recipients', '');

        $this->artisan('dashboard:monthly-report', ['--month' => '2026-05', '--send' => true])
            ->assertExitCode(0);

        Mail::assertNothingSent();
        Storage::disk('private')->assertExists('dashboard-monthly-reports/2026-05.pdf');
        $this->assertDatabaseHas('dashboard_monthly_reports', [
            'report_month' => '2026-05',
            'status' => 'generated',
        ]);
        $this->assertSame(0, DB::table('dashboard_monthly_report_email_logs')->count());
        $this->assertNull(DB::table('dashboard_monthly_reports')->where('report_month', '2026-05')->value('email_sent_at'));
    }

    public function test_monthly_dashboard_report_dry_run_uses_previous_month_across_year_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        $this->artisan('dashboard:monthly-report', ['--dry-run' => true])
            ->expectsOutput('Year-to-date dashboard report dry run for 2025-12 (01 Jan 2025 to 31 Dec 2025).')
            ->assertExitCode(0);
    }

    public function test_monthly_dashboard_report_ytd_range_handles_january_report_month(): void
    {
        $this->artisan('dashboard:monthly-report', ['--month' => '2026-01', '--dry-run' => true])
            ->expectsOutput('Year-to-date dashboard report dry run for 2026-01 (01 Jan 2026 to 31 Jan 2026).')
            ->assertExitCode(0);
    }

    public function test_workload_share_creates_public_expiring_snapshot(): void
    {
        $response = $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 1,
                'name_code' => 'AZA',
                'full_name' => 'Azam Bin Husain',
                'roles' => ['System Admin'],
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/stats/workload/share', [
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-31',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('path', '/share/workload/'.$response->json('token'));

        $token = (string) $response->json('token');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{48}$/', $token);
        $this->assertDatabaseHas('workload_dashboard_shares', [
            'created_by_staff_id' => 1,
            'created_by_code' => 'AZA',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);

        DB::table('workload_dashboard_shares')->update([
            'payload_json' => json_encode([
                'status' => 'success',
                'completedWindow' => ['startDate' => '2026-05-01', 'endDate' => '2026-05-31'],
                'staff' => [
                    [
                        'staffKey' => '1',
                        'staffCode' => 'AZA',
                        'score' => 10,
                        'completedInPeriod' => 1,
                        'scoreBreakdown' => [
                            ['label' => 'Non-project tasks', 'points' => 4],
                            ['label' => 'Project responsibility', 'points' => 1],
                            ['label' => 'Deadline pressure', 'points' => 2],
                            ['label' => 'Completed work', 'points' => 3],
                        ],
                        'completedTasks' => [['title' => 'Legacy completed task']],
                        'otherTasks' => [],
                        'projectGroups' => [],
                    ],
                ],
            ]),
        ]);

        $publicResponse = $this->get('/stats/workload/share/'.$token);

        $publicResponse->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('completedWindow.startDate', '2026-05-01')
            ->assertJsonPath('completedWindow.endDate', '2026-05-31')
            ->assertJsonPath('staff.0.score', 7)
            ->assertJsonPath('staff.0.completedInPeriod', 0)
            ->assertJsonPath('staff.0.completedTasks', [])
            ->assertJsonMissing(['label' => 'Completed work'])
            ->assertJsonStructure(['staff', 'share' => ['expiresAt', 'createdAt']]);
        $this->assertStringContainsString('no-store', (string) $publicResponse->headers->get('Cache-Control'));

        DB::table('workload_dashboard_shares')->update(['expires_at' => '2026-05-10 00:00:00']);

        $this->get('/stats/workload/share/'.$token)
            ->assertStatus(410)
            ->assertJsonPath('message', 'This shared workload dashboard has expired.');
    }

    public function test_workload_capture_daily_command_writes_and_skips_existing_snapshot(): void
    {
        DB::table('tasks')->insert([
            'staff_id' => 1,
            'project_id' => null,
            'project_progress_id' => null,
            'title' => 'Daily captured workload',
            'status' => 'Ongoing',
            'due_date' => '2026-05-20',
            'created_at' => '2026-05-10 09:00:00',
            'completed_at' => null,
        ]);

        $this->artisan('workload:capture-daily', ['--date' => '2026-05-31'])
            ->expectsOutput('Captured workload snapshot 2026-05-31 (1 staff row(s)).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('workload_daily_snapshots', [
            'snapshot_date' => '2026-05-31',
            'start_date' => '2026-05-31',
            'end_date' => '2026-05-31',
            'staff_count' => 1,
            'capture_mode' => 'captured',
        ]);
        $this->assertDatabaseHas('workload_daily_staff_snapshots', [
            'snapshot_date' => '2026-05-31',
            'staff_id' => 1,
            'staff_key' => '1',
            'staff_code' => 'AZA',
            'active_tasks' => 1,
            'overdue_tasks' => 1,
        ]);

        $this->artisan('workload:capture-daily', ['--date' => '2026-05-31'])
            ->expectsOutput('Workload snapshot 2026-05-31 already exists; skipped (1 staff row(s)).')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('workload_daily_snapshots')->where('snapshot_date', '2026-05-31')->count());
        $this->assertSame(1, DB::table('workload_daily_staff_snapshots')->where('snapshot_date', '2026-05-31')->count());
    }

    public function test_workload_capture_daily_command_force_replaces_existing_snapshot(): void
    {
        DB::table('tasks')->insert([
            'staff_id' => 1,
            'project_id' => null,
            'project_progress_id' => null,
            'title' => 'Original daily captured workload',
            'status' => 'Ongoing',
            'due_date' => '2026-05-20',
            'created_at' => '2026-05-10 09:00:00',
            'completed_at' => null,
        ]);

        $this->artisan('workload:capture-daily', ['--date' => '2026-05-31'])->assertExitCode(0);

        DB::table('tasks')->insert([
            'staff_id' => 2,
            'project_id' => null,
            'project_progress_id' => null,
            'title' => 'Force refreshed daily captured workload',
            'status' => 'Ongoing',
            'due_date' => '2026-05-20',
            'created_at' => '2026-05-10 09:00:00',
            'completed_at' => null,
        ]);

        $this->artisan('workload:capture-daily', ['--date' => '2026-05-31', '--force' => true])
            ->expectsOutput('Captured workload snapshot 2026-05-31 (2 staff row(s)).')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('workload_daily_snapshots')->where('snapshot_date', '2026-05-31')->count());
        $this->assertSame(2, DB::table('workload_daily_staff_snapshots')->where('snapshot_date', '2026-05-31')->count());
        $this->assertDatabaseHas('workload_daily_snapshots', [
            'snapshot_date' => '2026-05-31',
            'staff_count' => 2,
            'capture_mode' => 'captured',
        ]);
    }

    public function test_workload_capture_daily_command_replays_missing_range_as_reconstructed_and_skips_existing(): void
    {
        DB::table('tasks')->insert([
            'staff_id' => 1,
            'project_id' => null,
            'project_progress_id' => null,
            'title' => 'Replay range workload',
            'status' => 'Ongoing',
            'due_date' => '2026-05-08',
            'created_at' => '2026-05-01 09:00:00',
            'completed_at' => null,
        ]);
        DB::table('workload_daily_snapshots')->insert([
            'snapshot_date' => '2026-05-10',
            'start_date' => '2026-05-10',
            'end_date' => '2026-05-10',
            'staff_count' => 0,
            'payload_json' => '{"status":"success","source":"existing"}',
            'capture_mode' => 'captured',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('workload:capture-daily', [
            '--start-date' => '2026-05-09',
            '--end-date' => '2026-05-11',
            '--repair-only' => true,
        ])
            ->expectsOutput('Replaying workload snapshots 2026-05-09 to 2026-05-11 (3 day(s)).')
            ->expectsOutput('CAPTURED 2026-05-09 (1 staff row(s)).')
            ->expectsOutput('SKIPPED 2026-05-10 (0 existing staff row(s)).')
            ->expectsOutput('CAPTURED 2026-05-11 (1 staff row(s)).')
            ->expectsOutput('Replay summary: captured 2, skipped 1, failed 0.')
            ->assertExitCode(0);

        $this->assertSame(3, DB::table('workload_daily_snapshots')->count());
        $this->assertDatabaseHas('workload_daily_snapshots', [
            'snapshot_date' => '2026-05-09',
            'capture_mode' => 'reconstructed',
            'captured_by_command' => 'workload:capture-daily --repair-only',
        ]);
        $this->assertDatabaseHas('workload_daily_snapshots', [
            'snapshot_date' => '2026-05-10',
            'capture_mode' => 'captured',
            'staff_count' => 0,
        ]);
        $this->assertDatabaseHas('workload_daily_snapshots', [
            'snapshot_date' => '2026-05-11',
            'capture_mode' => 'reconstructed',
        ]);
    }

    public function test_workload_capture_daily_command_rejects_unsafe_range_replay_options(): void
    {
        $this->artisan('workload:capture-daily', [
            '--start-date' => '2026-05-09',
            '--end-date' => '2026-05-11',
        ])
            ->expectsOutput('Range replay requires --repair-only.')
            ->assertExitCode(1);

        $this->artisan('workload:capture-daily', [
            '--start-date' => '2026-05-09',
            '--end-date' => '2026-05-11',
            '--repair-only' => true,
            '--force' => true,
        ])
            ->expectsOutput('--force is not allowed with range replay.')
            ->assertExitCode(1);

        $this->artisan('workload:capture-daily', [
            '--start-date' => '2026-04-10',
            '--end-date' => '2026-05-10',
            '--repair-only' => true,
        ])
            ->expectsOutput('Range replay is limited to the latest 31-day window.')
            ->assertExitCode(1);

        $this->artisan('workload:capture-daily', [
            '--start-date' => '2026-04-10',
            '--end-date' => '2026-05-11',
            '--repair-only' => true,
        ])
            ->expectsOutput('Range replay is limited to 31 calendar days.')
            ->assertExitCode(1);
    }

    public function test_workload_history_returns_only_snapshots_in_requested_range(): void
    {
        DB::table('workload_daily_snapshots')->insert([
            [
                'snapshot_date' => '2026-05-28',
                'start_date' => '2026-05-28',
                'end_date' => '2026-05-28',
                'staff_count' => 1,
                'payload_json' => '{"status":"success"}',
                'capture_mode' => 'captured',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'snapshot_date' => '2026-05-29',
                'start_date' => '2026-05-29',
                'end_date' => '2026-05-29',
                'staff_count' => 2,
                'payload_json' => '{"status":"success"}',
                'capture_mode' => 'reconstructed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'snapshot_date' => '2026-05-30',
                'start_date' => '2026-05-30',
                'end_date' => '2026-05-30',
                'staff_count' => 1,
                'payload_json' => '{"status":"success"}',
                'capture_mode' => 'captured',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('workload_daily_staff_snapshots')->insert([
            [
                'snapshot_date' => '2026-05-28',
                'staff_id' => 1,
                'staff_key' => '1',
                'staff_code' => 'AZA',
                'staff_name' => 'Azam Bin Husain',
                'score' => 9,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'snapshot_date' => '2026-05-29',
                'staff_id' => 1,
                'staff_key' => '1',
                'staff_code' => 'AZA',
                'staff_name' => 'Azam Bin Husain',
                'score' => 12,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'snapshot_date' => '2026-05-29',
                'staff_id' => 2,
                'staff_key' => '2',
                'staff_code' => 'BOB',
                'staff_name' => 'Bob Tester',
                'score' => 15,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'snapshot_date' => '2026-05-30',
                'staff_id' => 1,
                'staff_key' => '1',
                'staff_code' => 'AZA',
                'staff_name' => 'Azam Bin Husain',
                'score' => 18,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->authenticatedGet('/stats/workload/history?start_date=2026-05-29&end_date=2026-05-30');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('startDate', '2026-05-29')
            ->assertJsonPath('endDate', '2026-05-30');

        $staff = collect($response->json('staff'));
        $this->assertSame(['AZA', 'BOB'], $staff->pluck('staffCode')->all());
        $this->assertSame(
            [
                ['date' => '2026-05-29', 'score' => 12, 'captureMode' => 'reconstructed'],
                ['date' => '2026-05-30', 'score' => 18, 'captureMode' => 'captured'],
            ],
            $staff->firstWhere('staffCode', 'AZA')['points']
        );
        $this->assertSame(
            [
                ['date' => '2026-05-29', 'score' => 15, 'captureMode' => 'reconstructed'],
            ],
            $staff->firstWhere('staffCode', 'BOB')['points']
        );
    }

    public function test_workload_history_returns_empty_staff_when_no_snapshots_exist(): void
    {
        $response = $this->authenticatedGet('/stats/workload/history?start_date=2026-05-01&end_date=2026-05-31');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('staff', []);
    }

    public function test_workload_normalize_current_scores_command_repairs_snapshot_scores(): void
    {
        $legacyBreakdown = [
            ['label' => 'Non-project tasks', 'points' => 6],
            ['label' => 'Project responsibility', 'points' => 2],
            ['label' => 'Deadline pressure', 'points' => 1.5],
            ['label' => 'Completed work', 'points' => 4],
        ];
        $legacyPayload = [
            'staffKey' => '1',
            'staffCode' => 'AZA',
            'score' => 13.5,
            'completedInPeriod' => 2,
            'lateCompletedInPeriod' => 1,
            'scoreBreakdown' => $legacyBreakdown,
            'otherTasks' => [
                ['title' => 'Active work', 'status' => 'Ongoing', 'workType' => 'technical_specialist', 'workTypeLabel' => 'Technical / Specialist', 'effortScore' => 3],
            ],
            'completedTasks' => [
                ['title' => 'Completed work', 'status' => 'Completed', 'workType' => 'technical_specialist', 'workTypeLabel' => 'Technical / Specialist', 'effortScore' => 4],
            ],
            'projectGroups' => [
                [
                    'projectId' => 9,
                    'projectName' => 'Current Project',
                    'activeTasks' => [
                        ['title' => 'Project work', 'status' => 'Ongoing', 'workType' => 'coordination_followup', 'workTypeLabel' => 'Coordination / Follow-up', 'effortScore' => 2],
                    ],
                    'completedTasks' => [
                        ['title' => 'Completed project work', 'status' => 'Completed'],
                    ],
                    'progressUpdates' => [
                        ['progressText' => 'Task-linked complete', 'sourceType' => 'task', 'sourceTaskId' => 88],
                        ['progressText' => 'Manual update', 'sourceType' => null, 'sourceTaskId' => null],
                    ],
                    'scoreableProgressCount' => 1,
                ],
            ],
        ];

        DB::table('workload_daily_snapshots')->insert([
            'snapshot_date' => '2026-05-29',
            'start_date' => '2026-05-29',
            'end_date' => '2026-05-29',
            'staff_count' => 3,
            'total_score' => 19.5,
            'avg_score' => 6.5,
            'total_completed_in_period' => 2,
            'payload_json' => '{"status":"success"}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('workload_daily_staff_snapshots')->insert([
            [
                'snapshot_date' => '2026-05-29',
                'staff_id' => 1,
                'staff_key' => '1',
                'staff_code' => 'AZA',
                'staff_name' => 'Azam Bin Husain',
                'score' => 13.5,
                'completed_in_period' => 2,
                'late_completed_in_period' => 1,
                'score_breakdown_json' => json_encode($legacyBreakdown),
                'row_payload_json' => json_encode($legacyPayload),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'snapshot_date' => '2026-05-29',
                'staff_id' => 2,
                'staff_key' => '2',
                'staff_code' => 'BOB',
                'staff_name' => 'Bob Tester',
                'score' => 6,
                'completed_in_period' => 0,
                'late_completed_in_period' => 0,
                'score_breakdown_json' => json_encode([
                    ['label' => 'Non-project tasks', 'points' => 6],
                ]),
                'row_payload_json' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'snapshot_date' => '2026-05-29',
                'staff_id' => 3,
                'staff_key' => '3',
                'staff_code' => 'BAD',
                'staff_name' => 'Bad Json',
                'score' => 2,
                'completed_in_period' => 0,
                'late_completed_in_period' => 0,
                'score_breakdown_json' => '{bad-json',
                'row_payload_json' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->artisan('workload:normalize-current-scores', ['--dry-run' => true])
            ->expectsOutput('Dry run workload current-score normalization: 1 row(s) would be updated, 1 unchanged, 1 skipped, 1 snapshot day(s) affected.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('workload_daily_staff_snapshots', [
            'staff_key' => '1',
            'score' => 13.5,
            'completed_in_period' => 2,
        ]);

        $this->artisan('workload:normalize-current-scores')
            ->expectsOutput('Completed workload current-score normalization: 1 row(s) updated, 1 unchanged, 1 skipped, 1 snapshot day(s) affected.')
            ->assertExitCode(0);

        $row = DB::table('workload_daily_staff_snapshots')->where('staff_key', '1')->first();
        $this->assertSame(9.5, (float) $row->score);
        $this->assertSame(0, (int) $row->completed_in_period);
        $this->assertSame(
            [
                ['label' => 'Non-project tasks', 'points' => 6],
                ['label' => 'Project responsibility', 'points' => 2],
                ['label' => 'Deadline pressure', 'points' => 1.5],
            ],
            json_decode((string) $row->score_breakdown_json, true)
        );

        $payload = json_decode((string) $row->row_payload_json, true);
        $this->assertSame(9.5, (float) $payload['score']);
        $this->assertSame(0, (int) $payload['completedInPeriod']);
        $this->assertSame([], $payload['completedTasks']);
        $this->assertSame([], $payload['projectGroups'][0]['completedTasks']);
        $this->assertSame(['Manual update'], collect($payload['projectGroups'][0]['progressUpdates'])->pluck('progressText')->all());
        $this->assertSame(['Technical / Specialist', 'Coordination / Follow-up'], collect($payload['workTypeBreakdown'])->pluck('workTypeLabel')->all());

        $this->assertDatabaseHas('workload_daily_snapshots', [
            'snapshot_date' => '2026-05-29',
            'total_score' => 17.5,
            'avg_score' => 5.83,
            'total_completed_in_period' => 0,
        ]);

        $this->artisan('workload:normalize-current-scores')
            ->expectsOutput('Completed workload current-score normalization: 0 row(s) updated, 2 unchanged, 1 skipped, 0 snapshot day(s) affected.')
            ->assertExitCode(0);
    }

    public function test_workload_daily_capture_check_creates_one_check_and_notification_without_duplicates(): void
    {
        $this->artisan('workload:check-daily-capture', ['--date' => '2026-05-10'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('workload_daily_snapshot_checks')->where([
            'snapshot_date' => '2026-05-10',
            'check_key' => 'missed_capture',
        ])->count());
        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 1,
            'module_key' => 'system.admin.workload_snapshots',
            'entity_type' => 'workload_daily_snapshot',
            'entity_id' => 20260510,
            'type' => 'workload_daily_snapshot_missing',
            'severity' => 'danger',
        ]);

        $this->artisan('workload:check-daily-capture', ['--date' => '2026-05-10'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('workload_daily_snapshot_checks')->where([
            'snapshot_date' => '2026-05-10',
            'check_key' => 'missed_capture',
        ])->count());
        $this->assertSame(1, DB::table('in_app_notifications')
            ->where('module_key', 'system.admin.workload_snapshots')
            ->where('entity_id', 20260510)
            ->whereNull('resolved_at')
            ->count());
    }

    public function test_workload_daily_capture_resolves_missed_capture_alert_when_snapshot_later_exists(): void
    {
        $this->artisan('workload:check-daily-capture', ['--date' => '2026-05-10'])
            ->assertExitCode(0);

        DB::table('tasks')->insert([
            'staff_id' => 1,
            'project_id' => null,
            'project_progress_id' => null,
            'title' => 'Recovered workload capture',
            'status' => 'Ongoing',
            'due_date' => '2026-05-10',
            'created_at' => '2026-05-10 09:00:00',
            'completed_at' => null,
        ]);

        $this->artisan('workload:capture-daily', ['--date' => '2026-05-10'])
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('workload_daily_snapshot_checks')->where([
            'snapshot_date' => '2026-05-10',
            'check_key' => 'missed_capture',
        ])->count());
        $this->assertSame(1, DB::table('in_app_notifications')
            ->where('module_key', 'system.admin.workload_snapshots')
            ->where('entity_id', 20260510)
            ->whereNotNull('resolved_at')
            ->count());
    }

    public function test_workload_daily_capture_records_suspicious_snapshot_checks(): void
    {
        DB::table('tasks')->delete();

        $this->artisan('workload:capture-daily', ['--date' => '2026-05-31'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('workload_daily_snapshot_checks', [
            'snapshot_date' => '2026-05-31',
            'severity' => 'critical',
            'check_key' => 'zero_staff',
        ]);
        $this->assertDatabaseHas('workload_daily_snapshot_checks', [
            'snapshot_date' => '2026-05-31',
            'severity' => 'warning',
            'check_key' => 'active_tasks_zero',
        ]);

        DB::table('workload_daily_snapshot_checks')->delete();
        DB::table('workload_daily_snapshots')->insert([
            [
                'snapshot_date' => '2026-06-01',
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-01',
                'staff_count' => 4,
                'total_score' => 100,
                'total_active_tasks' => 12,
                'payload_json' => '{"status":"success"}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'snapshot_date' => '2026-06-02',
                'start_date' => '2026-06-02',
                'end_date' => '2026-06-02',
                'staff_count' => 2,
                'total_score' => 170,
                'total_active_tasks' => 12,
                'payload_json' => '{"status":"success"}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        app(WorkloadSnapshotHealthService::class)->recordCaptureChecks('2026-06-02');

        $this->assertDatabaseHas('workload_daily_snapshot_checks', [
            'snapshot_date' => '2026-06-02',
            'severity' => 'warning',
            'check_key' => 'score_jump',
        ]);
        $this->assertDatabaseHas('workload_daily_snapshot_checks', [
            'snapshot_date' => '2026-06-02',
            'severity' => 'warning',
            'check_key' => 'staff_count_drop',
        ]);
    }

    public function test_workload_snapshot_health_endpoint_requires_system_admin_and_reports_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00'));
        DB::table('workload_daily_snapshots')->insert([
            [
                'snapshot_date' => '2026-05-30',
                'start_date' => '2026-05-30',
                'end_date' => '2026-05-30',
                'staff_count' => 1,
                'total_score' => 21,
                'avg_score' => 21,
                'total_active_tasks' => 4,
                'total_overdue_tasks' => 0,
                'total_due_soon_tasks' => 0,
                'payload_json' => '{"status":"success"}',
                'capture_mode' => 'reconstructed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'snapshot_date' => '2026-05-31',
                'start_date' => '2026-05-31',
                'end_date' => '2026-05-31',
                'staff_count' => 2,
                'total_score' => 41,
                'avg_score' => 20.5,
                'total_active_tasks' => 9,
                'total_overdue_tasks' => 3,
                'total_due_soon_tasks' => 1,
                'payload_json' => '{"status":"success"}',
                'capture_mode' => 'captured',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->authenticatedGet('/stats/workload/snapshot-health')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.captureStatus', 'ok')
            ->assertJsonPath('data.capturedSnapshotsLast31Days', 1)
            ->assertJsonPath('data.reconstructedSnapshotsLast31Days', 1)
            ->assertJsonPath('data.latestSnapshot.captureMode', 'captured')
            ->assertJsonPath('data.latestSnapshot.staffCount', 2)
            ->assertJsonPath('data.latestSnapshot.avgScore', 20.5);

        DB::table('system_users')->insert([
            'id' => 2,
            'staff_id' => 2,
            'email' => 'dashboard-staff@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
        ]);

        $this->authenticatedGetAs('/stats/workload/snapshot-health', ['Staff'], 2, 2)
            ->assertStatus(403);
    }

    public function test_workload_snapshot_health_reports_missing_and_warning_states(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00'));

        $this->authenticatedGet('/stats/workload/snapshot-health')
            ->assertOk()
            ->assertJsonPath('data.captureStatus', 'missing');

        DB::table('workload_daily_snapshots')->insert([
            'snapshot_date' => '2026-05-31',
            'start_date' => '2026-05-31',
            'end_date' => '2026-05-31',
            'staff_count' => 2,
            'total_score' => 41,
            'avg_score' => 20.5,
            'total_active_tasks' => 9,
            'payload_json' => '{"status":"success"}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('workload_daily_snapshot_checks')->insert([
            'snapshot_date' => '2026-05-31',
            'severity' => 'warning',
            'check_key' => 'score_jump',
            'message' => 'Score jumped.',
            'metadata_json' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->authenticatedGet('/stats/workload/snapshot-health')
            ->assertOk()
            ->assertJsonPath('data.captureStatus', 'warning')
            ->assertJsonPath('data.checkCounts.warning', 1);
    }

    public function test_workload_snapshot_payload_prune_nulls_only_large_old_payloads(): void
    {
        DB::table('workload_daily_snapshots')->insert([
            [
                'snapshot_date' => '2025-10-01',
                'start_date' => '2025-10-01',
                'end_date' => '2025-10-01',
                'staff_count' => 1,
                'payload_json' => '{"large":"old"}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'snapshot_date' => '2026-05-01',
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-01',
                'staff_count' => 1,
                'payload_json' => '{"large":"recent"}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('workload_daily_staff_snapshots')->insert([
            [
                'snapshot_date' => '2025-10-01',
                'staff_id' => 1,
                'staff_key' => '1',
                'staff_code' => 'AZA',
                'score' => 12,
                'score_breakdown_json' => '{"kept":true}',
                'work_type_breakdown_json' => '{"kept":true}',
                'row_payload_json' => '{"large":"old"}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'snapshot_date' => '2026-05-01',
                'staff_id' => 1,
                'staff_key' => '1',
                'staff_code' => 'AZA',
                'score' => 13,
                'score_breakdown_json' => '{"kept":true}',
                'work_type_breakdown_json' => '{"kept":true}',
                'row_payload_json' => '{"large":"recent"}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->artisan('workload:prune-snapshot-payloads', ['--older-than-days' => 180])
            ->assertExitCode(0);

        $this->assertDatabaseHas('workload_daily_snapshots', [
            'snapshot_date' => '2025-10-01',
            'payload_json' => null,
        ]);
        $this->assertDatabaseHas('workload_daily_staff_snapshots', [
            'snapshot_date' => '2025-10-01',
            'staff_key' => '1',
            'row_payload_json' => null,
            'score_breakdown_json' => '{"kept":true}',
            'work_type_breakdown_json' => '{"kept":true}',
        ]);
        $this->assertDatabaseHas('workload_daily_snapshots', [
            'snapshot_date' => '2026-05-01',
            'payload_json' => '{"large":"recent"}',
        ]);
        $this->assertDatabaseHas('workload_daily_staff_snapshots', [
            'snapshot_date' => '2026-05-01',
            'staff_key' => '1',
            'row_payload_json' => '{"large":"recent"}',
        ]);
    }

    public function test_workload_stats_excludes_inactive_staff_even_when_they_have_open_work(): void
    {
        DB::table('staff_general')->insert([
            [
                'staff_id' => 3,
                'name_code' => 'INA',
                'full_name' => 'Inactive Staff',
                'status' => 'Inactive',
                'terminated_at' => null,
                'deleted_at' => null,
            ],
            [
                'staff_id' => 4,
                'name_code' => 'TRM',
                'full_name' => 'Terminated Staff',
                'status' => 'Active',
                'terminated_at' => '2026-05-01 00:00:00',
                'deleted_at' => '2026-05-01 00:00:00',
            ],
        ]);

        DB::table('projects_main')->insert([
            'id' => 550,
            'project_name' => 'Inactive Staff Project',
            'client_id' => 550,
            'status' => 'Active',
            'created_by' => 1,
        ]);

        DB::table('project_collaborators')->insert([
            ['project_id' => 550, 'staff_id' => 1, 'project_role' => 'Leader'],
            ['project_id' => 550, 'staff_id' => 3, 'project_role' => 'Leader'],
            ['project_id' => 550, 'staff_id' => 4, 'project_role' => 'Leader'],
        ]);

        DB::table('tasks')->insert([
            [
                'staff_id' => 1,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => 'Active staff open workload',
                'status' => 'Ongoing',
                'due_date' => '2026-05-15',
                'created_at' => '2026-05-10 09:00:00',
                'completed_at' => null,
            ],
            [
                'staff_id' => 3,
                'project_id' => 550,
                'project_progress_id' => null,
                'title' => 'Inactive staff open workload',
                'status' => 'Ongoing',
                'due_date' => '2026-05-15',
                'created_at' => '2026-05-10 09:00:00',
                'completed_at' => null,
            ],
            [
                'staff_id' => 4,
                'project_id' => 550,
                'project_progress_id' => null,
                'title' => 'Terminated staff open workload',
                'status' => 'Ongoing',
                'due_date' => '2026-05-15',
                'created_at' => '2026-05-10 09:00:00',
                'completed_at' => null,
            ],
        ]);

        DB::table('project_progress')->insert([
            [
                'project_id' => 550,
                'progress_date' => '2026-05-20',
                'progress_text' => 'Active staff progress',
                'updated_by' => 1,
                'updated_on' => '2026-05-20 09:00:00',
                'source_type' => null,
                'source_task_id' => null,
            ],
            [
                'project_id' => 550,
                'progress_date' => '2026-05-20',
                'progress_text' => 'Inactive staff progress',
                'updated_by' => 3,
                'updated_on' => '2026-05-20 09:00:00',
                'source_type' => null,
                'source_task_id' => null,
            ],
        ]);

        $response = $this->authenticatedGet('/stats/workload?start_date=2026-05-01&end_date=2026-05-31');

        $response->assertOk()->assertJsonPath('status', 'success');

        $rows = collect($response->json('staff'));
        $this->assertContains('AZA', $rows->pluck('staffCode')->all());
        $this->assertNotContains('INA', $rows->pluck('staffCode')->all());
        $this->assertNotContains('TRM', $rows->pluck('staffCode')->all());

        $allTaskTitles = $rows->flatMap(function (array $row): array {
            return collect($row['otherTasks'])
                ->pluck('title')
                ->merge(
                    collect($row['projectGroups'])
                        ->flatMap(fn (array $group) => collect($group['activeTasks'])->pluck('title'))
                )
                ->all();
        })->all();
        $allProgressText = $rows->flatMap(fn (array $row) => collect($row['projectGroups'])
            ->flatMap(fn (array $group) => collect($group['progressUpdates'])->pluck('progressText')))->all();

        $this->assertContains('Active staff open workload', $allTaskTitles);
        $this->assertNotContains('Inactive staff open workload', $allTaskTitles);
        $this->assertNotContains('Terminated staff open workload', $allTaskTitles);
        $this->assertContains('Active staff progress', $allProgressText);
        $this->assertNotContains('Inactive staff progress', $allProgressText);
    }

    public function test_workload_score_counts_all_project_tasks_and_weights_role_and_value(): void
    {
        DB::table('staff_general')->insert([
            ['staff_id' => 3, 'name_code' => 'SPM', 'full_name' => 'Spam Tester', 'status' => 'Active'],
            ['staff_id' => 4, 'name_code' => 'LEA', 'full_name' => 'Leader Tester', 'status' => 'Active'],
            ['staff_id' => 5, 'name_code' => 'COL', 'full_name' => 'Collaborator Tester', 'status' => 'Active'],
            ['staff_id' => 6, 'name_code' => 'HVL', 'full_name' => 'High Value Leader', 'status' => 'Active'],
        ]);

        DB::table('projects_main')->insert([
            ['id' => 601, 'project_name' => 'Spam Project', 'quote_value' => 1000, 'status' => 'Active', 'created_by' => 3],
            ['id' => 602, 'project_name' => 'Leader Project', 'quote_value' => 1000, 'status' => 'Active', 'created_by' => 4],
            ['id' => 603, 'project_name' => 'Collaborator Project', 'quote_value' => 1000, 'status' => 'Active', 'created_by' => 5],
            ['id' => 604, 'project_name' => 'High Value Project', 'quote_value' => 600000, 'status' => 'Active', 'created_by' => 6],
        ]);

        DB::table('project_collaborators')->insert([
            ['project_id' => 601, 'staff_id' => 3, 'project_role' => 'Leader', 'role_description' => null],
            ['project_id' => 602, 'staff_id' => 4, 'project_role' => 'Leader', 'role_description' => null],
            ['project_id' => 603, 'staff_id' => 5, 'project_role' => 'Collaborator', 'role_description' => null],
            ['project_id' => 604, 'staff_id' => 6, 'project_role' => 'Leader', 'role_description' => null],
        ]);

        $tasks = [];
        for ($i = 1; $i <= 8; $i++) {
            $tasks[] = [
                'staff_id' => 3,
                'project_id' => 601,
                'project_progress_id' => null,
                'title' => "Spam task {$i}",
                'status' => 'Ongoing',
                'due_date' => '2026-05-30',
                'created_at' => '2026-05-02 09:00:00',
                'completed_at' => null,
            ];
        }
        foreach ([[4, 602, 'Leader task'], [5, 603, 'Collaborator task'], [6, 604, 'High value task']] as [$staffId, $projectId, $title]) {
            $tasks[] = [
                'staff_id' => $staffId,
                'project_id' => $projectId,
                'project_progress_id' => null,
                'title' => $title,
                'status' => 'Ongoing',
                'due_date' => '2026-05-30',
                'created_at' => '2026-05-02 09:00:00',
                'completed_at' => null,
            ];
        }
        DB::table('tasks')->insert($tasks);

        for ($i = 1; $i <= 4; $i++) {
            DB::table('project_progress')->insert([
                'project_id' => 601,
                'progress_date' => "2026-05-1{$i}",
                'progress_text' => "Spam project progress {$i}",
                'updated_by' => 3,
                'updated_on' => "2026-05-1{$i} 09:00:00",
                'source_type' => null,
                'source_task_id' => null,
            ]);
        }

        $response = $this->authenticatedGet('/stats/workload?start_date=2026-05-01&end_date=2026-05-31');

        $response->assertOk()->assertJsonPath('status', 'success');

        $rows = collect($response->json('staff'));
        $spam = $rows->firstWhere('staffCode', 'SPM');
        $leader = $rows->firstWhere('staffCode', 'LEA');
        $collaborator = $rows->firstWhere('staffCode', 'COL');
        $highValueLeader = $rows->firstWhere('staffCode', 'HVL');

        $this->assertSame(8, (int) $spam['activeTasks']);
        $this->assertSame(8, (int) $spam['projectTaggedActiveTasks']);
        $this->assertSame(16.0, (float) $spam['score']);
        $this->assertSame(12.0, (float) $spam['projectGroups'][0]['scoreContribution']);
        $this->assertSame(8.0, (float) $spam['projectGroups'][0]['projectTaskPoints']);
        $this->assertSame(1.0, (float) $spam['projectGroups'][0]['projectBasePoints']);
        $this->assertSame(2.0, (float) $spam['projectGroups'][0]['projectProgressPoints']);
        $this->assertSame(1.0, (float) $spam['projectGroups'][0]['projectValuePoints']);
        $this->assertSame(4.0, (float) $spam['projectGroups'][0]['projectOverheadPoints']);
        $this->assertCount(4, $spam['projectGroups'][0]['progressUpdates']);

        $this->assertSame(3.5, (float) $leader['score']);
        $this->assertSame(2.4, (float) $collaborator['score']);
        $this->assertGreaterThan((float) $collaborator['score'], (float) $leader['score']);
        $this->assertSame('collaborator', $collaborator['projectGroups'][0]['projectRole']);
        $this->assertSame(0.45, (float) $collaborator['projectGroups'][0]['roleWeight']);
        $this->assertSame(1.0, (float) $collaborator['projectGroups'][0]['projectTaskPoints']);

        $this->assertSame(4.5, (float) $highValueLeader['score']);
        $this->assertGreaterThan((float) $leader['score'], (float) $highValueLeader['score']);
        $this->assertSame(5, (int) $highValueLeader['projectGroups'][0]['valueBand']);
        $this->assertSame(2.0, (float) $highValueLeader['projectGroups'][0]['projectValuePoints']);
        $this->assertLessThan(35, (float) $highValueLeader['score']);
    }

    public function test_workload_score_uses_effort_for_non_project_and_deadline_work_only(): void
    {
        $activeEfforts = [4.0, 3.0, 2.0, 1.0, 1.0, 1.0, 1.0, 1.0, 0.5];
        foreach ($activeEfforts as $index => $effortScore) {
            DB::table('tasks')->insert([
                'staff_id' => 1,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => "Effort active task {$index}",
                'task_category' => $effortScore >= 4 ? 'critical_escalation' : ($effortScore >= 3 ? 'real_effort' : 'administrative'),
                'effort_score' => $effortScore,
                'classification_confidence' => 'high',
                'classification_source' => 'system',
                'user_override' => false,
                'matched_pattern' => 'test-pattern',
                'status' => 'Ongoing',
                'due_date' => '2026-05-08',
                'created_at' => '2026-05-02 09:00:00',
                'completed_at' => null,
            ]);
        }

        for ($i = 1; $i <= 30; $i++) {
            DB::table('tasks')->insert([
                'staff_id' => 1,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => "Completed effort task {$i}",
                'task_category' => 'critical_escalation',
                'effort_score' => 4,
                'classification_confidence' => 'high',
                'classification_source' => 'system',
                'user_override' => false,
                'matched_pattern' => 'test-pattern',
                'status' => 'Completed',
                'due_date' => '2026-05-08',
                'created_at' => '2026-05-01 09:00:00',
                'completed_at' => '2026-05-10',
            ]);
        }

        $response = $this->authenticatedGet('/stats/workload?start_date=2026-05-01&end_date=2026-05-31');

        $response->assertOk()->assertJsonPath('status', 'success');

        $aza = collect($response->json('staff'))->firstWhere('staffCode', 'AZA');

        $this->assertSame(18.5, (float) $aza['score']);
        $this->assertSame(
            [
                ['label' => 'Non-project tasks', 'points' => 14.5],
                ['label' => 'Project responsibility', 'points' => 0],
                ['label' => 'Deadline pressure', 'points' => 4],
            ],
            $aza['scoreBreakdown']
        );
        $this->assertSame(9, (int) $aza['activeTasks']);
        $this->assertSame(0, (int) $aza['completedInPeriod']);
        $this->assertCount(0, $aza['completedTasks']);
        $this->assertSame(4.0, (float) collect($aza['otherTasks'])->firstWhere('title', 'Effort active task 0')['effortScore']);
        $this->assertSame(3.0, (float) collect($aza['otherTasks'])->firstWhere('title', 'Effort active task 1')['effortScore']);
    }

    public function test_workload_uses_period_end_as_snapshot_and_excludes_completed_work(): void
    {
        DB::table('tasks')->insert([
            [
                'staff_id' => 1,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => 'Past year open overdue task',
                'task_category' => 'real_effort',
                'effort_score' => 3,
                'classification_confidence' => 'high',
                'classification_source' => 'system',
                'user_override' => false,
                'matched_pattern' => 'test-pattern',
                'status' => 'Ongoing',
                'due_date' => '2026-01-15',
                'created_at' => '2025-12-01 09:00:00',
                'completed_at' => null,
            ],
            [
                'staff_id' => 1,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => 'Completed after snapshot task',
                'task_category' => 'coordination_follow_up',
                'effort_score' => 2,
                'classification_confidence' => 'high',
                'classification_source' => 'system',
                'user_override' => false,
                'matched_pattern' => 'test-pattern',
                'status' => 'Completed',
                'due_date' => '2026-03-20',
                'created_at' => '2026-02-01 09:00:00',
                'completed_at' => '2026-04-05',
            ],
            [
                'staff_id' => 1,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => 'Same day completed snapshot task',
                'task_category' => 'critical_escalation',
                'effort_score' => 4,
                'classification_confidence' => 'high',
                'classification_source' => 'system',
                'user_override' => false,
                'matched_pattern' => 'test-pattern',
                'status' => 'Completed',
                'due_date' => '2026-03-31',
                'created_at' => '2026-03-01 09:00:00',
                'completed_at' => '2026-03-31',
            ],
            [
                'staff_id' => 1,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => 'Completed inside snapshot window task',
                'task_category' => 'administrative',
                'effort_score' => 1,
                'classification_confidence' => 'high',
                'classification_source' => 'system',
                'user_override' => false,
                'matched_pattern' => 'test-pattern',
                'status' => 'Completed',
                'due_date' => '2026-02-10',
                'created_at' => '2026-02-01 09:00:00',
                'completed_at' => '2026-02-15',
            ],
            [
                'staff_id' => 1,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => 'Completed before snapshot window task',
                'task_category' => 'critical_escalation',
                'effort_score' => 4,
                'classification_confidence' => 'high',
                'classification_source' => 'system',
                'user_override' => false,
                'matched_pattern' => 'test-pattern',
                'status' => 'Completed',
                'due_date' => '2025-12-10',
                'created_at' => '2025-12-01 09:00:00',
                'completed_at' => '2025-12-20',
            ],
            [
                'staff_id' => 1,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => 'Completed without completed date task',
                'task_category' => 'real_effort',
                'effort_score' => 3,
                'classification_confidence' => 'high',
                'classification_source' => 'system',
                'user_override' => false,
                'matched_pattern' => 'test-pattern',
                'status' => 'Completed',
                'due_date' => '2026-03-20',
                'created_at' => '2026-03-01 09:00:00',
                'completed_at' => null,
            ],
            [
                'staff_id' => 1,
                'project_id' => null,
                'project_progress_id' => null,
                'title' => 'Future created task',
                'task_category' => 'critical_escalation',
                'effort_score' => 4,
                'classification_confidence' => 'high',
                'classification_source' => 'system',
                'user_override' => false,
                'matched_pattern' => 'test-pattern',
                'status' => 'Ongoing',
                'due_date' => '2026-04-10',
                'created_at' => '2026-04-01 09:00:00',
                'completed_at' => null,
            ],
        ]);

        $response = $this->authenticatedGet('/stats/workload?start_date=2026-01-01&end_date=2026-03-31');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('asOfDate', '2026-03-31')
            ->assertJsonPath('completedWindow.startDate', '2026-01-01')
            ->assertJsonPath('completedWindow.endDate', '2026-03-31');

        $aza = collect($response->json('staff'))->firstWhere('staffCode', 'AZA');
        $otherTasks = collect($aza['otherTasks']);
        $completedTasks = collect($aza['completedTasks']);

        $this->assertSame(6.75, (float) $aza['score']);
        $this->assertSame(2, (int) $aza['activeTasks']);
        $this->assertSame(2, (int) $aza['overdueTasks']);
        $this->assertSame(0, (int) $aza['completedInPeriod']);
        $this->assertSame(
            [
                ['label' => 'Non-project tasks', 'points' => 5],
                ['label' => 'Project responsibility', 'points' => 0],
                ['label' => 'Deadline pressure', 'points' => 1.75],
            ],
            $aza['scoreBreakdown']
        );
        $this->assertEqualsCanonicalizing(
            [
                'Past year open overdue task',
                'Completed after snapshot task',
            ],
            $otherTasks->pluck('title')->all()
        );
        $completedAfterSnapshot = $otherTasks->firstWhere('title', 'Completed after snapshot task');
        $this->assertSame('Ongoing', $completedAfterSnapshot['status']);
        $this->assertSame('', $completedAfterSnapshot['completedAt']);
        $this->assertSame([], $completedTasks->pluck('title')->all());
        $this->assertFalse($otherTasks->contains('title', 'Same day completed snapshot task'));
        $this->assertFalse($otherTasks->contains('title', 'Completed inside snapshot window task'));
        $this->assertFalse($otherTasks->contains('title', 'Completed without completed date task'));
        $this->assertFalse($otherTasks->contains('title', 'Completed before snapshot window task'));
        $this->assertFalse($otherTasks->contains('title', 'Future created task'));
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

    private function authenticatedPut(string $uri, array $payload)
    {
        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 1,
                'name_code' => 'AZA',
                'full_name' => 'Azam Bin Husain',
                'roles' => ['Manager', 'System Admin'],
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->putJson($uri, $payload);
    }

    private function authenticatedGet(string $uri)
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
            ->getJson($uri);
    }

    private function authenticatedGetAs(string $uri, array $roles, int $userId, int $staffId)
    {
        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => $userId,
                'staff_id' => $staffId,
                'name_code' => $staffId === 1 ? 'AZA' : 'BOB',
                'full_name' => $staffId === 1 ? 'Azam Bin Husain' : 'Bob Tester',
                'roles' => $roles,
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->getJson($uri);
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
        if (! method_exists($pdo, 'sqliteCreateFunction')) {
            return;
        }

        $pdo->sqliteCreateFunction('DATE_FORMAT', static function ($date, $format) {
            if (! $date) {
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
        $pdo->sqliteCreateFunction('CONCAT', static fn (...$parts) => implode('', array_map(
            static fn ($part) => (string) $part,
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
            'project_collaborators',
            'staff_general',
            'system_users',
            'in_app_notifications',
            'quote_price_exception_requests',
            'legal_compliance_assessments',
            'workload_daily_snapshot_checks',
            'workload_daily_staff_snapshots',
            'workload_daily_snapshots',
            'workload_dashboard_shares',
            'dashboard_monthly_report_test_logs',
            'dashboard_monthly_report_email_logs',
            'dashboard_monthly_report_schedule_settings',
            'dashboard_monthly_reports',
            'project_progress',
            'tasks',
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
            $table->string('status')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 1,
            'email' => 'dashboard-admin@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => 1,
        ]);

        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('recipient_staff_id')->index();
            $table->unsignedInteger('actor_staff_id')->nullable()->index();
            $table->string('module_key', 80)->index();
            $table->string('entity_type', 80)->index();
            $table->unsignedBigInteger('entity_id')->index();
            $table->string('type', 80)->index();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('route')->nullable();
            $table->string('severity', 40)->default('info');
            $table->json('metadata_json')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });

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

        Schema::create('legal_compliance_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('template_version', 50)->nullable();
            $table->json('template_snapshot')->nullable();
            $table->string('stage', 50)->default('details_saved');
            $table->string('company_name')->nullable();
            $table->text('site_location')->nullable();
            $table->string('client_pic_name')->nullable();
            $table->string('client_pic_email')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('project_name')->nullable();
            $table->date('assessment_date')->nullable();
            $table->text('assessor_name')->nullable();
            $table->text('assessor_email')->nullable();
            $table->json('selected_assessors')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by_staff_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_company', function (Blueprint $table) {
            $table->integer('company_id')->primary();
            $table->string('company_name')->nullable();
        });

        Schema::create('projects_main', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('client_id')->nullable();
            $table->string('project_name')->nullable();
            $table->integer('quote_id')->nullable();
            $table->string('project_type')->nullable();
            $table->decimal('quote_value', 15, 2)->nullable();
            $table->decimal('current_project_value', 15, 2)->nullable();
            $table->date('award_date')->nullable();
            $table->string('status')->nullable();
            $table->integer('created_by')->nullable();
        });

        Schema::create('project_collaborators', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->unsignedBigInteger('staff_id');
            $table->string('project_role')->nullable();
            $table->string('role_description')->nullable();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->unsignedInteger('project_id')->nullable();
            $table->unsignedInteger('project_progress_id')->nullable();
            $table->string('title');
            $table->string('task_category')->default('uncategorised');
            $table->decimal('effort_score', 4, 1)->default(1);
            $table->string('classification_confidence')->nullable();
            $table->string('classification_source')->default('system');
            $table->boolean('user_override')->default(false);
            $table->string('matched_pattern')->nullable();
            $table->string('work_type')->default('unclear');
            $table->string('work_type_confidence')->nullable();
            $table->string('work_type_matched_pattern')->nullable();
            $table->string('status');
            $table->date('due_date');
            $table->timestamp('created_at')->nullable();
            $table->date('completed_at')->nullable();
        });

        Schema::create('project_progress', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->date('progress_date');
            $table->longText('progress_text');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_on')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_task_id')->nullable();
        });

        Schema::create('workload_dashboard_shares', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->unsignedBigInteger('created_by_staff_id')->nullable();
            $table->string('created_by_code')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->longText('payload_json');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('dashboard_monthly_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_month', 7)->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('stored_path')->nullable();
            $table->string('public_token_hash', 64)->nullable()->unique();
            $table->timestamp('public_token_expires_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->json('recipients_json')->nullable();
            $table->longText('payload_json')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->unsignedInteger('generation_duration_ms')->nullable();
            $table->string('status', 40)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('dashboard_monthly_report_email_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('report_id')->nullable()->index();
            $table->string('report_month', 7)->index();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('send_type', 20)->default('production')->index();
            $table->string('status', 20)->default('pending')->index();
            $table->text('public_url')->nullable();
            $table->timestamp('public_token_expires_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('dashboard_monthly_report_test_logs', function (Blueprint $table) {
            $table->id();
            $table->string('report_month', 7);
            $table->string('recipient_email');
            $table->string('status', 20);
            $table->text('response_message')->nullable();
            $table->text('public_url')->nullable();
            $table->timestamp('public_token_expires_at')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('name_code', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dashboard_monthly_report_schedule_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('interval_value')->default(1);
            $table->string('interval_unit', 20)->default('months');
            $table->date('start_date');
            $table->string('send_time', 5)->default('08:30');
            $table->timestamp('next_send_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('updated_by_staff_id')->nullable();
            $table->string('updated_by_code', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('workload_daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->unique();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('staff_count')->default(0);
            $table->decimal('total_score', 12, 2)->default(0);
            $table->decimal('avg_score', 12, 2)->default(0);
            $table->unsignedInteger('total_active_tasks')->default(0);
            $table->unsignedInteger('total_overdue_tasks')->default(0);
            $table->unsignedInteger('total_due_soon_tasks')->default(0);
            $table->unsignedInteger('total_completed_in_period')->default(0);
            $table->longText('payload_json')->nullable();
            $table->string('capture_mode', 40)->default('captured');
            $table->string('captured_by_command', 120)->nullable();
            $table->text('capture_note')->nullable();
            $table->timestamps();
        });

        Schema::create('workload_daily_snapshot_checks', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->index();
            $table->string('severity', 40)->index();
            $table->string('check_key', 80);
            $table->text('message')->nullable();
            $table->longText('metadata_json')->nullable();
            $table->timestamps();
            $table->unique(['snapshot_date', 'check_key'], 'workload_daily_snapshot_checks_unique');
        });

        Schema::create('workload_daily_staff_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->index();
            $table->unsignedBigInteger('staff_id')->nullable()->index();
            $table->string('staff_key')->index();
            $table->string('staff_code')->nullable();
            $table->string('staff_name')->nullable();
            $table->decimal('score', 12, 2)->default(0);
            $table->unsignedInteger('active_tasks')->default(0);
            $table->unsignedInteger('overdue_tasks')->default(0);
            $table->unsignedInteger('due_soon_tasks')->default(0);
            $table->unsignedInteger('project_tagged_active_tasks')->default(0);
            $table->unsignedInteger('project_group_count')->default(0);
            $table->unsignedInteger('completed_in_period')->default(0);
            $table->unsignedInteger('late_completed_in_period')->default(0);
            $table->integer('avg_days_lapsed')->default(0);
            $table->longText('score_breakdown_json')->nullable();
            $table->longText('work_type_breakdown_json')->nullable();
            $table->longText('row_payload_json')->nullable();
            $table->timestamps();
            $table->unique(['snapshot_date', 'staff_key'], 'workload_daily_staff_snapshot_unique');
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

    private function insertLegalComplianceAssessment(array $overrides = []): void
    {
        $payload = [
            'id' => $overrides['id'] ?? null,
            'staff_id' => $overrides['staff_id'] ?? 1,
            'template_id' => $overrides['template_id'] ?? null,
            'template_version' => $overrides['template_version'] ?? 'v1',
            'template_snapshot' => json_encode($overrides['template_snapshot'] ?? [
                'assessment_tier' => 'free',
            ]),
            'stage' => $overrides['stage'] ?? 'submitted',
            'company_name' => $overrides['company_name'] ?? 'Legal Prospect Sdn Bhd',
            'site_location' => $overrides['site_location'] ?? 'Shah Alam',
            'client_pic_name' => $overrides['client_pic_name'] ?? 'Client PIC',
            'client_pic_email' => $overrides['client_pic_email'] ?? 'pic@example.test',
            'project_id' => $overrides['project_id'] ?? null,
            'project_name' => $overrides['project_name'] ?? null,
            'assessment_date' => $overrides['assessment_date'] ?? '2026-05-16',
            'assessor_name' => $overrides['assessor_name'] ?? 'Azam Bin Husain',
            'assessor_email' => $overrides['assessor_email'] ?? 'azam@example.test',
            'selected_assessors' => json_encode($overrides['selected_assessors'] ?? [
                [
                    'value' => 1,
                    'label' => 'Azam Bin Husain (AZA) - azam@example.test',
                    'data' => [
                        'staff_id' => 1,
                        'full_name' => 'Azam Bin Husain',
                        'name_code' => 'AZA',
                        'email' => 'azam@example.test',
                    ],
                ],
            ]),
            'submitted_at' => $overrides['submitted_at'] ?? '2026-05-17 09:00:00',
            'submitted_by_staff_id' => $overrides['submitted_by_staff_id'] ?? 1,
            'deleted_at' => $overrides['deleted_at'] ?? null,
            'created_at' => $overrides['created_at'] ?? '2026-05-16 08:00:00',
            'updated_at' => $overrides['updated_at'] ?? '2026-05-17 09:00:00',
        ];

        if ($payload['id'] === null) {
            unset($payload['id']);
        }

        DB::table('legal_compliance_assessments')->insert($payload);
    }

    private function seedDashboardFacts(): void
    {
        DB::table('staff_general')->insert([
            [
                'staff_id' => 1,
                'name_code' => 'AZA',
                'full_name' => 'Azam Bin Husain',
                'status' => 'Active',
                'terminated_at' => null,
                'deleted_at' => null,
            ],
            [
                'staff_id' => 2,
                'name_code' => 'BOB',
                'full_name' => 'Bob Tester',
                'status' => 'Active',
                'terminated_at' => null,
                'deleted_at' => null,
            ],
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
                'entry_type' => 'closed',
                'prospect_name' => 'Manual Unknown Service',
                'entry_date' => '2026-05-08',
                'source' => 'Referral',
                'segment_type' => 'individual',
                'service_category' => 'unknown_service',
                'estimated_rm' => 900,
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
