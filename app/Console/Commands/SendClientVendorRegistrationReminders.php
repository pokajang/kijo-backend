<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;

class SendClientVendorRegistrationReminders extends Command
{
    protected $signature = 'app:send-client-vendor-registration-reminders
        {--dry-run : Report eligible reminders without sending email or writing logs}
        {--limit= : Maximum number of registrations to process}';

    protected $description = 'Send internal reminders for client vendor registration expiry';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->resolveLimit();

        if (!$dryRun && !$this->mailSenderIsConfigured()) {
            $this->error('System email sender is not configured. Check MAIL_MAILER and MAIL_FROM_ADDRESS.');
            return self::FAILURE;
        }

        $lockAcquired = false;
        if (!$dryRun) {
            $lockAcquired = $this->acquireCommandLock();
            if (!$lockAcquired) {
                $this->info('Client vendor registration reminders are already running; skipped this run.');
                return self::SUCCESS;
            }
        }

        try {
            return $this->process($dryRun, $limit);
        } finally {
            if ($lockAcquired) {
                $this->releaseCommandLock();
            }
        }
    }

    private function process(bool $dryRun, ?int $limit): int
    {
        $today = CarbonImmutable::today();
        $rows = $this->candidateRows($today, $limit);
        if ($rows->isEmpty()) {
            $this->info('Client vendor registration reminders: no eligible registrations found.');
            return self::SUCCESS;
        }

        $deliveries = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $candidate = $this->formatCandidate($row, $today);
            $stage = $this->stageForDaysLeft($candidate['days_left']);
            if ($stage === null || $this->alreadySent($candidate['registration_id'], $candidate['staff_id'], $stage['key'])) {
                $skipped++;
                continue;
            }

            if (!$this->isValidEmail($candidate['to_email'])) {
                $skipped++;
                $this->warn("Skipped {$candidate['client_name']}: recipient email is missing or invalid.");
                continue;
            }

            $recipientKey = strtolower($candidate['to_email']);
            if (!isset($deliveries[$recipientKey])) {
                $deliveries[$recipientKey] = [
                    'email' => $candidate['to_email'],
                    'name' => $candidate['to_name'],
                    'items' => [],
                ];
            }

            $deliveries[$recipientKey]['items'][] = [
                'candidate' => $candidate,
                'stage' => $stage,
                'body_snapshot' => $this->bodySnapshot($candidate, $stage),
            ];
        }

        if ($dryRun) {
            $count = 0;
            foreach ($deliveries as $delivery) {
                $count += count($delivery['items']);
                $this->line("[dry-run] {$delivery['email']} would receive ".count($delivery['items']).' vendor registration reminder(s).');
            }

            $this->info("Client vendor registration reminders finished. Mode=dry-run, Sent={$count}, Failed=0, Skipped={$skipped}");
            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        foreach ($deliveries as $delivery) {
            $subject = $this->digestSubject($delivery);
            try {
                $this->sendDigestMail($delivery, $subject);
            } catch (\Throwable $e) {
                report($e);
                foreach ($delivery['items'] as $item) {
                    $this->tryInsertLog($item['candidate'], $item['stage'], $subject, $item['body_snapshot'], 'failed', mb_substr($e->getMessage(), 0, 1000));
                    $failed++;
                }
                continue;
            }

            foreach ($delivery['items'] as $item) {
                if ($this->tryInsertLog($item['candidate'], $item['stage'], $subject, $item['body_snapshot'], 'sent')) {
                    $sent++;
                    continue;
                }

                $failed++;
            }
        }

        $this->info("Client vendor registration reminders finished. Mode=sent, Sent={$sent}, Failed={$failed}, Skipped={$skipped}");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function candidateRows(CarbonImmutable $today, ?int $limit)
    {
        $registrationIdQuery = DB::table('client_vendor_registrations as r')
            ->leftJoin('client_company as cc', 'cc.company_id', '=', 'r.client_id')
            ->whereNull('r.deleted_at')
            ->whereDate('r.valid_until', '<=', $today->addDays(60)->toDateString())
            ->select('r.id')
            ->orderBy('r.valid_until')
            ->orderBy('cc.company_name');

        if ($limit !== null) {
            $registrationIdQuery->limit($limit);
        }

        $registrationIds = $registrationIdQuery
            ->pluck('r.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (!$registrationIds) {
            return collect();
        }

        $query = DB::table('client_vendor_registrations as r')
            ->join('client_vendor_registration_recipients as rr', 'rr.registration_id', '=', 'r.id')
            ->join('staff_general as sg', 'sg.staff_id', '=', 'rr.staff_id')
            ->leftJoin('client_company as cc', 'cc.company_id', '=', 'r.client_id')
            ->whereIn('r.id', $registrationIds)
            ->whereNotNull('sg.email')
            ->where('sg.email', '<>', '')
            ->select([
                'r.id',
                'r.client_id',
                'r.valid_from',
                'r.valid_until',
                'r.certificate_original_name',
                'r.remarks',
                'cc.company_name',
                'sg.staff_id',
                'sg.full_name',
                'sg.name_code',
                'sg.email',
            ])
            ->orderBy('r.valid_until')
            ->orderBy('cc.company_name')
            ->orderBy('sg.full_name');

        if (Schema::hasColumn('staff_general', 'deleted_at')) {
            $query->whereNull('sg.deleted_at');
        }
        if (Schema::hasColumn('staff_general', 'status')) {
            $query->whereRaw("LOWER(TRIM(COALESCE(sg.status, 'active'))) = 'active'");
        }
        return $query->get();
    }

    private function formatCandidate(object $row, CarbonImmutable $today): array
    {
        $validUntil = CarbonImmutable::parse(substr((string) $row->valid_until, 0, 10));

        return [
            'registration_id' => (int) $row->id,
            'client_id' => (int) $row->client_id,
            'client_name' => trim((string) ($row->company_name ?? '')) ?: 'Client #' . (string) $row->client_id,
            'valid_from' => substr((string) $row->valid_from, 0, 10),
            'valid_until' => $validUntil->toDateString(),
            'days_left' => (int) $today->diffInDays($validUntil, false),
            'certificate_name' => (string) ($row->certificate_original_name ?? ''),
            'remarks' => (string) ($row->remarks ?? ''),
            'staff_id' => (int) $row->staff_id,
            'to_name' => trim((string) ($row->full_name ?? '')),
            'to_code' => trim((string) ($row->name_code ?? '')),
            'to_email' => trim((string) ($row->email ?? '')),
        ];
    }

    private function stageForDaysLeft(int $daysLeft): ?array
    {
        if ($daysLeft < 0) {
            return ['key' => 'expired', 'label' => 'Expired'];
        }
        if ($daysLeft <= 7) {
            return ['key' => '7_days', 'label' => 'Expires within 7 days'];
        }
        if ($daysLeft <= 30) {
            return ['key' => '30_days', 'label' => 'Expires within 30 days'];
        }
        if ($daysLeft <= 60) {
            return ['key' => '60_days', 'label' => 'Expires within 60 days'];
        }

        return null;
    }

    private function alreadySent(int $registrationId, int $staffId, string $stage): bool
    {
        return DB::table('client_vendor_registration_reminder_logs')
            ->where('registration_id', $registrationId)
            ->where('staff_id', $staffId)
            ->where('stage', $stage)
            ->where('status', 'sent')
            ->exists();
    }

    private function sendDigestMail(array $delivery, string $subject): void
    {
        $fromAddress = trim((string) config('mail.from.address', ''));
        $fromName = trim((string) config('mail.from.name', 'AMIOSH Admin'));
        $recipientName = trim((string) $delivery['name']);

        $htmlBody = view('emails.client-vendor-registration-reminder-digest', [
            'subject' => $subject,
            'recipientName' => $recipientName !== '' ? $recipientName : 'there',
            'rows' => array_map(fn ($item) => $this->digestRow($item), $delivery['items']),
        ])->render();

        Mail::send(['html' => new HtmlString($htmlBody)], [], function ($message) use ($delivery, $fromAddress, $fromName, $recipientName, $subject): void {
            $message
                ->from($fromAddress, $fromName)
                ->to($delivery['email'], $recipientName !== '' ? $recipientName : null)
                ->subject($subject);
        });
    }

    private function digestRow(array $item): array
    {
        return [
            ...$item['candidate'],
            'stage_label' => $item['stage']['label'],
        ];
    }

    private function digestSubject(array $delivery): string
    {
        $count = count($delivery['items']);
        return $count === 1
            ? 'Client vendor registration expiry reminder'
            : "Client vendor registration expiry reminders ({$count})";
    }

    private function bodySnapshot(array $candidate, array $stage): string
    {
        return "{$stage['label']}: {$candidate['client_name']} valid until {$candidate['valid_until']} ({$candidate['days_left']} days left).";
    }

    private function tryInsertLog(
        array $candidate,
        array $stage,
        string $subject,
        string $bodySnapshot,
        string $status,
        ?string $error = null
    ): bool {
        try {
            DB::table('client_vendor_registration_reminder_logs')->insert([
                'registration_id' => $candidate['registration_id'],
                'staff_id' => $candidate['staff_id'],
                'stage' => $stage['key'],
                'to_email' => $candidate['to_email'],
                'to_name' => $candidate['to_name'] !== '' ? $candidate['to_name'] : null,
                'subject' => $subject,
                'body_snapshot' => $bodySnapshot,
                'status' => $status,
                'error' => $error,
                'sent_at' => $status === 'sent' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    private function resolveLimit(): ?int
    {
        $raw = $this->option('limit');
        if ($raw === null || $raw === '') {
            return null;
        }

        $limit = (int) $raw;
        return $limit > 0 ? $limit : null;
    }

    private function mailSenderIsConfigured(): bool
    {
        $mailer = (string) config('mail.default', '');
        $fromAddress = trim((string) config('mail.from.address', ''));

        return $mailer !== ''
            && !in_array($mailer, ['array', 'log'], true)
            && $fromAddress !== ''
            && !str_contains(strtolower($fromAddress), 'example.com');
    }

    private function isValidEmail(string $email): bool
    {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function acquireCommandLock(): bool
    {
        if (!$this->supportsNamedLocks()) {
            return true;
        }

        try {
            $result = DB::selectOne("SELECT GET_LOCK('client_vendor_registration_reminders', 0) as acquired");
            return (int) ($result->acquired ?? 0) === 1;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    private function releaseCommandLock(): void
    {
        if (!$this->supportsNamedLocks()) {
            return;
        }

        try {
            DB::selectOne("SELECT RELEASE_LOCK('client_vendor_registration_reminders')");
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function supportsNamedLocks(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
