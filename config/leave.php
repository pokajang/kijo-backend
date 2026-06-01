<?php

return [
    'application_mail_to' => env('LEAVE_APPLICATION_MAIL_TO', ''),

    // Phase D3 authority flag for the staff.leaves badge count.
    //   'recompute' (default) — live workflow query is authoritative (legacy).
    //   'stored'              — stored notification rows are authoritative.
    // Flip to 'stored' only after D1 parity logs and the D2 backfill
    // (notifications:reconcile-leaves) confirm the stored table is trustworthy.
    'notification_badge_source' => env('LEAVE_NOTIFICATION_BADGE_SOURCE', 'recompute'),
];
