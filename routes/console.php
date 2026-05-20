<?php

use Illuminate\Support\Facades\Schedule;

// 1st of every month at 08:00 — remind all active staff to fill KPI tracker
Schedule::command('app:send-kpi-reminder')->monthlyOn(1, '08:00');

// Every Monday at 09:00 — detect and alert on accounts still using the default password
Schedule::command('app:check-default-passwords')->weeklyOn(1, '09:00');

// Daily at 08:30 - remind internal invoice PICs to manually follow up unpaid invoices
Schedule::command('app:send-invoice-payment-follow-up-reminders')->dailyAt('08:30')->withoutOverlapping();

// Daily at 08:45 - remind selected staff about client vendor registration expiry
Schedule::command('app:send-client-vendor-registration-reminders')->dailyAt('08:45')->withoutOverlapping();
