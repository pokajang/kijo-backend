<?php

$allowedOrigins = array_filter(array_map(
    'trim',
    explode(',', env('CORS_ALLOWED_ORIGINS', 'https://kijo.amiosh.com'))
));

return [
    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
