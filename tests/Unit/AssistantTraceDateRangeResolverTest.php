<?php

namespace Tests\Unit;

use App\Services\Assistant\UserTrace\AssistantTraceDateRangeResolver;
use Carbon\Carbon;
use Tests\TestCase;

class AssistantTraceDateRangeResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_resolves_default_and_relative_trace_date_ranges(): void
    {
        Carbon::setTestNow('2026-06-18 10:00:00');
        $resolver = app(AssistantTraceDateRangeResolver::class);

        $this->assertSame([
            'label' => 'current calendar year',
            'start' => '2026-01-01',
            'end' => '2026-12-31',
            'is_all_time' => false,
        ], $resolver->resolve('how many quotations have i issued'));

        $this->assertSame('2026-05-01', $resolver->resolve('my leave last month')['start']);
        $this->assertSame('2025-01-01', $resolver->resolve('my KPI last year')['start']);
        $this->assertSame('2025-07-01', $resolver->resolve('my task last 12 months')['start']);
        $this->assertTrue($resolver->resolve('my quotation all time')['is_all_time']);
        $this->assertSame('2024-01-01', $resolver->resolve('my quotation in 2024')['start']);
        $this->assertSame([
            'label' => 'explicit date range',
            'start' => '2026-02-01',
            'end' => '2026-04-30',
            'is_all_time' => false,
        ], $resolver->resolve('my quotations from 2026-04-30 to 2026-02-01'));
        $this->assertSame('2026-01-01', $resolver->resolve('my quotations from 1 Jan 2026 to 31 Mar 2026')['start']);
        $this->assertSame('2026-03-31', $resolver->resolve('my quotations from Jan 1 2026 to Mar 31 2026')['end']);
    }

    public function test_contains_checks_dates_against_trace_range(): void
    {
        Carbon::setTestNow('2026-06-18 10:00:00');
        $resolver = app(AssistantTraceDateRangeResolver::class);
        $range = $resolver->resolve('this year');

        $this->assertTrue($resolver->contains('2026-03-01', $range));
        $this->assertFalse($resolver->contains('2025-12-31', $range));
        $this->assertFalse($resolver->contains(null, $range));
        $this->assertTrue($resolver->contains(null, $resolver->resolve('all time')));
    }
}
