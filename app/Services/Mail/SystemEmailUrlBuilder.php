<?php

namespace App\Services\Mail;

class SystemEmailUrlBuilder
{
    public function frontendUrl(string $route): string
    {
        $base = trim((string) config('app.frontend_url', ''));
        $base = rtrim($base !== '' ? $base : 'https://kijo.amiosh.com', '/');

        return $base.'/'.ltrim($route, '/');
    }
}
