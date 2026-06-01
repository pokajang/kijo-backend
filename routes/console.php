<?php

use Illuminate\Support\Facades\Schedule;

// 1st of every month at 08:00 — remind all active staff to fill KPI tracker
Schedule::command('app:send-kpi-reminder')->monthlyOn(1, '08:00');

// Configurable dashboard report email schedule. The command checks DB settings before sending.
Schedule::command('dashboard:monthly-report --scheduled')->everyFiveMinutes()->withoutOverlapping();

// Every Monday at 09:00 — detect and alert on accounts still using the default password
Schedule::command('app:check-default-passwords')->weeklyOn(1, '09:00');

// Daily at 08:30 - remind internal invoice PICs to manually follow up unpaid invoices
Schedule::command('app:send-invoice-payment-follow-up-reminders')->dailyAt('08:30')->withoutOverlapping();

// Daily at 08:45 - remind selected staff about client vendor registration expiry
Schedule::command('app:send-client-vendor-registration-reminders')->dailyAt('08:45')->withoutOverlapping();

// Daily at 09:00 - digest pending Salary/Other Claim workflow actions per recipient
Schedule::command('salary:send-workflow-digest')->dailyAt('09:00')->withoutOverlapping();

// Daily at 03:20 - remove expired Learn Kijo assistant chat threads
Schedule::command('knowledge:prune-assistant-chats')->dailyAt('03:20')->withoutOverlapping();

// Daily at 23:55 - persist workload score history for dashboard graphs
Schedule::command('workload:capture-daily')->dailyAt('23:55')->withoutOverlapping();

// Daily at 00:30 - alert System Admins if the prior day's workload snapshot is missing
Schedule::command('workload:check-daily-capture')->dailyAt('00:30')->withoutOverlapping();

// Weekly at 03:40 - remove large retained evidence payloads from older workload snapshots
Schedule::command('workload:prune-snapshot-payloads')->weeklyOn(1, '03:40')->withoutOverlapping();

// Daily at 03:10 - delete resolved/consumed in-app notifications past the retention window
Schedule::command('notifications:prune')->dailyAt('03:10')->withoutOverlapping();

// Daily at 03:05 - heal stored leave notifications to match actionable workflow stages
Schedule::command('notifications:reconcile-leaves')->dailyAt('03:05')->withoutOverlapping();
