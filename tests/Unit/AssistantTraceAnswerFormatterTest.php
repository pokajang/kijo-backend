<?php

namespace Tests\Unit;

use App\Services\Assistant\UserTrace\AssistantTraceAnswerFormatter;
use App\Services\Assistant\UserTrace\AssistantUserTraceIntent;
use App\Services\Assistant\UserTrace\AssistantUserTraceResult;
use Tests\TestCase;

class AssistantTraceAnswerFormatterTest extends TestCase
{
    public function test_formats_trace_answers_without_raw_json_or_iso_dates_in_visible_content(): void
    {
        $formatter = app(AssistantTraceAnswerFormatter::class);
        $intent = new AssistantUserTraceIntent(
            'self',
            'quote',
            'count',
            [
                'label' => 'current calendar year',
                'start' => '2026-01-01',
                'end' => '2026-12-31',
                'is_all_time' => false,
            ],
            supported: true,
            catalogEntry: ['analyzer' => 'quote'],
        );
        $result = new AssistantUserTraceResult(
            'user_trace.quote_issued',
            'My quotation trace',
            'Quote records created by the current user.',
            $intent->dateRange,
            ['count' => 41, 'total_value' => 3574070, 'all_matching_count_before_status_filter' => 41],
            [
                'by_month' => ['2026-01' => 10, '2026-02' => 31],
                'by_status' => ['Open' => 30, 'Awarded' => 11],
            ],
            [],
            ['break down by month'],
            [],
            'high',
            'For your own records, 2026-01-01 to 2026-12-31, I found 41 quotation(s) issued.',
            '/crm/quotes',
        );

        $answer = $formatter->format([$result], $intent, ['user-trace:user_trace.quote_issued:test'], 'how many quotations have i issued');
        $content = (string) $answer['answer_markdown'];

        $this->assertStringContainsString('1 Jan 2026 to 31 Dec 2026', $content);
        $this->assertStringContainsString('Total quoted value: 3,574,070.00', $content);
        $this->assertStringNotContainsString('2026-01-01', $content);
        $this->assertStringNotContainsString('all_matching_count_before_status_filter', $content);
        $this->assertStringNotContainsString('{"by_month"', $content);
        $this->assertContains('metric_cards', array_column($answer['display_blocks'], 'type'));
        $this->assertContains('table', array_column($answer['display_blocks'], 'type'));
        $this->assertContains('bar_chart', array_column($answer['display_blocks'], 'type'));
        $chart = collect($answer['display_blocks'])->firstWhere('type', 'bar_chart');
        $this->assertSame(['10', '31'], $chart['display_values']);
    }

    public function test_formats_employment_tenure_without_calendar_range(): void
    {
        $formatter = app(AssistantTraceAnswerFormatter::class);
        $intent = new AssistantUserTraceIntent(
            'self',
            'employment',
            'tenure',
            [
                'label' => 'all time',
                'start' => null,
                'end' => null,
                'is_all_time' => true,
            ],
            supported: true,
            catalogEntry: ['analyzer' => 'employment'],
        );
        $result = new AssistantUserTraceResult(
            'user_trace.employment_tenure',
            'My employment trace',
            'Current user employment profile and tenure.',
            $intent->dateRange,
            [
                'join_date' => '2016-09-05',
                'join_date_source' => 'join_date',
                'tenure_years' => 9.79,
                'tenure_label' => '9 year(s), 9 month(s)',
            ],
            [],
            [],
            [],
            [],
            'medium',
            'Based on your staff profile join_date, your tenure is 9 year(s), 9 month(s) as of 2026-06-18.',
            '/my/profile',
        );

        $answer = $formatter->format([$result], $intent, ['user-trace:user_trace.employment_tenure:test'], 'how long have i worked here');
        $content = (string) $answer['answer_markdown'];

        $this->assertStringContainsString('You have worked here for 9 years, 9 months', $content);
        $this->assertStringContainsString('join date of 5 Sep 2016', $content);
        $this->assertStringNotContainsString('Date range:', $content);
        $this->assertStringNotContainsString('2016-09-05', $content);
    }
}
