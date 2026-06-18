<?php

namespace Tests\Unit;

use App\Services\Assistant\CompanyAnalytics\AssistantCompanyAnalyticsDateRangeResolver;
use Tests\TestCase;

class AssistantCompanyAnalyticsDateRangeResolverTest extends TestCase
{
    public function test_default_range_is_current_year_to_today(): void
    {
        $range = app(AssistantCompanyAnalyticsDateRangeResolver::class)->resolve('commercial summary');

        $this->assertSame(now()->startOfYear()->toDateString(), $range['start']);
        $this->assertSame(now()->toDateString(), $range['end']);
        $this->assertFalse($range['is_all_time']);
    }

    public function test_supports_relative_and_explicit_ranges(): void
    {
        $resolver = app(AssistantCompanyAnalyticsDateRangeResolver::class);

        $this->assertSame(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), $resolver->resolve('sales last month')['start']);
        $this->assertSame(now()->subMonthsNoOverflow(11)->startOfMonth()->toDateString(), $resolver->resolve('sales last 12 months')['start']);
        $this->assertSame('2026-02-01', $resolver->resolve('sales from 2026-02-01 to 2026-02-28')['start']);
        $this->assertTrue($resolver->resolve('sales all time')['is_all_time']);
    }
}
