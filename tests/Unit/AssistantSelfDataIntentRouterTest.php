<?php

namespace Tests\Unit;

use App\Services\Assistant\UserTrace\AssistantSelfDataIntentRouter;
use Carbon\Carbon;
use Tests\TestCase;

class AssistantSelfDataIntentRouterTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_routes_direct_self_data_questions_by_domain_and_metric(): void
    {
        Carbon::setTestNow('2026-06-18 10:00:00');
        $router = app(AssistantSelfDataIntentRouter::class);

        $employment = $router->resolve('how long have i worked here');
        $this->assertTrue($employment->supported);
        $this->assertSame('self', $employment->subject);
        $this->assertSame('employment', $employment->domain);
        $this->assertSame('tenure', $employment->metric);
        $this->assertTrue($employment->dateRange['is_all_time']);

        $leave = $router->resolve('whats my leave status');
        $this->assertSame('leave', $leave->domain);
        $this->assertSame('status', $leave->metric);
        $this->assertSame('2026-01-01', $leave->dateRange['start']);

        $quote = $router->resolve('berapa quotation saya tahun ini');
        $this->assertSame('quote', $quote->domain);
        $this->assertSame('count', $quote->metric);
    }

    public function test_denies_other_staff_and_team_scopes_without_leaking_domain_content(): void
    {
        $router = app(AssistantSelfDataIntentRouter::class);

        $otherStaff = $router->resolve("show Ali's KPI");
        $this->assertTrue($otherStaff->supported);
        $this->assertTrue($otherStaff->denied);
        $this->assertSame('other_staff', $otherStaff->subject);

        $namedStaff = $router->resolve("show Ahmad's KPI");
        $this->assertTrue($namedStaff->supported);
        $this->assertTrue($namedStaff->denied);
        $this->assertSame('other_staff', $namedStaff->subject);

        $team = $router->resolve('how many quotations has my team issued');
        $this->assertTrue($team->denied);
        $this->assertSame('team', $team->subject);

        $ownProfile = $router->resolve('what is my department');
        $this->assertFalse($ownProfile->denied);
        $this->assertSame('self', $ownProfile->subject);
        $this->assertSame('employment', $ownProfile->domain);
    }

    public function test_routes_broad_self_summary_to_safe_aggregate_domains(): void
    {
        $intent = app(AssistantSelfDataIntentRouter::class)->resolve('how am i doing this year');

        $this->assertTrue($intent->supported);
        $this->assertTrue($intent->aggregate);
        $this->assertSame(['kpi', 'task', 'quote', 'leave'], $intent->aggregateDomains);

        $improvement = app(AssistantSelfDataIntentRouter::class)->resolve('how can i improve?');
        $this->assertTrue($improvement->supported);
        $this->assertTrue($improvement->aggregate);
        $this->assertSame(['kpi', 'task', 'quote'], $improvement->aggregateDomains);
    }

    public function test_does_not_steal_non_self_module_detail_questions(): void
    {
        $router = app(AssistantSelfDataIntentRouter::class);

        $projectDetail = $router->resolve('what is the project status?', '/project/manage/1');
        $this->assertFalse($projectDetail->supported);

        $selfProject = $router->resolve('what is my project status?', '/project/manage/1');
        $this->assertTrue($selfProject->supported);
        $this->assertSame('project', $selfProject->domain);

        $quoteHelp = $router->resolve('how can i improve this quote?', '/crm/quotes?quoteId=1');
        $this->assertFalse($quoteHelp->supported);
        $this->assertFalse($quoteHelp->aggregate);
        $this->assertSame('quote', $quoteHelp->domain);

        $createQuoteHelp = $router->resolve('how do I create quotation?', '/crm/quotes');
        $this->assertFalse($createQuoteHelp->supported);
    }

    public function test_salary_trace_is_caught_as_unsupported_self_data(): void
    {
        $intent = app(AssistantSelfDataIntentRouter::class)->resolve('what is my salary this month', '/my/salary');

        $this->assertTrue($intent->supported);
        $this->assertFalse($intent->denied);
        $this->assertSame('salary', $intent->domain);
        $this->assertNull($intent->catalogEntry['analyzer']);
    }
}
