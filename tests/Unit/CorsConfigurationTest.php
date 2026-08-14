<?php

namespace Tests\Unit;

use App\Support\CorsOriginConfiguration;
use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_local_origin_pattern_accepts_loopback_hosts_on_any_port_only(): void
    {
        $patterns = CorsOriginConfiguration::allowedPatterns('local', false);
        $pattern = $patterns[0] ?? null;

        $this->assertIsString($pattern);
        $this->assertMatchesRegularExpression($pattern, 'http://127.0.0.1:3001');
        $this->assertMatchesRegularExpression($pattern, 'http://127.0.0.1:8002');
        $this->assertMatchesRegularExpression($pattern, 'https://localhost:8443');
        $this->assertMatchesRegularExpression($pattern, 'http://[::1]:5173');
        $this->assertDoesNotMatchRegularExpression($pattern, 'https://localhost.example.com:8001');
        $this->assertDoesNotMatchRegularExpression($pattern, 'https://kijo.amiosh.com');
    }

    public function test_production_does_not_enable_loopback_origins_by_default(): void
    {
        $this->assertSame([], CorsOriginConfiguration::allowedPatterns('production', false));
        $this->assertNotSame([], CorsOriginConfiguration::allowedPatterns('production', true));
    }
}
