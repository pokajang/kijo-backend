<?php

namespace Tests\Unit;

use App\Services\Mail\SystemEmailBodyBuilder;
use Tests\TestCase;

class SystemEmailBodyBuilderTest extends TestCase
{
    public function test_builder_escapes_user_controlled_detail_values(): void
    {
        $html = app(SystemEmailBodyBuilder::class)->render([
            'intro' => 'A request was submitted.',
            'details' => [
                'Feedback' => '<script>alert("x")</script>',
            ],
            'signOff' => false,
        ]);

        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert("x")</script>', $html);
    }

    public function test_builder_renders_detail_panel_notice_cta_and_signoff(): void
    {
        $html = app(SystemEmailBodyBuilder::class)->render([
            'intro' => 'Please review this workflow item.',
            'status' => ['label' => 'Pending Approval', 'tone' => 'warning'],
            'detailsHeading' => 'Workflow Details',
            'details' => [
                'Reference' => 'REQ-001',
                'Amount' => 'RM 100.00',
            ],
            'notice' => [
                'label' => 'Note',
                'body' => 'Review before approving.',
                'tone' => 'warning',
            ],
            'actionUrl' => 'https://kijo.amiosh.com/item/1',
            'actionLabel' => 'Open in KIJO',
        ]);

        $this->assertStringContainsString('Workflow Details', $html);
        $this->assertStringContainsString('Pending Approval', $html);
        $this->assertStringContainsString('REQ-001', $html);
        $this->assertStringContainsString('Review before approving.', $html);
        $this->assertStringContainsString('Open in KIJO', $html);
        $this->assertStringContainsString('href="https://kijo.amiosh.com/item/1"', $html);
        $this->assertStringContainsString('Best regards', $html);
    }

    public function test_builder_allows_no_cta_emails(): void
    {
        $html = app(SystemEmailBodyBuilder::class)->render([
            'intro' => 'A system ticket was submitted.',
            'details' => ['Ticket' => 'Feedback text'],
            'signOff' => false,
        ]);

        $this->assertStringContainsString('A system ticket was submitted.', $html);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringNotContainsString('Best regards', $html);
    }

    public function test_generic_wrapper_uses_presentation_metadata_around_workflow_body(): void
    {
        $builder = app(SystemEmailBodyBuilder::class);
        $body = $builder->render([
            'intro' => 'A leave application needs attention.',
            'details' => ['Applicant' => 'Test User'],
            'signOff' => false,
        ]);

        $html = view('emails.generic-html', [
            'subject' => 'Test Subject',
            'preheader' => 'Test preheader',
            'headerLabel' => 'Leave',
            'headerTitle' => 'Leave Application Pending Approval',
            'headerSubtitle' => 'Approval required',
            'footer' => 'Automated KIJO notification.',
            'body' => $body,
        ])->render();

        $this->assertStringContainsString('Leave Application Pending Approval', $html);
        $this->assertStringContainsString('Approval required', $html);
        $this->assertStringContainsString('A leave application needs attention.', $html);
        $this->assertStringContainsString('Automated KIJO notification.', $html);
    }
}
