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
            $this->notifications()->resolveActive(
                $context['financialModule'],
                $context['entityType'],
                $applicationId,
                [$context['typePrefix'].'.needs_check'],
            );

            $recipientIds = $this->stepRecipientIds($context['instance'], 'approve', [$applicantId, $actorId]);
            $this->notifications()->createForStaff($recipientIds, [
                'actor_staff_id' => $actorId,
                'module_key' => $context['financialModule'],
                'entity_type' => $context['entityType'],
                'entity_id' => $applicationId,
                'type' => $context['typePrefix'].'.needs_approval',
                'title' => $context['title'].' needs approval',
                'message' => "{$applicantName}'s {$context['lowerTitle']} has been checked.",
                'route' => $context['financialRoute'],
                'severity' => 'warning',
            ]);

            $this->sendApplicantMail($record, "Your {$context['title']} Has Been Checked", $this->actionBody($context, $record, 'checked', $actorName));

            return true;
        }

        if ($action === 'approve') {
            $this->notifications()->resolveActive(
                $context['financialModule'],
                $context['entityType'],
                $applicationId,
                [$context['typePrefix'].'.needs_approval'],
            );

            $this->notifications()->createForStaff([$applicantId], [
                'actor_staff_id' => $actorId,
                'module_key' => $context['applicantModule'],
                'entity_type' => $context['entityType'],
                'entity_id' => $applicationId,
                'type' => $context['typePrefix'].'.approved',
                'title' => $context['title'].' approved',
                'message' => "Your {$context['lowerTitle']} has been approved.",
                'route' => $context['applicantRoute'],
                'severity' => 'success',
            ]);

            return $this->sendApplicantMail(
                $record,
                "Your {$context['title']} Has Been Approved",
                $this->actionBody($context, $record, 'approved', $actorName),
            );
        }

        if ($action === 'reject') {
            $this->notifications()->resolveActive(
                $context['financialModule'],
                $context['entityType'],
                $applicationId,
                [$context['typePrefix'].'.needs_check', $context['typePrefix'].'.needs_approval'],
            );

            $this->notifications()->createForStaff([$applicantId], [
                'actor_staff_id' => $actorId,
                'module_key' => $context['applicantModule'],
                'entity_type' => $context['entityType'],
                'entity_id' => $applicationId,
                'type' => $context['typePrefix'].'.rejected',
                'title' => $context['title'].' rejected',
                'message' => "Your {$context['lowerTitle']} has been rejected.",
                'route' => $context['applicantRoute'],
                'severity' => 'danger',
            ]);

            return $this->sendApplicantMail(
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
        $context = $this->context($subjectType, $applicationId);
        if ($context) {
            $this->notifications()->resolveActive(
                $context['financialModule'],
                $context['entityType'],
                $applicationId,
                null,
            );
            $this->notifications()->resolveActive(
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
        $recipientIds = $this->stepRecipientIds($context['instance'], 'check', [$applicantId]);

        $this->notifications()->resolveActive(
            $context['financialModule'],
            $context['entityType'],
            $applicationId,
            null,
        );
        $this->notifications()->createForStaff($recipientIds, [
            'actor_staff_id' => $actorId,
            'module_key' => $context['financialModule'],
            'entity_type' => $context['entityType'],
            'entity_id' => $applicationId,
            'type' => $context['typePrefix'].'.needs_check',
            'title' => $context['title'].' needs check',
            'message' => "{$applicantName} submitted {$context['lowerTitle']} for {$context['period']}.",
            'route' => $context['financialRoute'],
            'severity' => 'warning',
        ]);

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
        $recipients = array_values(array_diff(
            array_unique(array_filter(array_map('intval', $recipientIds))),
            [$actorId, $applicantId],
        ));

        if ($recipients === []) {
            return false;
        }

        $title = $context['title'].' '.$changeLabel;
        $this->notifications()->createForStaff($recipients, [
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
        ]);

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
        return [
            'subjectType' => $subjectType,
            'entityType' => $subjectType,
            'record' => $record,
            'instance' => DB::table('workflow_instances')
                ->where('subject_type', $subjectType)
                ->where('subject_id', $applicationId)
                ->first(),
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

    private function stepRecipientIds(?object $instance, string $stepKey, array $excludeStaffIds = []): array
    {
        if (! $instance) {
            return [];
        }

        $step = DB::table('workflow_template_steps')
            ->where('template_id', $instance->template_id)
            ->where('step_key', $stepKey)
            ->where('active', 1)
            ->orderBy('sort_order')
            ->first();
        if (! $step) {
            return [];
        }

        $recipientIds = DB::table('workflow_step_recipients')
            ->where('step_id', $step->id)
            ->where('active', 1)
            ->pluck('staff_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($recipientIds)) {
            $recipientIds = $this->notifications()->staffIdsForRoles($this->decodeJsonArray($step->fallback_roles));
        }

        $exclude = array_values(array_unique(array_filter(array_map('intval', $excludeStaffIds))));

        return array_values(array_diff(array_unique(array_filter($recipientIds)), $exclude));
    }

    private function actionBody(array $context, object $record, string $actionLabel, string $actorName): string
    {
        return $this->bodyShell(
            "Your {$context['lowerTitle']} has been {$actionLabel}.",
            $context,
            $record,
            [
                'Status' => ucfirst($actionLabel),
                'Action by' => $actorName,
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

    private function sendApplicantMail(object $record, string $subject, string $body): bool
    {
        return $this->sendHtmlMailNow(
            trim((string) ($record->staff_email ?? '')),
            $this->staffLabel((string) ($record->staff_name ?? ''), (string) ($record->staff_code ?? ''), (int) ($record->staff_id ?? 0)),
            $subject,
            $body,
        );
    }

    private function sendHtmlMailNow(string $to, string $toName, string $subject, string $body, array $cc = []): bool
    {
        if (! $this->isValidEmail($to)) {
            return false;
        }

        $cc = array_values(array_filter(
            array_unique(array_map(static fn ($email): string => trim((string) $email), $cc)),
            fn (string $email): bool => $email !== '' && strtolower($email) !== strtolower($to) && $this->isValidEmail($email),
        ));

        try {
            SendHtmlMailJob::dispatchSync(
                $to,
                $toName,
                $subject,
                $body,
                $cc,
                null,
                null,
                $this->emailBody()->presentation($this->headerLabelFromSubject($subject), $subject, 'Workflow update', $subject),
            );

            Log::info('Salary workflow notification email sent.', [
                'subject' => $subject,
                'recipient_count' => 1 + count($cc),
                'success' => true,
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Salary workflow notification email failed.', [
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

    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
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
