<?php

namespace App\Services\Salary;

use App\Jobs\SendHtmlMailJob;
use App\Services\AppNotificationService;
use App\Services\Mail\SystemEmailBodyBuilder;
use App\Services\Mail\SystemEmailUrlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalaryPaymentNotificationService
{
    private const SALARY_SUBJECT_TYPE = 'salary_application';

    private const OTHER_CLAIM_SUBJECT_TYPE = 'other_claim_application';

    private const FINANCIAL_MODULE = 'financial.payment-queue';

    private const APPLICANT_MODULE = 'my.payment-queue';

    public function __construct(
        private AppNotificationService $notifications,
        private SalaryWorkflowRecipientResolver $recipients,
        private SystemEmailBodyBuilder $emailBody,
        private SystemEmailUrlBuilder $emailUrls,
    ) {}

    public function notifyReady(string $subjectType, int $subjectId, int $actorId, string $cycle): bool
    {
        $context = $this->subjectContext($subjectType, $subjectId);
        if (! $context) {
            return false;
        }

        $recipientIds = $this->recipients->paymentRecipientIds([$context['staffId'], $actorId]);
        $this->notifications->createForStaffOnce($recipientIds, [
            'actor_staff_id' => $actorId > 0 ? $actorId : null,
            'module_key' => self::FINANCIAL_MODULE,
            'entity_type' => $subjectType,
            'entity_id' => $subjectId,
            'type' => $context['typePrefix'].'.'.SalaryNotificationType::PAYMENT_READY,
            'title' => $context['title'].' ready for payment',
            'message' => "{$context['staffLabel']}'s {$context['lowerTitle']} for {$context['periodLabel']} is approved and ready for payment.",
            'route' => $this->financialRoute($context['staffId'], $context['period']),
            'severity' => 'warning',
            'metadata' => [
                'staff_id' => $context['staffId'],
                'period' => $context['period'],
                'amount' => $context['amount'],
            ],
        ], $this->paymentReadyDedupeKey($subjectType, $subjectId, $cycle));

        return $recipientIds !== [];
    }

    public function resolveReady(string $subjectType, int $subjectId): void
    {
        $prefix = $subjectType === self::OTHER_CLAIM_SUBJECT_TYPE ? 'other_claim' : 'salary';
        $this->notifications->resolveOutstanding(
            self::FINANCIAL_MODULE,
            $subjectType,
            $subjectId,
            [$prefix.'.'.SalaryNotificationType::PAYMENT_READY],
        );
    }

    public function notifyPaid(int $paymentRunId, int $actorId): bool
    {
        $run = $this->paymentRun($paymentRunId);
        if (! $run) {
            return false;
        }

        foreach ($run['items'] as $item) {
            $this->resolveReady((string) $item->subject_type, (int) $item->subject_id);
        }

        $route = $this->applicantRoute((int) $run['record']->staff_id, (string) $run['record']->payment_period);
        $this->notifications->createForStaffOnce([(int) $run['record']->staff_id], [
            'actor_staff_id' => $actorId > 0 ? $actorId : null,
            'module_key' => self::APPLICANT_MODULE,
            'entity_type' => 'salary_payment_run',
            'entity_id' => $paymentRunId,
            'type' => 'salary_payment.'.SalaryNotificationType::PAID,
            'title' => 'Payment completed',
            'message' => 'Your salary/claim payment for '.$run['periodLabel'].' has been marked paid.',
            'route' => $route,
            'severity' => 'success',
            'metadata' => $this->paymentMetadata($run['record']),
        ], 'salary-payment:'.$paymentRunId.':'.SalaryNotificationType::PAID);

        return $this->queueApplicantMail(
            $run,
            'Your Salary/Claim Payment Has Been Completed',
            'Your salary/claim payment has been marked paid.',
            'Paid',
            $route,
        );
    }

    public function notifyReversed(array $paymentRunIds, int $actorId, string $reason): bool
    {
        $paymentRunIds = array_values(array_unique(array_filter(array_map('intval', $paymentRunIds))));
        if ($paymentRunIds === []) {
            return false;
        }

        $run = $this->paymentRun($paymentRunIds[0], $paymentRunIds);
        if (! $run) {
            return false;
        }

        $cycle = 'reversal-'.max($paymentRunIds);
        foreach ($run['items'] as $item) {
            $this->notifyReady((string) $item->subject_type, (int) $item->subject_id, $actorId, $cycle);
        }

        $entityId = max($paymentRunIds);
        $route = $this->applicantRoute((int) $run['record']->staff_id, (string) $run['record']->payment_period);
        $this->notifications->createForStaffOnce([(int) $run['record']->staff_id], [
            'actor_staff_id' => $actorId > 0 ? $actorId : null,
            'module_key' => self::APPLICANT_MODULE,
            'entity_type' => 'salary_payment_run',
            'entity_id' => $entityId,
            'type' => 'salary_payment.'.SalaryNotificationType::PAYMENT_REVERSED,
            'title' => 'Payment reversed',
            'message' => 'Your salary/claim payment for '.$run['periodLabel'].' was reversed. Reason: '.$reason,
            'route' => $route,
            'severity' => 'danger',
            'metadata' => [...$this->paymentMetadata($run['record']), 'reason' => $reason],
        ], 'salary-payment:'.$entityId.':'.SalaryNotificationType::PAYMENT_REVERSED);

        return $this->queueApplicantMail(
            $run,
            'Your Salary/Claim Payment Was Reversed',
            'Your salary/claim payment was reversed.',
            'Payment Reversed',
            $route,
            ['Reason' => $reason],
        );
    }

    private function subjectContext(string $subjectType, int $subjectId): ?array
    {
        $isOtherClaim = $subjectType === self::OTHER_CLAIM_SUBJECT_TYPE;
        if (! $isOtherClaim && $subjectType !== self::SALARY_SUBJECT_TYPE) {
            return null;
        }

        $table = $isOtherClaim ? 'hr_other_claim_applications' : 'hr_salary_applications';
        $record = DB::table($table.' as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->where('application.id', $subjectId)
            ->select('application.*', 'staff.full_name as staff_name', 'staff.name_code as staff_code')
            ->first();
        if (! $record) {
            return null;
        }

        $period = (string) ($isOtherClaim ? $record->claim_month : $record->salary_month);

        return [
            'staffId' => (int) $record->staff_id,
            'staffLabel' => $this->staffLabel((string) ($record->staff_name ?? ''), (string) ($record->staff_code ?? ''), (int) $record->staff_id),
            'period' => $period,
            'periodLabel' => (string) ($isOtherClaim ? ($record->claim_month_label ?: $period) : ($record->salary_month_label ?: $period)),
            'amount' => (float) ($isOtherClaim ? $record->claims_total : $record->payable_salary),
            'typePrefix' => $isOtherClaim ? 'other_claim' : 'salary',
            'title' => $isOtherClaim ? 'Other Claim' : 'Salary Claim',
            'lowerTitle' => $isOtherClaim ? 'other claim' : 'salary claim',
        ];
    }

    private function paymentRun(int $paymentRunId, ?array $paymentRunIds = null): ?array
    {
        $record = DB::table('hr_salary_payment_runs as run')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'run.staff_id')
            ->where('run.id', $paymentRunId)
            ->select('run.*', 'staff.full_name as staff_name', 'staff.name_code as staff_code', 'staff.email as staff_email')
            ->first();
        if (! $record) {
            return null;
        }

        $ids = $paymentRunIds ?: [$paymentRunId];
        if (count($ids) > 1) {
            $record->total_paid = (float) DB::table('hr_salary_payment_runs')
                ->whereIn('id', $ids)
                ->sum('total_paid');
        }

        return [
            'record' => $record,
            'items' => DB::table('hr_salary_payment_run_items')->whereIn('payment_run_id', $ids)->get(),
            'periodLabel' => $this->periodLabel((string) $record->payment_period),
            'staffLabel' => $this->staffLabel((string) ($record->staff_name ?? ''), (string) ($record->staff_code ?? ''), (int) $record->staff_id),
        ];
    }

    private function queueApplicantMail(
        array $run,
        string $subject,
        string $intro,
        string $status,
        string $route,
        array $extraDetails = [],
    ): bool {
        $record = $run['record'];
        $email = trim((string) ($record->staff_email ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $details = [
            'Period' => $run['periodLabel'],
            'Total' => 'RM '.number_format((float) $record->total_paid, 2),
            'Payment Date' => (string) ($record->payment_date ?: '-'),
            'Method' => (string) ($record->payment_method ?: '-'),
            'Reference' => (string) ($record->payment_reference ?: '-'),
            ...$extraDetails,
        ];
        $body = $this->emailBody->render([
            'intro' => $intro,
            'status' => ['label' => $status, 'tone' => $status === 'Paid' ? 'success' : 'danger'],
            'detailsHeading' => 'Payment Details',
            'details' => $details,
            'actionUrl' => $this->emailUrls->frontendUrl($route),
            'actionLabel' => 'Open in KIJO',
        ]);

        try {
            SendHtmlMailJob::dispatch(
                $email,
                $run['staffLabel'],
                $subject,
                $body,
                [],
                null,
                null,
                $this->emailBody->presentation('Salary & Claims', $subject, 'Payment update', $subject),
            )->afterCommit();

            return true;
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Salary payment notification email could not be queued.', [
                'payment_run_id' => (int) $record->id,
                'event' => $status,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function paymentMetadata(object $run): array
    {
        return [
            'payment_run_id' => (int) $run->id,
            'period' => (string) $run->payment_period,
            'total' => (float) $run->total_paid,
            'payment_date' => $run->payment_date,
            'payment_method' => $run->payment_method,
            'payment_reference' => $run->payment_reference,
        ];
    }

    private function paymentReadyDedupeKey(string $subjectType, int $subjectId, string $cycle): string
    {
        $cycle = preg_replace('/[^A-Za-z0-9_-]/', '', $cycle) ?: '0';

        return "payment-ready:{$subjectType}:{$subjectId}:{$cycle}";
    }

    private function financialRoute(int $staffId, string $period): string
    {
        return "/financial/payment-queue/{$staffId}/{$period}";
    }

    private function applicantRoute(int $staffId, string $period): string
    {
        return "/my/salary/payment-queue/{$staffId}/{$period}";
    }

    private function periodLabel(string $period): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $period);

        return $date ? $date->format('F Y') : $period;
    }

    private function staffLabel(string $name, string $code, int $staffId): string
    {
        $name = trim($name);
        $code = trim($code);
        if ($name !== '' && $code !== '') {
            return "{$name} ({$code})";
        }

        return $name !== '' ? $name : ($code !== '' ? $code : "Staff #{$staffId}");
    }
}
