<?php

namespace App\Services\Salary;

use App\Jobs\SendHtmlMailJob;
use App\Services\AppNotificationService;
use App\Services\Mail\SystemEmailBodyBuilder;
use App\Services\Mail\SystemEmailUrlBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalaryWorkflowNotificationService
{
    private const SALARY_SUBJECT_TYPE = 'salary_application';

    private const OTHER_CLAIM_SUBJECT_TYPE = 'other_claim_application';

    public function __construct(private SalaryWorkflowRecipientResolver $recipientResolver) {}

    public function notifySubmittedSalary(Request $request, int $applicationId): bool
    {
        return $this->notifySubmitted($request, self::SALARY_SUBJECT_TYPE, $applicationId);
    }

    public function notifySalarySubmitted(Request $request, int $applicationId): bool
    {
        return $this->notifySubmittedSalary($request, $applicationId);
    }

    public function notifySubmittedOtherClaim(Request $request, int $applicationId): bool
    {
        return $this->notifySubmitted($request, self::OTHER_CLAIM_SUBJECT_TYPE, $applicationId);
    }

    public function notifyOtherClaimSubmitted(Request $request, int $applicationId): bool
    {
        return $this->notifySubmittedOtherClaim($request, $applicationId);
    }

    public function reconcilePending(string $subjectType, int $applicationId): int
    {
        $context = $this->context($subjectType, $applicationId);
        if (! $context || ! $context['instance']) {
            return 0;
        }

        $instance = $context['instance'];
        $status = (string) $instance->status;
        $isApproval = $status === 'Checked';
        if (! $isApproval && ! in_array($status, ['Submitted', 'Prepared'], true)) {
            return 0;
        }

        $record = $context['record'];
        $applicantId = (int) ($record->staff_id ?? 0);
        $exclude = [$applicantId];
        if ($isApproval) {
            $exclude[] = (int) ($record->checked_by ?? 0);
        }
        $stepKey = $isApproval ? 'approve' : 'check';
        $event = $isApproval ? SalaryNotificationType::NEEDS_APPROVAL : SalaryNotificationType::NEEDS_CHECK;
        $recipientIds = $this->recipientResolver->stepRecipientIds($instance, $stepKey, $exclude);
        $applicantName = $this->staffLabel(
            (string) ($record->staff_name ?? ''),
            (string) ($record->staff_code ?? ''),
            $applicantId,
        );

        $this->notifications()->createForStaffOnce($recipientIds, [
            'actor_staff_id' => null,
            'module_key' => $context['financialModule'],
            'entity_type' => $context['entityType'],
            'entity_id' => $applicationId,
            'type' => $context['typePrefix'].'.'.$event,
            'title' => $context['title'].($isApproval ? ' needs approval' : ' needs check'),
            'message' => $isApproval
                ? "{$applicantName}'s {$context['lowerTitle']} has been checked."
                : "{$applicantName} submitted {$context['lowerTitle']} for {$context['period']}.",
            'route' => $context['financialRoute'],
            'severity' => 'warning',
        ], $this->dedupeKey($context, $event));

        return count($recipientIds);
    }

    public function resolvePending(string $subjectType, int $applicationId): int
    {
        $context = $this->context($subjectType, $applicationId);
        if (! $context) {
            return 0;
        }

        return count($this->notifications()->resolveOutstanding(
            $context['financialModule'],
            $context['entityType'],
            $applicationId,
            [
                $context['typePrefix'].'.'.SalaryNotificationType::NEEDS_CHECK,
                $context['typePrefix'].'.'.SalaryNotificationType::NEEDS_APPROVAL,
            ],
        ));
    }

    public function notifyWorkflowAction(Request $request, string $subjectType, int $applicationId, string $action): bool
    {
        $context = $this->context($subjectType, $applicationId);
        if (! $context) {
            return false;
        }

        $record = $context['record'];
        $actorId = $this->staffId($request);
        $actorName = $this->staffLabel(
            (string) $request->session()->get('full_name', ''),
            (string) $request->session()->get('name_code', ''),
            $actorId,
        );
        $applicantId = (int) ($record->staff_id ?? 0);
        $applicantName = $this->staffLabel(
            (string) ($record->staff_name ?? ''),
            (string) ($record->staff_code ?? ''),
            $applicantId,
        );

        if ($action === 'check') {
            $this->notifications()->resolveOutstanding(
                $context['financialModule'],
                $context['entityType'],
                $applicationId,
                [$context['typePrefix'].'.needs_check'],
            );

            $recipientIds = $this->recipientResolver->stepRecipientIds($context['instance'], 'approve', [$applicantId, $actorId]);
            $this->notifications()->createForStaffOnce($recipientIds, [
                'actor_staff_id' => $actorId,
                'module_key' => $context['financialModule'],
                'entity_type' => $context['entityType'],
                'entity_id' => $applicationId,
                'type' => $context['typePrefix'].'.needs_approval',
                'title' => $context['title'].' needs approval',
                'message' => "{$applicantName}'s {$context['lowerTitle']} has been checked.",
                'route' => $context['financialRoute'],
                'severity' => 'warning',
            ], $this->dedupeKey($context, SalaryNotificationType::NEEDS_APPROVAL));

            $this->createApplicantNotification(
                $context,
                $applicantId,
                $actorId,
                SalaryNotificationType::CHECKED,
                $context['title'].' checked',
                "Your {$context['lowerTitle']} has been checked and is pending approval.",
                'info',
            );

            $this->queueApplicantMail($record, "Your {$context['title']} Has Been Checked", $this->actionBody($context, $record, 'checked', $actorName));

            return true;
        }

        if ($action === 'approve') {
            $this->notifications()->resolveOutstanding(
                $context['financialModule'],
                $context['entityType'],
                $applicationId,
                [$context['typePrefix'].'.needs_approval'],
            );

            $this->createApplicantNotification(
                $context,
                $applicantId,
                $actorId,
                SalaryNotificationType::APPROVED,
                $context['title'].' approved',
                "Your {$context['lowerTitle']} has been approved.",
                'success',
            );

            $mailQueued = $this->queueApplicantMail(
                $record,
                "Your {$context['title']} Has Been Approved",
                $this->actionBody($context, $record, 'approved', $actorName),
            );
            app(SalaryPaymentNotificationService::class)->notifyReady(
                $subjectType,
                $applicationId,
                $actorId,
                $this->dedupeVersion($context),
            );

            return $mailQueued;
        }

        if ($action === 'reject') {
            $this->notifications()->resolveOutstanding(
                $context['financialModule'],
                $context['entityType'],
                $applicationId,
                [$context['typePrefix'].'.needs_check', $context['typePrefix'].'.needs_approval'],
            );

            app(SalaryPaymentNotificationService::class)->resolveReady($subjectType, $applicationId);
            $this->createApplicantNotification(
                $context,
                $applicantId,
                $actorId,
                SalaryNotificationType::REJECTED,
                $context['title'].' rejected',
                "Your {$context['lowerTitle']} has been rejected.",
                'danger',
            );

            return $this->queueApplicantMail(
                $record,
                "Your {$context['title']} Has Been Rejected",
                $this->actionBody($context, $record, 'rejected', $actorName),
            );
        }

        return false;
    }

    public function notifySalaryAction(Request $request, int $applicationId, string $action, string $statusTo = ''): bool
    {
        return $this->notifyWorkflowAction($request, self::SALARY_SUBJECT_TYPE, $applicationId, $action);
    }

    public function notifyOtherClaimAction(Request $request, int $applicationId, string $action, string $statusTo = ''): bool
    {
        return $this->notifyWorkflowAction($request, self::OTHER_CLAIM_SUBJECT_TYPE, $applicationId, $action);
    }

    public function notifyRecordAmended(
        Request $request,
        string $subjectType,
        int $applicationId,
        array $recipientIds,
        string $reason,
    ): bool {
        return $this->notifyRecordChanged($request, $subjectType, $applicationId, $recipientIds, 'amended', $reason);
    }

    public function notifyRecordCancelled(
        Request $request,
        string $subjectType,
        int $applicationId,
        array $recipientIds,
        string $reason,
    ): bool {
        app(SalaryPaymentNotificationService::class)->resolveReady($subjectType, $applicationId);
        $context = $this->context($subjectType, $applicationId);
        if ($context) {
            $this->notifications()->resolveOutstanding(
                $context['financialModule'],
                $context['entityType'],
                $applicationId,
                null,
            );
            $this->notifications()->resolveOutstanding(
                $context['applicantModule'],
                $context['entityType'],
                $applicationId,
                null,
            );
        }

        return $this->notifyRecordChanged($request, $subjectType, $applicationId, $recipientIds, 'cancelled', $reason);
    }

    private function notifySubmitted(Request $request, string $subjectType, int $applicationId): bool
    {
        $context = $this->context($subjectType, $applicationId);
        if (! $context) {
            return false;
        }

        $record = $context['record'];
        $actorId = $this->staffId($request);
        $applicantId = (int) ($record->staff_id ?? 0);
        $applicantName = $this->staffLabel(
            (string) ($record->staff_name ?? ''),
            (string) ($record->staff_code ?? ''),
            $applicantId,
        );
        $recipientIds = $this->recipientResolver->stepRecipientIds($context['instance'], 'check', [$applicantId]);

        $this->notifications()->resolveOutstanding(
            $context['financialModule'],
            $context['entityType'],
            $applicationId,
            null,
        );
        $this->notifications()->createForStaffOnce($recipientIds, [
            'actor_staff_id' => $actorId,
            'module_key' => $context['financialModule'],
            'entity_type' => $context['entityType'],
            'entity_id' => $applicationId,
            'type' => $context['typePrefix'].'.needs_check',
            'title' => $context['title'].' needs check',
            'message' => "{$applicantName} submitted {$context['lowerTitle']} for {$context['period']}.",
            'route' => $context['financialRoute'],
            'severity' => 'warning',
        ], $this->dedupeKey($context, SalaryNotificationType::NEEDS_CHECK));

        $this->createApplicantNotification(
            $context,
            $applicantId,
            $actorId,
            SalaryNotificationType::SUBMITTED,
            $context['title'].' submitted',
            "Your {$context['lowerTitle']} was submitted for review.",
            'info',
        );
        $this->queueApplicantMail(
            $record,
            "Your {$context['title']} Has Been Submitted",
            $this->bodyShell(
                "Your {$context['lowerTitle']} has been submitted for review.",
                $context,
                $record,
                ['Status' => 'Submitted'],
                $context['applicantRoute'],
            ),
        );

        return ! empty($recipientIds);
    }

    private function notifyRecordChanged(
        Request $request,
        string $subjectType,
        int $applicationId,
        array $recipientIds,
        string $changeLabel,
        string $reason,
    ): bool {
        $context = $this->context($subjectType, $applicationId);
        if (! $context) {
            return false;
        }

        $record = $context['record'];
        $actorId = $this->staffId($request);
        $applicantId = (int) ($record->staff_id ?? 0);
        $applicantName = $this->staffLabel(
            (string) ($record->staff_name ?? ''),
            (string) ($record->staff_code ?? ''),
            $applicantId,
        );
        if ($changeLabel === SalaryNotificationType::AMENDED) {
            app(SalaryPaymentNotificationService::class)->resolveReady($subjectType, $applicationId);
        }

        $recipients = array_values(array_diff(
            array_unique(array_filter(array_map('intval', $recipientIds))),
            [$actorId, $applicantId],
        ));

        if ($recipients === []) {
            return false;
        }

        $title = $context['title'].' '.$changeLabel;
        $this->notifications()->createForStaffOnce($recipients, [
            'actor_staff_id' => $actorId,
            'module_key' => $context['financialModule'],
            'entity_type' => $context['entityType'],
            'entity_id' => $applicationId,
            'type' => $context['typePrefix'].'.'.$changeLabel,
            'title' => $title,
            'message' => "{$applicantName}'s {$context['lowerTitle']} for {$context['period']} was {$changeLabel}. Reason: {$reason}",
            'route' => $context['financialRoute'],
            'severity' => $changeLabel === 'cancelled' ? 'danger' : 'warning',
            'metadata' => ['reason' => $reason],
        ], $this->dedupeKey($context, $changeLabel));

        return true;
    }

    private function context(string $subjectType, int $applicationId): ?array
    {
        if ($subjectType === self::OTHER_CLAIM_SUBJECT_TYPE) {
            $record = DB::table('hr_other_claim_applications as application')
                ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
                ->where('application.id', $applicationId)
                ->select('application.*', 'staff.full_name as staff_name', 'staff.name_code as staff_code', 'staff.email as staff_email')
                ->first();
            if (! $record) {
                return null;
            }

            return $this->baseContext(
                $subjectType,
                $applicationId,
                $record,
                'financial.other-claims',
                'my.other-claims',
                'other_claim',
                'Other Claim',
                'other claim',
                (string) ($record->claim_month_label ?? $record->claim_month ?? ''),
                '/financial/other-claim-records',
                '/my/salary/other-claims/records/'.$applicationId,
                ['Claims Total' => (float) ($record->claims_total ?? 0)],
            );
        }

        $record = DB::table('hr_salary_applications as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->where('application.id', $applicationId)
            ->select('application.*', 'staff.full_name as staff_name', 'staff.name_code as staff_code', 'staff.email as staff_email')
            ->first();
        if (! $record) {
            return null;
        }

        return $this->baseContext(
            $subjectType,
            $applicationId,
            $record,
            'financial.salary',
            'my.salary',
            'salary',
            'Salary Claim',
            'salary claim',
            (string) ($record->salary_month_label ?? $record->salary_month ?? ''),
            '/financial/salary-records',
            '/my/salary/records/'.(string) ($record->salary_month ?? $applicationId),
            [
                'Claims Total' => (float) ($record->claims_total ?? 0),
                'Payable Salary' => (float) ($record->payable_salary ?? 0),
            ],
        );
    }

    private function baseContext(
        string $subjectType,
        int $applicationId,
        object $record,
        string $financialModule,
        string $applicantModule,
        string $typePrefix,
        string $title,
        string $lowerTitle,
        string $period,
        string $financialRoute,
        string $applicantRoute,
        array $amounts,
    ): array {
        $instance = DB::table('workflow_instances')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $applicationId)
            ->orderByDesc('id')
            ->first();
        $actionId = $instance
            ? (int) DB::table('workflow_actions')->where('instance_id', $instance->id)->max('id')
            : 0;

        return [
            'subjectType' => $subjectType,
            'entityType' => $subjectType,
            'record' => $record,
            'instance' => $instance,
            'notificationVersion' => $actionId > 0 ? (string) $actionId : (string) ($record->updated_at ?? '0'),
            'financialModule' => $financialModule,
            'applicantModule' => $applicantModule,
            'typePrefix' => $typePrefix,
            'title' => $title,
            'lowerTitle' => $lowerTitle,
            'period' => $period,
            'financialRoute' => $financialRoute,
            'applicantRoute' => $applicantRoute,
            'amounts' => $amounts,
        ];
    }

    private function actionBody(array $context, object $record, string $actionLabel, string $actorName): string
    {
        $remarks = match ($actionLabel) {
            'checked' => trim((string) ($record->checked_remarks ?? '')),
            'approved' => trim((string) ($record->approved_remarks ?? '')),
            'rejected' => trim((string) (($record->approved_remarks ?? '') ?: ($record->checked_remarks ?? ''))),
            default => '',
        };

        return $this->bodyShell(
            "Your {$context['lowerTitle']} has been {$actionLabel}.",
            $context,
            $record,
            [
                'Status' => ucfirst($actionLabel),
                'Action by' => $actorName,
                ...($remarks !== '' ? ['Remarks' => $remarks] : []),
            ],
            $context['applicantRoute'],
        );
    }

    private function bodyShell(string $opening, array $context, object $record, array $extraRows, string $route): string
    {
        $amountRows = [];
        foreach ($context['amounts'] as $label => $value) {
            $amountRows[$label] = 'RM '.number_format((float) $value, 2);
        }

        $rows = [
            'Period' => $context['period'],
            ...$amountRows,
            ...$extraRows,
        ];

        $status = (string) ($extraRows['Status'] ?? '');

        return $this->emailBody()->render([
            'intro' => $opening,
            'status' => $status !== '' ? [
                'label' => $status,
                'tone' => match (strtolower($status)) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    default => 'warning',
                },
            ] : null,
            'detailsHeading' => $context['title'].' Details',
            'details' => $rows,
            'actionUrl' => $this->emailUrls()->frontendUrl($route),
            'actionLabel' => 'Open in KIJO',
        ]);
    }

    private function queueApplicantMail(object $record, string $subject, string $body): bool
    {
        return $this->queueHtmlMail(
            trim((string) ($record->staff_email ?? '')),
            $this->staffLabel((string) ($record->staff_name ?? ''), (string) ($record->staff_code ?? ''), (int) ($record->staff_id ?? 0)),
            $subject,
            $body,
        );
    }

    private function queueHtmlMail(string $to, string $toName, string $subject, string $body, array $cc = []): bool
    {
        if (! $this->isValidEmail($to)) {
            return false;
        }

        $cc = array_values(array_filter(
            array_unique(array_map(static fn ($email): string => trim((string) $email), $cc)),
            fn (string $email): bool => $email !== '' && strtolower($email) !== strtolower($to) && $this->isValidEmail($email),
        ));

        try {
            SendHtmlMailJob::dispatch(
                $to,
                $toName,
                $subject,
                $body,
                $cc,
                null,
                null,
                $this->emailBody()->presentation($this->headerLabelFromSubject($subject), $subject, 'Workflow update', $subject),
            )->afterCommit();

            Log::info('Salary workflow notification email queued.', [
                'subject' => $subject,
                'recipient_count' => 1 + count($cc),
                'success' => true,
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Salary workflow notification email could not be queued.', [
                'subject' => $subject,
                'recipient_count' => 1 + count($cc),
                'success' => false,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function staffId(Request $request): int
    {
        return (int) $request->session()->get('staff_id', 0);
    }

    private function staffLabel(string $name, string $code, ?int $fallbackId = null): string
    {
        $name = trim($name);
        $code = trim($code);
        if ($name !== '' && $code !== '') {
            return "{$name} ({$code})";
        }
        if ($name !== '') {
            return $name;
        }
        if ($code !== '') {
            return $code;
        }

        return $fallbackId ? "Staff #{$fallbackId}" : 'Staff';
    }

    private function isValidEmail(?string $email): bool
    {
        $email = trim((string) $email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function notifications(): AppNotificationService
    {
        return app(AppNotificationService::class);
    }

    private function createApplicantNotification(
        array $context,
        int $applicantId,
        int $actorId,
        string $event,
        string $title,
        string $message,
        string $severity,
    ): void {
        if ($applicantId <= 0) {
            return;
        }

        $this->notifications()->createForStaffOnce([$applicantId], [
            'actor_staff_id' => $actorId > 0 ? $actorId : null,
            'module_key' => $context['applicantModule'],
            'entity_type' => $context['entityType'],
            'entity_id' => (int) $context['record']->id,
            'type' => $context['typePrefix'].'.'.$event,
            'title' => $title,
            'message' => $message,
            'route' => $context['applicantRoute'],
            'severity' => $severity,
        ], $this->dedupeKey($context, $event));
    }

    private function dedupeKey(array $context, string $event): string
    {
        return implode(':', [
            $context['typePrefix'],
            (int) $context['record']->id,
            $event,
            $this->dedupeVersion($context),
        ]);
    }

    private function dedupeVersion(array $context): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($context['notificationVersion'] ?? '0')) ?: '0';
    }

    private function emailBody(): SystemEmailBodyBuilder
    {
        return app(SystemEmailBodyBuilder::class);
    }

    private function emailUrls(): SystemEmailUrlBuilder
    {
        return app(SystemEmailUrlBuilder::class);
    }

    private function headerLabelFromSubject(string $subject): string
    {
        return str_contains($subject, 'Other Claim') ? 'Other Claim' : 'Salary Claim';
    }
}
