<?php

return [
    'evidence_key' => env('HANDBOOK_EVIDENCE_KEY') ?: env('APP_KEY'),
    'evidence_key_id' => env('HANDBOOK_EVIDENCE_KEY_ID', 'app-key-v1'),
    'evidence_previous_keys' => env('HANDBOOK_EVIDENCE_PREVIOUS_KEYS', '{}'),
];
