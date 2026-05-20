<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendInvoicePaymentFollowUpReminders extends Command
{
    protected $signature = 'app:send-invoice-payment-follow-up-reminders
        {--dry-run : Report eligible reminders without sending email or writing logs}
        {--limit= : Maximum number of reminders to process}';

    protected $description = 'Send internal-only reminders for staff to manually follow up unpaid invoices';

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
                $this->info('Invoice payment follow-up reminders are already running. Skipping this run.');
                return self::SUCCESS;
            }
        }

        try {
            return $this->processReminders($dryRun, $limit);
        } finally {
            if ($lockAcquired) {
                $this->releaseCommandLock();
            }
        }
    }

    private function processReminders(bool $dryRun, ?int $limit): int
    {
        $today = CarbonImmutable::today();
        $query = $this->candidateRows($today);
        if ($limit !== null) {
            $query->limit($limit);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->info('Invoice payment follow-up reminders: no eligible unpaid invoices found.');
            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $deliveries = [];

        foreach ($rows as $row) {
            $candidate = $this->formatCandidate($row, $today);
            $stage = $this->stageForOverdueDays($candidate['overdue_days']);
            if ($stage === null) {
                $skipped++;
                continue;
            }

            if ($this->alreadySent((int) $row->id, $stage['key'])) {
                $skipped++;
                continue;
            }

            if (!$this->isValidEmail($candidate['internal_pic_email'])) {
                $skipped++;
                $this->warn("Skipped {$candidate['invoice_ref_no']}: internal PIC email is missing or invalid.");
                continue;
            }

            [$subject, $paragraphs] = $this->message($candidate, $stage);
            $recipientKey = strtolower($candidate['internal_pic_email']);
            if (!isset($deliveries[$recipientKey])) {
                $deliveries[$recipientKey] = [
                    'email' => $candidate['internal_pic_email'],
                    'name' => $candidate['internal_pic_name'],
                    'items' => [],
                ];
            }

            $deliveries[$recipientKey]['items'][] = [
                'candidate' => $candidate,
                'stage' => $stage,
                'subject' => $subject,
                'body_snapshot' => implode("\n\n", $paragraphs),
            ];
        }

        if ($dryRun) {
            foreach ($deliveries as $delivery) {
                $sent += count($delivery['items']);
                $this->line("[dry-run] {$delivery['email']} would receive ".count($delivery['items']).' invoice follow-up reminder(s).');
            }

            $this->info("Invoice payment follow-up reminders finished. Mode=dry-run, Sent={$sent}, Failed=0, Skipped={$skipped}");
            return self::SUCCESS;
        }

        foreach ($deliveries as $delivery) {
            $subject = $this->digestSubject($delivery);
            try {
                $this->sendDigestMail($delivery, $subject);
            } catch (\Throwable $e) {
                report($e);

                foreach ($delivery['items'] as $item) {
                    $this->tryInsertLog(
                        $item['candidate'],
                        $item['stage'],
                        $subject,
                        $item['body_snapshot'],
                        'failed',
                        mb_substr($e->getMessage(), 0, 1000)
                    );
                    $failed++;
                }

                $this->warn("Failed digest for {$delivery['email']}: {$e->getMessage()}");
                continue;
            }

            foreach ($delivery['items'] as $item) {
                if ($this->tryInsertLog(
                    $item['candidate'],
                    $item['stage'],
                    $subject,
                    $item['body_snapshot'],
                    'sent'
                )) {
                    $sent++;
                    continue;
                }

                $failed++;
                $this->warn("Email sent but reminder log failed for {$item['candidate']['invoice_ref_no']}.");
            }
        }

        $this->info("Invoice payment follow-up reminders finished. Mode=sent, Sent={$sent}, Failed={$failed}, Skipped={$skipped}");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function candidateRows(CarbonImmutable $today)
    {
        return DB::table('invoices as i')
            ->leftJoin('quotes_training as q', 'i.quote_id', '=', 'q.id')
            ->leftJoin('client_company as cc', 'i.client_id', '=', 'cc.company_id')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'i.created_by')
            ->whereNotNull('i.invoice_date')
            ->whereRaw("COALESCE(i.due_date, DATE_ADD(i.invoice_date, INTERVAL COALESCE(i.payment_terms_days, cc.payment_terms_days, 30) DAY)) <= ?", [$today->toDateString()])
            ->whereRaw("LOWER(TRIM(COALESCE(i.status, 'pending'))) IN ('pending', 'unpaid', 'overdue')")
            ->whereNull('i.paid_date')
            ->orderByRaw('COALESCE(i.due_date, DATE_ADD(i.invoice_date, INTERVAL COALESCE(i.payment_terms_days, cc.payment_terms_days, 30) DAY))')
            ->orderBy('i.id')
            ->select([
                'i.id',
                'i.invoice_ref_no',
                'i.invoice_date',
                DB::raw('COALESCE(i.payment_terms_days, cc.payment_terms_days, 30) AS payment_terms_days'),
                DB::raw("COALESCE(NULLIF(i.payment_terms_source, ''), CASE WHEN cc.payment_terms_days IS NULL THEN 'system_default' ELSE 'client' END) AS payment_terms_source"),
                DB::raw('COALESCE(i.due_date, DATE_ADD(i.invoice_date, INTERVAL COALESCE(i.payment_terms_days, cc.payment_terms_days, 30) DAY)) AS due_date'),
                'i.status',
                'i.amount',
                'i.grand_total',
                DB::raw("COALESCE(i.invoice_client_name, q.client_name, cc.company_name, CONCAT('Client #', i.client_id)) AS client_name"),
                DB::raw("COALESCE(i.invoice_pic_name, q.pic_name) AS client_pic_name"),
                DB::raw("COALESCE(i.invoice_pic_email, q.pic_email) AS client_pic_email"),
                'sg.staff_id as internal_pic_id',
                'sg.full_name as internal_pic_name',
                'sg.name_code as internal_pic_code',
                'sg.email as internal_pic_email',
            ]);
    }

    private function formatCandidate(object $row, CarbonImmutable $today): array
    {
        $invoiceDate = CarbonImmutable::parse(substr((string) $row->invoice_date, 0, 10));
        $paymentTermsDays = max(0, min(365, (int) ($row->payment_terms_days ?? 30)));
        $dueDate = ! empty($row->due_date)
            ? CarbonImmutable::parse(substr((string) $row->due_date, 0, 10))
            : $invoiceDate->addDays($paymentTermsDays);
        $amount = is_numeric($row->grand_total ?? null) ? (float) $row->grand_total : (float) ($row->amount ?? 0);

        return [
            'invoice_id' => (int) $row->id,
            'invoice_ref_no' => (string) ($row->invoice_ref_no ?? "Invoice #{$row->id}"),
            'invoice_date' => $invoiceDate->toDateString(),
            'payment_terms_days' => $paymentTermsDays,
            'payment_terms_source' => (string) ($row->payment_terms_source ?? 'legacy'),
            'due_date' => $dueDate->toDateString(),
            'age_days' => (int) $invoiceDate->diffInDays($today),
            'overdue_days' => (int) $dueDate->diffInDays($today, false),
            'client_name' => trim((string) ($row->client_name ?? '')) ?: '-',
            'client_pic_name' => trim((string) ($row->client_pic_name ?? '')),
            'client_pic_email' => trim((string) ($row->client_pic_email ?? '')),
            'internal_pic_id' => isset($row->internal_pic_id) ? (int) $row->internal_pic_id : null,
            'internal_pic_name' => trim((string) ($row->internal_pic_name ?? '')),
            'internal_pic_code' => trim((string) ($row->internal_pic_code ?? '')),
            'internal_pic_email' => trim((string) ($row->internal_pic_email ?? '')),
            'amount_display' => number_format($amount, 2),
            'status_label' => trim((string) ($row->status ?? 'Pending')) ?: 'Pending',
        ];
    }

    private function stageForOverdueDays(int $overdueDays): ?array
    {
        if ($overdueDays >= 30) {
            return [
                'key' => '30_overdue_internal',
                'threshold_days' => 30,
                'heading' => 'Invoice Follow-up Reminder',
            ];
        }

        if ($overdueDays >= 0) {
            return [
                'key' => 'due_internal',
                'threshold_days' => 0,
                'heading' => 'Invoice Follow-up Suggested',
            ];
        }

        return null;
    }

    private function alreadySent(int $invoiceId, string $stage): bool
    {
        $stages = match ($stage) {
            'due_internal' => ['due_internal', '30_internal'],
            '30_overdue_internal' => ['30_overdue_internal', '60_internal'],
            default => [$stage],
        };

        return DB::table('invoice_payment_reminder_logs')
            ->where('invoice_id', $invoiceId)
            ->whereIn('stage', $stages)
            ->where('status', 'sent')
            ->exists();
    }

    private function message(array $candidate, array $stage): array
    {
        $clientPic = $candidate['client_pic_name'] !== '' || $candidate['client_pic_email'] !== ''
            ? trim($candidate['client_pic_name'].' '.$candidate['client_pic_email'])
            : 'No client PIC email is recorded';

        $opening = $stage['threshold_days'] >= 30
            ? "Invoice {$candidate['invoice_ref_no']} is now {$candidate['overdue_days']} days overdue."
            : "Invoice {$candidate['invoice_ref_no']} has reached its payment due date.";

        return [
            "Follow-up suggested for invoice {$candidate['invoice_ref_no']}",
            [
                "Hi {$this->displayName($candidate['internal_pic_name'], 'there')},",
                "{$opening} This is an internal reminder only; no email has been sent to the client.",
                "Invoice date: {$candidate['invoice_date']}. Payment terms: {$candidate['payment_terms_days']} days. Due date: {$candidate['due_date']}.",
                "Client: {$candidate['client_name']}. Client PIC on record: {$clientPic}.",
                'Please review the latest payment status and follow up manually with the client when appropriate.',
                'If payment has already been received, please update the invoice status so future reminders are skipped.',
            ],
        ];
    }

    private function digestSubject(array $delivery): string
    {
        $count = count($delivery['items']);
        return $count === 1
            ? 'Invoice follow-up reminder'
            : "Invoice follow-up reminders ({$count})";
    }

    private function sendDigestMail(array $delivery, string $subject): void
    {
        $fromAddress = trim((string) config('mail.from.address', ''));
        $fromName = trim((string) config('mail.from.name', 'AMIOSH Admin'));
        $recipientName = trim((string) $delivery['name']);
        $invoiceRows = array_map(fn ($item) => $this->digestInvoiceRow($item), $delivery['items']);

        $htmlBody = view('emails.invoice-payment-follow-up-digest', [
            'subject' => $subject,
            'recipientName' => $this->displayName($recipientName, 'there'),
            'invoiceRows' => $invoiceRows,
        ])->render();

        Mail::html($htmlBody, function ($message) use ($delivery, $fromAddress, $fromName, $recipientName, $subject) {
            $message
                ->from($fromAddress, $fromName)
                ->to($delivery['email'], $recipientName !== '' ? $recipientName : null)
                ->subject($subject);
        });
    }

    private function digestInvoiceRow(array $item): array
    {
        $candidate = $item['candidate'];
        $stage = $item['stage'];
        $clientPic = $candidate['client_pic_name'] !== '' || $candidate['client_pic_email'] !== ''
            ? trim($candidate['client_pic_name'].' '.$candidate['client_pic_email'])
            : 'Not recorded';

        return [
            'stage' => $stage['threshold_days'] > 0 ? "{$stage['threshold_days']}+ days overdue" : 'Due',
            'invoice_ref_no' => $candidate['invoice_ref_no'],
            'invoice_date' => $candidate['invoice_date'],
            'payment_terms_days' => $candidate['payment_terms_days'],
            'due_date' => $candidate['due_date'],
            'age_days' => $candidate['age_days'],
            'overdue_days' => $candidate['overdue_days'],
            'client_name' => $candidate['client_name'],
            'client_pic' => $clientPic,
            'amount' => $candidate['amount_display'],
            'status' => $candidate['status_label'],
        ];
    }

    private function insertLog(
        array $candidate,
        array $stage,
        string $subject,
        string $bodySnapshot,
        string $status,
        ?string $failureReason = null
    ): void {
        DB::table('invoice_payment_reminder_logs')->insert([
            'invoice_id' => $candidate['invoice_id'],
            'stage' => $stage['key'],
            'triggered_by_staff_id' => null,
            'triggered_by_name' => 'System Scheduler',
            'triggered_by_code' => 'cron',
            'to_email' => $candidate['internal_pic_email'],
            'to_name' => $candidate['internal_pic_name'] !== '' ? $candidate['internal_pic_name'] : null,
            'cc' => null,
            'subject' => $subject,
            'body_snapshot' => $bodySnapshot,
            'status' => $status,
            'failure_reason' => $failureReason,
            'sent_at' => $status === 'sent' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tryInsertLog(
        array $candidate,
        array $stage,
        string $subject,
        string $bodySnapshot,
        string $status,
        ?string $failureReason = null
    ): bool {
        try {
            $this->insertLog($candidate, $stage, $subject, $bodySnapshot, $status, $failureReason);
            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    private function acquireCommandLock(): bool
    {
        try {
            $row = DB::selectOne("SELECT GET_LOCK('invoice_payment_follow_up_reminders', 0) AS acquired");
            return (int) ($row->acquired ?? 0) === 1;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    private function releaseCommandLock(): void
    {
        try {
            DB::selectOne("SELECT RELEASE_LOCK('invoice_payment_follow_up_reminders')");
        } catch (\Throwable $e) {
            report($e);
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

    private function displayName(string $name, string $fallback): string
    {
        $name = trim($name);
        return $name !== '' ? $name : $fallback;
    }

    private function isValidEmail(string $email): bool
    {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
