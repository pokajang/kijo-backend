<?php

return [
    'workflow_template' => 'quote-approval',
    'rule_version' => 'traffic-light-220626-v1',
    'rule_versions' => [
        'training' => 'traffic-light-training-202608-v2',
        'equipment' => 'traffic-light-equipment-202608-v2',
        'manpower' => 'traffic-light-manpower-202608-v2',
    ],
    'legacy_cost_policy' => [
        // The traffic-light columns were introduced by the 2026-07-16 migration.
        'training' => ['cutoff' => '2026-07-16 01:00:00'],
        'equipment' => ['cutoff' => '2026-07-16 01:00:00'],
        'manpower' => ['cutoff' => '2026-07-16 01:00:00'],
    ],
    'default_approvers' => [
        'hod' => env('QUOTE_APPROVAL_HOD_EMAIL', 'azlin@amiosh.com'),
        'bd' => env('QUOTE_APPROVAL_BD_EMAIL', 'kamarul@amiosh.com'),
    ],
    // Percentage is markup on estimated cost, matching the quotation UI.
    'thresholds' => [
        'training' => ['green' => 40.0, 'red' => 25.0],
        'ih' => ['green' => 35.0, 'red' => 20.0],
        'manpower' => ['green' => 35.0, 'red' => 20.0],
        'equipment' => ['green' => 30.0, 'red' => 10.0],
    ],
];
