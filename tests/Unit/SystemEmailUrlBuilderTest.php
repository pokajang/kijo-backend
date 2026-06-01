<?php

namespace Tests\Unit;

use App\Mail\DefaultPasswordNoticeMail;
use App\Services\Mail\SystemEmailUrlBuilder;
use Tests\TestCase;

class SystemEmailUrlBuilderTest extends TestCase
{
    public function test_frontend_url_uses_frontend_config_over_app_url(): void
    {
        config([
            'app.frontend_url' => 'https://kijo.amiosh.com',
            'app.url' => 'https://api.amiosh.com',
        ]);

        $this->assertSame(
            'https://kijo.amiosh.com/login',
            app(SystemEmailUrlBuilder::class)->frontendUrl('/login'),
        );
    }

    public function test_frontend_url_normalizes_slashes(): void
    {
        config([
            'app.frontend_url' => 'https://kijo.amiosh.com/',
            'app.url' => 'https://api.amiosh.com',
        ]);

        $this->assertSame(
            'https://kijo.amiosh.com/support/feedback/12',
            app(SystemEmailUrlBuilder::class)->frontendUrl('/support/feedback/12'),
        );
    }

    public function test_frontend_url_falls_back_to_kijo_origin_when_frontend_config_is_empty(): void
    {
        config([
            'app.frontend_url' => '',
            'app.url' => 'https://api.amiosh.com',
        ]);

        $this->assertSame(
            'https://kijo.amiosh.com/vendor/payment-records/9',
            app(SystemEmailUrlBuilder::class)->frontendUrl('vendor/payment-records/9'),
        );
    }

    public function test_default_password_notice_uses_configured_login_button_url(): void
    {
        config([
            'app.frontend_url' => 'https://kijo.amiosh.com',
            'app.url' => 'https://api.amiosh.com',
        ]);

        $html = view('emails.default-password-notice', [
            'recipientName' => 'Test User',
            'loginUrl' => app(SystemEmailUrlBuilder::class)->frontendUrl('/login'),
        ])->render();

        $this->assertStringContainsString('href="https://kijo.amiosh.com/login"', $html);
        $this->assertStringNotContainsString('work.amiosh.com', $html);
        $this->assertStringNotContainsString('https://api.amiosh.com', $html);

        $this->assertSame('https://kijo.amiosh.com/login', (new DefaultPasswordNoticeMail('Test User'))->loginUrl);
    }
}
