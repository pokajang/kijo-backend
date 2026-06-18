<?php

namespace Tests\Unit;

use App\Services\Assistant\CompanyAnalytics\AssistantCompanyAnalyticsIntentRouter;
use Tests\TestCase;

class AssistantCompanyAnalyticsIntentRouterTest extends TestCase
{
    public function test_routes_company_commercial_questions(): void
    {
        $router = app(AssistantCompanyAnalyticsIntentRouter::class);

        $sales = $router->resolve('who has the most sales this year');
        $this->assertTrue($sales->supported);
        $this->assertFalse($sales->denied);
        $this->assertSame('sales', $sales->domain);
        $this->assertSame('top', $sales->metric);

        $clients = $router->resolve('which client contributes most sales');
        $this->assertTrue($clients->supported);
        $this->assertSame('clients', $clients->domain);
    }

    public function test_does_not_steal_self_trace_detail_or_how_to_questions(): void
    {
        $router = app(AssistantCompanyAnalyticsIntentRouter::class);

        $this->assertFalse($router->resolve('what is my sales this year')->supported);
        $this->assertFalse($router->resolve('status of invoice INV-1001')->supported);
        $this->assertFalse($router->resolve('how do I create quotation')->supported);
    }

    public function test_denies_restricted_people_analytics(): void
    {
        $router = app(AssistantCompanyAnalyticsIntentRouter::class);

        foreach (['compare my KPI to Person A', 'who took most leave', 'highest salary'] as $question) {
            $intent = $router->resolve($question);
            $this->assertTrue($intent->supported, $question);
            $this->assertTrue($intent->denied, $question);
            $this->assertSame('restricted_people_analytics', $intent->denialReason);
        }
    }
}
