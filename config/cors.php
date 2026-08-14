<?php

use App\Support\CorsOriginConfiguration;

$allowedOrigins = array_filter(array_map(
    'trim',
    explode(',', env('CORS_ALLOWED_ORIGINS', 'https://kijo.amiosh.com'))
));
$allowLocalOrigins = env('APP_ENV', 'production') === 'local'
    || filter_var(env('CORS_ALLOW_LOCAL_ORIGINS', false), FILTER_VALIDATE_BOOL);
$allowedOriginPatterns = CorsOriginConfiguration::allowedPatterns(
    env('APP_ENV', 'production'),
    $allowLocalOrigins
);

return [
    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => $allowedOriginPatterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,
];
