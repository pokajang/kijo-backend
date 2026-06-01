<?php

namespace Tests\Unit;

use App\Services\Salary\SalaryWorkflowNotificationService;
use Tests\TestCase;

class SalaryWorkflowNotificationEmailTest extends TestCase
{
    public function test_other_claim_email_cta_uses_frontend_origin_not_api_origin(): void
    {
        config([
            'app.frontend_url' => 'https://kijo.amiosh.com',
            'app.url' => 'https://api.amiosh.com',
        ]);

        $service = app(SalaryWorkflowNotificationService::class);
        $method = new \ReflectionMethod($service, 'bodyShell');
        $method->setAccessible(true);

        $html = $method->invoke($service, 'A new other claim has been submitted in KIJO.', [
            'title' => 'Other Claim',
            'period' => 'June 2026',
            'amounts' => ['Claims Total' => 12.34],
        ], (object) [], [
            'Applicant' => 'Azam Bin Husain (AZA)',
            'Status' => 'Submitted',
        ], '/financial/other-claim-records');

        $this->assertStringContainsString('href="https://kijo.amiosh.com/financial/other-claim-records"', $html);
        $this->assertStringNotContainsString('https://api.amiosh.com/financial/other-claim-records', $html);
        $this->assertStringNotContainsString('http://localhost:8000/financial/other-claim-records', $html);
    }
}
