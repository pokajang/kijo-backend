<?php

namespace Tests\Unit;

use App\Services\Assistant\CompanyAnalytics\AssistantCompanyAnalyticsIntent;
use App\Services\Assistant\CompanyAnalytics\CompanyAnalyticsAnswerFormatter;
use App\Services\Assistant\CompanyAnalytics\CompanyAnalyticsResult;
use Tests\TestCase;

class CompanyAnalyticsAnswerFormatterTest extends TestCase
{
    public function test_formats_company_answers_without_raw_json_or_iso_dates(): void
    {
        $intent = new AssistantCompanyAnalyticsIntent(
            'sales',
            'top',
            ['label' => 'this year', 'start' => '2026-01-01', 'end' => '2026-06-18', 'is_all_time' => false],
            supported: true,
            catalogEntry: ['metric_key' => 'company_analytics.sales'],
        );
        $result = new CompanyAnalyticsResult(
            'company_analytics.sales',
            'Company sales analytics',
            'Sales means awarded project value.',
            $intent->dateRange,
            ['sales_count' => 3, 'sales_value' => 3574070],
            [
                'top_staff' => [
                    ['name' => 'Aina Sales', 'sales_value' => 2500000, 'sales_count' => 2],
                    ['name' => 'Ben Ops', 'sales_value' => 1074070, 'sales_count' => 1],
                ],
                'by_month' => ['2026-01' => 1000000, '2026-02' => 2574070],
            ],
            [],
            [],
            [],
            'high',
            'Sales from 2026-01-01 to 2026-06-18.',
        );

        $answer = app(CompanyAnalyticsAnswerFormatter::class)->format([$result], $intent, ['company-analytics:test'], 'who has the most sales');
        $content = (string) $answer['answer_markdown'];

        $this->assertStringContainsString('1 Jan 2026 to 18 Jun 2026', $content);
        $this->assertStringContainsString('3,574,070.00', $content);
        $this->assertStringContainsString('Scope: company commercial records.', $content);
        $this->assertStringNotContainsString('2026-01-01', $content);
        $this->assertStringNotContainsString('{"top_staff"', $content);
        $this->assertContains('metric_cards', array_column($answer['display_blocks'], 'type'));
        $this->assertContains('table', array_column($answer['display_blocks'], 'type'));
        $this->assertContains('bar_chart', array_column($answer['display_blocks'], 'type'));
    }

    public function test_formats_count_breakdowns_as_counts_not_money(): void
    {
        $intent = new AssistantCompanyAnalyticsIntent(
            'crm_inquiries',
            'breakdown',
            ['label' => 'this year', 'start' => '2026-01-01', 'end' => '2026-06-18', 'is_all_time' => false],
            supported: true,
            catalogEntry: ['metric_key' => 'company_analytics.crm_inquiries'],
        );
        $result = new CompanyAnalyticsResult(
            'company_analytics.crm_inquiries',
            'Company CRM inquiry analytics',
            'CRM inquiry counts.',
            $intent->dateRange,
            ['inquiry_count' => 2, 'linked_quote_count' => 1, 'stale_open_count' => 1],
            [
                'by_service' => ['Training' => 2],
                'by_month' => ['2026-06' => 2],
            ],
        );

        $answer = app(CompanyAnalyticsAnswerFormatter::class)->format([$result], $intent, ['company-analytics:test'], 'crm inquiries by service');
        $content = (string) $answer['answer_markdown'];
        $table = collect($answer['display_blocks'])->firstWhere('type', 'table');

        $this->assertStringContainsString('By service: Training: 2.', $content);
        $this->assertStringNotContainsString('Training: 2.00', $content);
        $this->assertSame([['Training', '2']], $table['rows']);
    }

    public function test_ranking_table_keeps_name_as_first_column(): void
    {
        $intent = new AssistantCompanyAnalyticsIntent(
            'clients',
            'top',
            ['label' => 'this year', 'start' => '2026-01-01', 'end' => '2026-06-18', 'is_all_time' => false],
            supported: true,
            catalogEntry: ['metric_key' => 'company_analytics.clients'],
        );
        $result = new CompanyAnalyticsResult(
            'company_analytics.clients',
            'Company client contribution analytics',
            'Client contribution.',
            $intent->dateRange,
            ['client_count' => 1, 'awarded_value' => 5000],
            [
                'top_clients' => [
                    ['name' => 'Debtor Client Sdn Bhd', 'awarded_value' => 5000, 'record_count' => 1],
                ],
            ],
        );

        $answer = app(CompanyAnalyticsAnswerFormatter::class)->format([$result], $intent, ['company-analytics:test'], 'which client contributes most sales');
        $table = collect($answer['display_blocks'])->firstWhere('type', 'table');

        $this->assertSame(['Name', 'Awarded value', 'Record count'], $table['columns']);
        $this->assertSame([['Debtor Client Sdn Bhd', '5,000.00', '1']], $table['rows']);
    }
}
