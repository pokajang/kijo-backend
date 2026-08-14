<?php

namespace App\Support;

final class CorsOriginConfiguration
{
    private const LOOPBACK_PATTERN = '#^https?://(?:localhost|127\.0\.0\.1|\[::1\])(?::\d{1,5})?$#';

    /** @return list<string> */
    public static function allowedPatterns(string $environment, bool $allowLocalOrigins): array
    {
        return $environment === 'local' || $allowLocalOrigins
            ? [self::LOOPBACK_PATTERN]
            : [];
    }
}
