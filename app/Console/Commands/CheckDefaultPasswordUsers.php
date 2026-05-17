<?php

namespace App\Console\Commands;

use App\Mail\DefaultPasswordNoticeMail;
use App\Mail\DefaultPasswordReportMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CheckDefaultPasswordUsers extends Command
{
    protected $signature   = 'app:check-default-passwords';
    protected $description = 'Alert users and admins about accounts still using the default password';

    public function handle(): int
    {
        $defaultPassword = (string) env('KIJO_DEFAULT_PASSWORD', '');
        if ($defaultPassword === '') {
            $this->error('KIJO_DEFAULT_PASSWORD is not set in .env');
            return self::FAILURE;
        }

        $rows = DB::table('system_users as su')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'su.staff_id')
            ->where('su.is_active', 1)
            ->whereNotNull('su.email')
            ->where('su.email', '<>', '')
            ->select('su.staff_id', 'su.email', 'su.password_hash', 'su.role', 'sg.full_name')
            ->get();

        $defaultUsers = $rows->filter(
            fn($u) => (string) ($u->password_hash ?? '') !== '' && password_verify($defaultPassword, $u->password_hash)
        );

        if ($defaultUsers->isEmpty()) {
            $this->info('Default-password check: no users found with default password.');
            return self::SUCCESS;
        }

        $adminRecipients = $this->resolveAdminRecipients();
        if (empty($adminRecipients)) {
            $this->error('No valid admin recipients found.');
            return self::FAILURE;
        }

        $noticeSent   = 0;
        $noticeFailed = 0;
        $reportRows   = [];

        foreach ($defaultUsers as $user) {
            $email        = strtolower(trim((string) ($user->email ?? '')));
            $name         = trim((string) ($user->full_name ?? '')) ?: 'Colleague';
            $noticeStatus = 'failed';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $noticeFailed++;
                $noticeStatus = 'invalid email';
            } else {
                try {
                    Mail::to($email, $name)->send(new DefaultPasswordNoticeMail($name));
                    $noticeSent++;
                    $noticeStatus = 'sent';
                } catch (\Throwable $e) {
                    $noticeFailed++;
                    $noticeStatus = 'send failed';
                    report($e);
                    $this->warn("Notice failed for {$email}: {$e->getMessage()}");
                }
            }

            $reportRows[] = [
                'full_name'     => (string) ($user->full_name ?? 'Unknown'),
                'email'         => (string) ($user->email ?? ''),
                'staff_id'      => (string) ($user->staff_id ?? ''),
                'notice_status' => $noticeStatus,
            ];
        }

        $primary = array_shift($adminRecipients);
        $cc      = $adminRecipients;

        try {
            Mail::to($primary, 'System Admin')
                ->cc($cc)
                ->send(new DefaultPasswordReportMail($defaultUsers->count(), $noticeSent, $noticeFailed, $reportRows));
        } catch (\Throwable $e) {
            report($e);
            $this->error('Report failed to send: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info("Default-password check done. Users={$defaultUsers->count()}, NoticeSent={$noticeSent}, NoticeFailed={$noticeFailed}");
        return self::SUCCESS;
    }

    private function resolveAdminRecipients(): array
    {
        $users = DB::table('system_users')
            ->where('is_active', 1)
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->select('email', 'role')
            ->get();

        $admins = [];
        foreach ($users as $u) {
            $email = strtolower(trim((string) ($u->email ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $raw   = $u->role ?? '';
            $roles = is_string($raw) ? (json_decode($raw, true) ?? [$raw]) : (array) $raw;
            foreach ($roles as $role) {
                if ($this->isAdminRole((string) $role)) {
                    $admins[$email] = true;
                    break;
                }
            }
        }

        if (!empty($admins)) {
            return array_keys($admins);
        }

        $fallback = (string) env('KIJO_FALLBACK_ADMIN_EMAIL', 'azam@amiosh.com');
        return filter_var($fallback, FILTER_VALIDATE_EMAIL) ? [$fallback] : [];
    }

    private function isAdminRole(string $role): bool
    {
        $lower = strtolower(trim($role));
        return $lower !== '' && (
            str_contains($lower, 'system admin') ||
            str_contains($lower, 'admin') ||
            str_contains($lower, 'super')
        );
    }
}
