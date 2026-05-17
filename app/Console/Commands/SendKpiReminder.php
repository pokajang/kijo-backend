<?php

namespace App\Console\Commands;

use App\Mail\KpiReminderMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendKpiReminder extends Command
{
    protected $signature   = 'app:send-kpi-reminder';
    protected $description = 'Send monthly KPI tracker reminder emails to all active staff';

    public function handle(): int
    {
        $users = DB::table('staff_general')
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->whereRaw("LOWER(TRIM(status)) = 'active'")
            ->select('email', 'full_name')
            ->get();

        if ($users->isEmpty()) {
            $this->info('KPI reminder: no active users found.');
            return self::SUCCESS;
        }

        $sent   = 0;
        $failed = 0;

        foreach ($users as $user) {
            $email = trim((string) ($user->email ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed++;
                $this->warn("Skipped invalid email: {$email}");
                continue;
            }

            $name = trim((string) ($user->full_name ?? '')) ?: 'Colleague';

            try {
                Mail::to($email, $name)->send(new KpiReminderMail($name));
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                $this->warn("Failed for {$email}: {$e->getMessage()}");
            }
        }

        $this->info("KPI reminder finished. Sent={$sent}, Failed={$failed}");
        return self::SUCCESS;
    }
}
