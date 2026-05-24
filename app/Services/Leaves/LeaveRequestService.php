<?php

namespace App\Services\Leaves;

use App\Http\Requests\Leave\AssignEntitlementRequest;
use App\Http\Requests\Leave\LeaveActionRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Requests\Leave\UpdateEntitlementRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\AuditLogService;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveRequestService extends LeaveBaseService
{
    public function createLeave(StoreLeaveRequest $request): JsonResponse
    {
        $data    = $request->validated();
        $staffId = (int) $request->session()->get('staff_id');
        $name    = (string) $request->session()->get('full_name', $request->session()->get('name_code', 'Staff'));

        // Same-day time validation
        if ($data['start_date'] === $data['end_date'] && $data['start_time'] >= $data['end_time']) {
            return response()->json([
                'status'  => 'error',
                'message' => 'For same-day leave, start_time must be earlier than end_time.',
            ], 422);
        }

        $leaveId = DB::table('hr_leaves_application')->insertGetId([
            'staff_id'      => $staffId,
            'type'          => $data['type'],
            'reason'        => $data['reason'] ?? null,
            'start_date'    => $data['start_date'],
            'start_time'    => $data['start_time'],
            'end_date'      => $data['end_date'],
            'end_time'      => $data['end_time'],
            'duration_days' => $data['duration_days'],
            'status'        => $data['status'],
            'applied_at'    => now(),
        ]);

        $this->auditLog->log($request, "Created leave application #{$leaveId}");
        $this->notifyLeaveNeedsRecommendation($request, $leaveId, $staffId, $name, $data);

        $reason      = htmlspecialchars($data['reason'] ?? 'N/A');
        $applicantName = htmlspecialchars($name);
        $body = "
            <p>A new leave application has been submitted in KIJO.</p>
            <table cellpadding='6' cellspacing='0' border='1' style='border-collapse:collapse;'>
                <tr><th align='left'>Applicant</th><td>{$applicantName}</td></tr>
                <tr><th align='left'>Leave Type</th><td>" . htmlspecialchars($data['type']) . "</td></tr>
                <tr><th align='left'>Start</th><td>" . htmlspecialchars($data['start_date']) . " " . htmlspecialchars($data['start_time']) . "</td></tr>
                <tr><th align='left'>End</th><td>" . htmlspecialchars($data['end_date']) . " " . htmlspecialchars($data['end_time']) . "</td></tr>
                <tr><th align='left'>Duration</th><td>" . htmlspecialchars((string) $data['duration_days']) . " day(s)</td></tr>
                <tr><th align='left'>Reason</th><td>{$reason}</td></tr>
            </table>
        ";

        $recipients = $this->workflowRecipients()->recipientsForStage(
            LeaveWorkflowRecipientService::STAGE_SUBMITTED_RECOMMENDERS,
            ['HR'],
        );
        $mailSent = $this->sendHtmlMailToRecipients(
            $recipients,
            "New Leave Application by {$applicantName}",
            $body,
        );

        return response()->json([
            'status'    => 'success',
            'message'   => 'Leave application submitted successfully.',
            'leave_id'  => $leaveId,
            'mail_sent' => $mailSent,
            'mail_message' => $mailSent
                ? 'Leave application submitted and notification email sent.'
                : 'Leave application submitted, but notification email could not be sent. Check mail settings and recipient list.',
        ]);
    }

    public function getAllLeavesData(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->unauthorizedResponse();
        }

        $year = (int) $request->query('year', 0);

        $leaves = DB::table('hr_leaves_application as hla')
            ->select([
                'hla.*',
                'sg.full_name as applicant_name',
                'sg.name_code as applicant_code',
                'reviewer.full_name as reviewer_name',
                'reviewer.name_code as reviewer_code',
                'approver.full_name as approver_name',
                'approver.name_code as approver_code',
                'canceller.full_name as canceller_name',
                'canceller.name_code as canceller_code',
            ])
            ->leftJoin('staff_general as sg', 'hla.staff_id', '=', 'sg.staff_id')
            ->leftJoin('staff_general as reviewer', 'hla.reviewed_by', '=', 'reviewer.staff_id')
            ->leftJoin('staff_general as approver', 'hla.approved_by', '=', 'approver.staff_id')
            ->leftJoin('staff_general as canceller', 'hla.cancelled_by', '=', 'canceller.staff_id')
            ->when($year >= 2000 && $year <= 2100, function ($query) use ($year) {
                $query->where(function ($scoped) use ($year) {
                    $scoped
                        ->whereYear('hla.start_date', $year)
                        ->orWhereYear('hla.applied_at', $year);
                });
            })
            ->orderByDesc('hla.applied_at')
            ->get();

        return response()->json(['status' => 'success', 'leaves' => $leaves]);
    }

    public function getPersonalLeavesRecord(Request $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id');

        $leaves = DB::table('hr_leaves_application')
            ->where('staff_id', $staffId)
            ->when((int) $request->query('year', 0) >= 2000 && (int) $request->query('year', 0) <= 2100, function ($query) use ($request) {
                $query->whereYear('start_date', (int) $request->query('year'));
            })
            ->orderBy('applied_at', 'desc')
            ->get();

        return response()->json(['status' => 'success', 'leaves' => $leaves]);
    }

    public function leaveAction(LeaveActionRequest $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->unauthorizedResponse();
        }

        $data    = $request->validated();
        $leaveId = (int) $data['id'];
        $action  = $data['action'];
        $remarks = $data['remarks'] ?? null;
        $actorId = (int) $request->session()->get('staff_id');
        $actorName = (string) $request->session()->get('full_name', $request->session()->get('name_code', 'Staff'));

        DB::beginTransaction();
        try {
            $leave = DB::table('hr_leaves_application')
                ->lockForUpdate()
                ->where('id', $leaveId)
                ->first();

            if (!$leave) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Leave application not found.'], 404);
            }

            $status = strtolower((string) $leave->status);

            if ($action === 'recommend') {
                if (in_array($status, ['cancelled', 'rejected', 'approved'], true)) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Leave cannot be recommended in its current state.',
                    ], 422);
                }
                if (!empty($leave->reviewed_by)) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Leave has already been reviewed.',
                    ], 422);
                }
                if (!$this->canActForLeaveStage(
                    $request,
                    LeaveWorkflowRecipientService::STAGE_SUBMITTED_RECOMMENDERS,
                    ['HR'],
                )) {
                    DB::rollBack();
                    return $this->unauthorizedLeaveActionResponse('recommend this leave');
                }

                DB::table('hr_leaves_application')->where('id', $leaveId)->update([
                    'reviewed_by'      => $actorId,
                    'reviewed_at'      => now(),
                    'reviewed_status'  => 'Recommended',
                    'reviewed_remarks' => $remarks,
                ]);

            } elseif ($action === 'approve') {
                if (in_array($status, ['cancelled', 'rejected', 'approved'], true)) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Leave cannot be approved in its current state.',
                    ], 422);
                }
                if (empty($leave->reviewed_by)) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Leave must be reviewed/recommended before it can be approved.',
                    ], 422);
                }
                if (!$this->canActForLeaveStage(
                    $request,
                    LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS,
                    ['Manager', 'System Admin'],
                )) {
                    DB::rollBack();
                    return $this->unauthorizedLeaveActionResponse('approve this leave');
                }

                DB::table('hr_leaves_application')->where('id', $leaveId)->update([
                    'approved_by'      => $actorId,
                    'approved_at'      => now(),
                    'approved_status'  => 'Approved',
                    'approved_remarks' => $remarks,
                    'status'           => 'Approved',
                ]);

                $this->adjustAllocationUsedDays($leave, (float) $leave->duration_days);

            } elseif ($action === 'reject') {
                if (in_array($status, ['cancelled', 'rejected', 'approved'], true)) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Leave cannot be rejected in its current state.',
                    ], 422);
                }

                if (!empty($leave->reviewed_by)) {
                    if (!$this->canActForLeaveStage(
                        $request,
                        LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS,
                        ['Manager', 'System Admin'],
                    )) {
                        DB::rollBack();
                        return $this->unauthorizedLeaveActionResponse('reject this reviewed leave');
                    }

                    // Already reviewed - reject at approver level
                    DB::table('hr_leaves_application')->where('id', $leaveId)->update([
                        'approved_by'      => $actorId,
                        'approved_at'      => now(),
                        'approved_status'  => 'Rejected',
                        'approved_remarks' => $remarks,
                        'status'           => 'Rejected',
                    ]);
                } else {
                    if (!$this->canActForLeaveStage(
                        $request,
                        LeaveWorkflowRecipientService::STAGE_SUBMITTED_RECOMMENDERS,
                        ['HR'],
                    )) {
                        DB::rollBack();
                        return $this->unauthorizedLeaveActionResponse('reject this unreviewed leave');
                    }

                    // Not yet reviewed - reject at reviewer level
                    DB::table('hr_leaves_application')->where('id', $leaveId)->update([
                        'reviewed_by'      => $actorId,
                        'reviewed_at'      => now(),
                        'reviewed_status'  => 'Rejected',
                        'reviewed_remarks' => $remarks,
                        'status'           => 'Rejected',
                    ]);
                }
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'An error occurred: ' . $e->getMessage()], 500);
        }

        $this->auditLog->log($request, "Leave action '{$action}' performed on leave #{$leaveId}");

        // Fetch applicant email for notification
        $applicant = DB::table('staff_general')->where('staff_id', $leave->staff_id)->first();
        $applicantEmail     = $applicant->email ?? null;
        $applicantNameStr   = htmlspecialchars($applicant->full_name ?? ('Staff #' . $leave->staff_id));
        $actorNameSafe      = htmlspecialchars($actorName);

        $this->handleLeaveActionNotifications(
            $request,
            $leave,
            $leaveId,
            $action,
            $actorId,
            strip_tags($applicantNameStr),
        );

        // Dispatch emails asynchronously
        if ($action === 'recommend') {
            $approverBody = "
                <p>A leave application by <strong>{$applicantNameStr}</strong> has been recommended and is pending your approval.</p>
                <p><strong>Leave Type:</strong> " . htmlspecialchars((string) $leave->type) . "</p>
                <p><strong>Duration:</strong> " . htmlspecialchars((string) $leave->duration_days) . " day(s)
                   (" . htmlspecialchars((string) $leave->start_date) . " to " . htmlspecialchars((string) $leave->end_date) . ")</p>
                <p><strong>Recommended by:</strong> {$actorNameSafe}</p>
            ";
            $this->sendHtmlMailToRecipients(
                $this->workflowRecipients()->recipientsForStage(
                    LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS,
                    ['Manager', 'System Admin'],
                ),
                "Please Approve Leave Application by {$applicantNameStr}",
                $approverBody,
            );
        }

        $subjectMap = [
            'recommend' => "Your Leave Application Has Been Recommended",
            'approve'   => "Your Leave Application Has Been Approved",
            'reject'    => "Your Leave Application Has Been Rejected",
        ];
        $applicantBody = "
            <p>Dear <strong>{$applicantNameStr}</strong>,</p>
            <p>Your leave application has been <strong>" . htmlspecialchars(ucfirst($action) . 'd') . "</strong>.</p>
            <p><strong>Leave Type:</strong> " . htmlspecialchars((string) $leave->type) . "</p>
            <p><strong>Duration:</strong> " . htmlspecialchars((string) $leave->duration_days) . " day(s)
               (" . htmlspecialchars((string) $leave->start_date) . " to " . htmlspecialchars((string) $leave->end_date) . ")</p>
            <p><strong>Action by:</strong> {$actorNameSafe}</p>
        ";

        // Notify applicant on any action
        if ($this->isValidEmail($applicantEmail)) {
            $this->sendHtmlMailNow(
                $applicantEmail,
                $applicantNameStr,
                $subjectMap[$action],
                $applicantBody,
            );
        }

        if (in_array($action, ['approve', 'reject'], true)) {
            $stageKey = $action === 'approve'
                ? LeaveWorkflowRecipientService::STAGE_APPROVED_NOTIFY
                : LeaveWorkflowRecipientService::STAGE_REJECTED_NOTIFY;
            $stageRecipients = $this->configuredOrWorkflowParticipantRecipients(
                $stageKey,
                $leave,
                [],
                [(int) $leave->staff_id],
            );
            $this->sendHtmlMailToRecipients(
                $stageRecipients,
                $subjectMap[$action],
                $applicantBody,
            );
        }

        return response()->json(['status' => 'success', 'message' => 'Leave action processed successfully.']);
    }

    public function cancelLeave(Request $request): JsonResponse
    {
        $request->validate(['id' => ['required', 'integer', 'min:1']]);

        $staffId = (int) $request->session()->get('staff_id');
        $name    = (string) $request->session()->get('full_name', $request->session()->get('name_code', 'Staff'));
        $leaveId = (int) $request->input('id');

        DB::beginTransaction();
        try {
            $leaveQuery = DB::table('hr_leaves_application')
                ->lockForUpdate()
                ->where('id', $leaveId);

            if (!$this->isPrivileged($request)) {
                $leaveQuery->where('staff_id', $staffId);
            }

            $leave = $leaveQuery->first();

            if (!$leave) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Leave application not found or you do not have permission to cancel it.',
                ], 403);
            }

            $previousStatus = (string) $leave->status;

            DB::table('hr_leaves_application')->where('id', $leaveId)->update([
                'status'       => 'Cancelled',
                'cancelled_by' => $staffId,
                'cancelled_at' => now(),
            ]);

            // If previously Approved, reverse allocation usage
            if (strtolower($previousStatus) === 'approved') {
                $this->adjustAllocationUsedDays($leave, -1 * (float) $leave->duration_days);
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'An error occurred: ' . $e->getMessage()], 500);
        }

        $this->auditLog->log($request, "Cancelled leave application #{$leaveId}");
        $activeRecipientIds = $this->handleLeaveCancellationNotifications($request, $leave, $leaveId, $staffId, $previousStatus);

        $nameSafe = htmlspecialchars($name);
        $body = "
            <p>A leave application has been cancelled by <strong>{$nameSafe}</strong>.</p>
            <p><strong>Leave Type:</strong> " . htmlspecialchars((string) $leave->type) . "</p>
            <p><strong>Duration:</strong> " . htmlspecialchars((string) $leave->duration_days) . " day(s)
               (" . htmlspecialchars((string) $leave->start_date) . " to " . htmlspecialchars((string) $leave->end_date) . ")</p>
        ";

        $isRevokedApprovedLeave = strtolower($previousStatus) === 'approved';
        $stageKey = $isRevokedApprovedLeave
            ? LeaveWorkflowRecipientService::STAGE_REVOKED_NOTIFY
            : LeaveWorkflowRecipientService::STAGE_CANCELLED_NOTIFY;
        $stageRecipients = $this->configuredOrWorkflowParticipantRecipients(
            $stageKey,
            $leave,
            $activeRecipientIds,
            [(int) $leave->staff_id],
        );
        $this->sendHtmlMailToRecipients(
            $stageRecipients,
            $isRevokedApprovedLeave
                ? "Leave Application Revoked by {$nameSafe}"
                : "Leave Application Cancelled by {$nameSafe}",
            $body,
        );

        if ($isRevokedApprovedLeave && (int) $leave->staff_id !== $staffId) {
            $applicant = DB::table('staff_general')->where('staff_id', $leave->staff_id)->first();
            $applicantEmail = $applicant->email ?? null;
            if ($this->isValidEmail($applicantEmail)) {
                $applicantName = htmlspecialchars($applicant->full_name ?? ('Staff #' . $leave->staff_id));
                $this->sendHtmlMailNow(
                    $applicantEmail,
                    $applicantName,
                    "Your Leave Application Has Been Revoked",
                    "<p>Dear <strong>{$applicantName}</strong>,</p>{$body}",
                );
            }
        }

        if (!$isRevokedApprovedLeave && (int) $leave->staff_id !== $staffId) {
            $applicant = DB::table('staff_general')->where('staff_id', $leave->staff_id)->first();
            $applicantEmail = $applicant->email ?? null;
            if ($this->isValidEmail($applicantEmail)) {
                $applicantName = htmlspecialchars($applicant->full_name ?? ('Staff #' . $leave->staff_id));
                $this->sendHtmlMailNow(
                    $applicantEmail,
                    $applicantName,
                    "Your Leave Application Has Been Cancelled",
                    "<p>Dear <strong>{$applicantName}</strong>,</p>{$body}",
                );
            }
        }

        return response()->json(['status' => 'success', 'message' => 'Leave application cancelled successfully.']);
    }

    private function sendHtmlMailNow(
        string $to,
        string $toName,
        string $subject,
        string $body,
        array $cc = [],
    ): bool {
        if (!$this->isValidEmail($to)) {
            return false;
        }

        $cc = array_values(array_filter(
            array_unique(array_map(static fn ($email) => trim((string) $email), $cc)),
            fn ($email) => $email !== '' && strtolower($email) !== strtolower($to) && $this->isValidEmail($email),
        ));

        try {
            SendHtmlMailJob::dispatchSync($to, $toName, $subject, $body, $cc);
            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    private function sendHtmlMailToRecipients(array $recipients, string $subject, string $body): bool
    {
        $emails = $this->workflowRecipients()->emailAddresses($recipients);
        if (empty($emails)) {
            return false;
        }

        $to = array_shift($emails);
        return $this->sendHtmlMailNow($to, 'Human Resource', $subject, $body, $emails);
    }

    private function adjustAllocationUsedDays(object $leave, float $delta): void
    {
        $allocationYear = (int) date('Y', strtotime((string) $leave->start_date));

        $allocation = DB::table('hr_leaves_allocation')
            ->where('staff_id', $leave->staff_id)
            ->where('leave_type', $leave->type)
            ->where('year', $allocationYear)
            ->lockForUpdate()
            ->first(['id', 'used_days']);

        if (!$allocation) {
            return;
        }

        $nextUsedDays = max(0, (float) $allocation->used_days + $delta);

        DB::table('hr_leaves_allocation')
            ->where('id', $allocation->id)
            ->update(['used_days' => $nextUsedDays]);
    }

    private function workflowRecipients(): LeaveWorkflowRecipientService
    {
        return app(LeaveWorkflowRecipientService::class);
    }

    private function canActForLeaveStage(Request $request, string $stageKey, array $fallbackRoles): bool
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return false;
        }

        return in_array(
            $staffId,
            $this->workflowRecipients()->stageStaffIds($stageKey, $fallbackRoles),
            true,
        );
    }

    private function unauthorizedLeaveActionResponse(string $actionLabel): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => "You are not authorized to {$actionLabel}.",
        ], 403);
    }

    private function configuredOrWorkflowParticipantRecipients(
        string $stageKey,
        object $leave,
        array $fallbackStaffIds = [],
        array $excludeStaffIds = [],
    ): array {
        $configured = $this->workflowRecipients()->configuredRecipientsForStage($stageKey);
        $exclude = array_values(array_unique(array_filter(array_map('intval', $excludeStaffIds))));

        if (!empty($configured)) {
            return array_values(array_filter(
                $configured,
                fn (array $recipient): bool => !in_array((int) $recipient['staff_id'], $exclude, true),
            ));
        }

        $participantIds = [
            (int) ($leave->staff_id ?? 0),
            (int) ($leave->reviewed_by ?? 0),
            (int) ($leave->approved_by ?? 0),
            ...array_map('intval', $fallbackStaffIds),
        ];

        $participantIds = array_values(array_diff(
            array_unique(array_filter($participantIds)),
            $exclude,
        ));

        return $this->workflowRecipients()->recipientsForStaffIds($participantIds);
    }

    private function notifications(): AppNotificationService
    {
        return app(AppNotificationService::class);
    }

    private function notifyLeaveNeedsRecommendation(
        Request $request,
        int $leaveId,
        int $applicantStaffId,
        string $applicantName,
        array $data,
    ): void {
        $recipientIds = $this->workflowRecipients()->stageStaffIds(
            LeaveWorkflowRecipientService::STAGE_SUBMITTED_RECOMMENDERS,
            ['HR'],
        );
        if (empty($recipientIds)) {
            return;
        }

        $this->notifications()->createForStaff($recipientIds, [
            'recipient_staff_ids' => $recipientIds,
            'actor_staff_id' => $applicantStaffId,
            'module_key' => 'staff.leaves',
            'entity_type' => 'leave_application',
            'entity_id' => $leaveId,
            'type' => 'leave.needs_recommendation',
            'title' => 'Leave request needs recommendation',
            'message' => "{$applicantName} submitted {$data['type']} leave.",
            'route' => "/staff/leaves/records/{$leaveId}",
            'severity' => 'warning',
        ]);
    }

    private function handleLeaveActionNotifications(
        Request $request,
        object $leave,
        int $leaveId,
        string $action,
        int $actorId,
        string $applicantName,
    ): void {
        $notifications = $this->notifications();

        if ($action === 'recommend') {
            $notifications->consumeEntity(
                $request,
                'staff.leaves',
                'leave_application',
                $leaveId,
            );
            $notifications->resolveActive(
                'staff.leaves',
                'leave_application',
                $leaveId,
                ['leave.needs_recommendation'],
            );
            $notifications->createForStaff($this->workflowRecipients()->stageStaffIds(
                LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS,
                ['Manager', 'System Admin'],
            ), [
                'actor_staff_id' => $actorId,
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => $leaveId,
                'type' => 'leave.needs_approval',
                'title' => 'Leave request needs approval',
                'message' => "{$applicantName}'s leave request has been recommended.",
                'route' => "/staff/leaves/records/{$leaveId}",
                'severity' => 'warning',
            ]);
            return;
        }

        if ($action === 'approve') {
            $notifications->consumeEntity($request, 'staff.leaves', 'leave_application', $leaveId);
            $notifications->resolveActive(
                'staff.leaves',
                'leave_application',
                $leaveId,
                ['leave.needs_approval'],
            );
            $notifications->createForStaff([(int) $leave->staff_id], [
                'actor_staff_id' => $actorId,
                'module_key' => 'my.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => $leaveId,
                'type' => 'leave.approved',
                'title' => 'Leave approved',
                'message' => 'Your leave request has been approved.',
                'route' => "/my/leaves/records/{$leaveId}",
                'severity' => 'success',
            ]);

            $copyRecipientIds = array_map(
                static fn (array $recipient): int => (int) $recipient['staff_id'],
                $this->configuredOrWorkflowParticipantRecipients(
                    LeaveWorkflowRecipientService::STAGE_APPROVED_NOTIFY,
                    $leave,
                    [],
                    [(int) $leave->staff_id],
                ),
            );
            if (!empty($copyRecipientIds)) {
                $notifications->createForStaff($copyRecipientIds, [
                    'actor_staff_id' => $actorId,
                    'module_key' => 'staff.leaves',
                    'entity_type' => 'leave_application',
                    'entity_id' => $leaveId,
                    'type' => 'leave.approved.copy',
                    'title' => 'Leave approved',
                    'message' => "{$applicantName}'s leave request has been approved.",
                    'route' => "/staff/leaves/records/{$leaveId}",
                    'severity' => 'success',
                ]);
            }
            return;
        }

        if ($action === 'reject') {
            $notifications->consumeEntity($request, 'staff.leaves', 'leave_application', $leaveId);
            $notifications->resolveActive(
                'staff.leaves',
                'leave_application',
                $leaveId,
                ['leave.needs_recommendation', 'leave.needs_approval'],
            );
            $notifications->createForStaff([(int) $leave->staff_id], [
                'actor_staff_id' => $actorId,
                'module_key' => 'my.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => $leaveId,
                'type' => 'leave.rejected',
                'title' => 'Leave rejected',
                'message' => 'Your leave request has been rejected.',
                'route' => "/my/leaves/records/{$leaveId}",
                'severity' => 'danger',
            ]);

            $copyRecipientIds = array_map(
                static fn (array $recipient): int => (int) $recipient['staff_id'],
                $this->configuredOrWorkflowParticipantRecipients(
                    LeaveWorkflowRecipientService::STAGE_REJECTED_NOTIFY,
                    $leave,
                    [],
                    [(int) $leave->staff_id],
                ),
            );
            if (!empty($copyRecipientIds)) {
                $notifications->createForStaff($copyRecipientIds, [
                    'actor_staff_id' => $actorId,
                    'module_key' => 'staff.leaves',
                    'entity_type' => 'leave_application',
                    'entity_id' => $leaveId,
                    'type' => 'leave.rejected.copy',
                    'title' => 'Leave rejected',
                    'message' => "{$applicantName}'s leave request has been rejected.",
                    'route' => "/staff/leaves/records/{$leaveId}",
                    'severity' => 'danger',
                ]);
            }
        }
    }

    private function handleLeaveCancellationNotifications(
        Request $request,
        object $leave,
        int $leaveId,
        int $actorId,
        string $previousStatus,
    ): array {
        $notifications = $this->notifications();
        $activeRecipients = $notifications->resolveActive(
            'staff.leaves',
            'leave_application',
            $leaveId,
            ['leave.needs_recommendation', 'leave.needs_approval'],
        );

        if (strtolower($previousStatus) === 'approved') {
            $copyRecipientIds = array_map(
                static fn (array $recipient): int => (int) $recipient['staff_id'],
                $this->configuredOrWorkflowParticipantRecipients(
                    LeaveWorkflowRecipientService::STAGE_REVOKED_NOTIFY,
                    $leave,
                    $activeRecipients,
                    [$actorId, (int) $leave->staff_id],
                ),
            );

            if (!empty($copyRecipientIds)) {
                $notifications->createForStaff($copyRecipientIds, [
                    'actor_staff_id' => $actorId,
                    'module_key' => 'staff.leaves',
                    'entity_type' => 'leave_application',
                    'entity_id' => $leaveId,
                    'type' => 'leave.revoked.copy',
                    'title' => 'Leave revoked',
                    'message' => 'An approved leave request was revoked.',
                    'route' => "/staff/leaves/records/{$leaveId}",
                    'severity' => 'warning',
                ]);
            }

            if ((int) $leave->staff_id !== $actorId) {
                $notifications->createForStaff([(int) $leave->staff_id], [
                    'actor_staff_id' => $actorId,
                    'module_key' => 'my.leaves',
                    'entity_type' => 'leave_application',
                    'entity_id' => $leaveId,
                    'type' => 'leave.revoked',
                    'title' => 'Leave revoked',
                    'message' => 'Your approved leave has been revoked.',
                    'route' => "/my/leaves/records/{$leaveId}",
                    'severity' => 'warning',
                ]);
            }
            return $activeRecipients;
        }

        $configuredCancelIds = array_map(
            static fn (array $recipient): int => (int) $recipient['staff_id'],
            $this->configuredOrWorkflowParticipantRecipients(
                LeaveWorkflowRecipientService::STAGE_CANCELLED_NOTIFY,
                $leave,
                $activeRecipients,
                [$actorId, (int) $leave->staff_id],
            ),
        );
        $recipientIds = array_values(array_diff($configuredCancelIds, [$actorId]));
        if (!empty($recipientIds)) {
            $notifications->createForStaff($recipientIds, [
                'actor_staff_id' => $actorId,
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => $leaveId,
                'type' => 'leave.cancelled',
                'title' => 'Leave request cancelled',
                'message' => 'A pending leave request was cancelled.',
                'route' => "/staff/leaves/records/{$leaveId}",
                'severity' => 'info',
            ]);
        }

        if ((int) $leave->staff_id !== $actorId) {
            $notifications->createForStaff([(int) $leave->staff_id], [
                'actor_staff_id' => $actorId,
                'module_key' => 'my.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => $leaveId,
                'type' => 'leave.cancelled.applicant',
                'title' => 'Leave cancelled',
                'message' => 'Your leave request has been cancelled.',
                'route' => "/my/leaves/records/{$leaveId}",
                'severity' => 'info',
            ]);
        }

        return $activeRecipients;
    }
}
