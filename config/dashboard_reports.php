<?php

return [
    'monthly_recipients' => env('DASHBOARD_MONTHLY_REPORT_RECIPIENTS', ''),
    'public_link_ttl_days' => (int) env('DASHBOARD_MONTHLY_REPORT_LINK_TTL_DAYS', 90),
];
