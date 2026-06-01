<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'translation' => [
        'provider' => env('TRANSLATION_PROVIDER', 'google'),
        'source' => env('TRANSLATION_SOURCE', 'en'),
        'target' => env('TRANSLATION_TARGET', 'ms'),
    ],

    'google_translate' => [
        'key' => env('GOOGLE_TRANSLATE_API_KEY'),
    ],

    'google_places' => [
        'key' => env('GOOGLE_PLACES_API_KEY'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'responses_endpoint' => env('OPENAI_RESPONSES_ENDPOINT', 'https://api.openai.com/v1/responses'),
        'timeout_ms' => (int) env('OPENAI_TIMEOUT_MS', 30000),
    ],

    'workload_ai_classification' => [
        'enabled' => (bool) env('WORKLOAD_AI_CLASSIFICATION_ENABLED', false),
        'model' => env('WORKLOAD_AI_CLASSIFICATION_MODEL', 'gpt-5-nano'),
        'timeout_ms' => (int) env('WORKLOAD_AI_CLASSIFICATION_TIMEOUT_MS', 30000),
    ],

    'knowledge_assistant' => [
        'model' => env('KNOWLEDGE_ASSISTANT_MODEL', env('OPENAI_MODEL', 'gpt-5-nano')),
        'timeout_ms' => (int) env('KNOWLEDGE_ASSISTANT_TIMEOUT_MS', env('OPENAI_TIMEOUT_MS', 30000)),
        'live_cache_ttl_minutes' => (int) env('KNOWLEDGE_ASSISTANT_LIVE_CACHE_TTL_MINUTES', 5),
    ],

];
