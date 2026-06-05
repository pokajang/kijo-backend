<?php

namespace App\Services\Leaves;

use App\Http\Requests\Leave\LeaveActionRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\AppNotificationService;
use App\Services\Mail\SystemEmailBodyBuilder;
use App\Services\Mail\SystemEmailUrlBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LeaveRequestService extends LeaveBaseService
{
    public function createLeave(StoreLeaveRequest $request): JsonResponse
    {
        $data = $request->validated();
        $staffId = (int) $request->session()->get('staff_id');
        $name = (string) $request->session()->get('full_name', $request->session()->get('name_code', 'Staff'));

        // Same-day time validation
        if ($data['start_date'] === $data['end_date'] && $data['start_time'] >= $data['end_time']) {
            return response()->json([
                'status' => 'error',
                'message' => 'For same-day leave, start_time must be earlier than end_time.',
            ], 422);
        }

        $leaveId = DB::table('hr_leaves_application')->insertGetId([
            'staff_id' => $staffId,
            'type' => $data['type'],
            'reason' => $data['reason'] ?? null,
            'start_date' => $data['start_date'],
            'start_time' => $data['start_time'],
            'end_date' => $data['end_date'],
            'end_time' => $data['end_time'],
            'duration_days' => $data['duration_days'],
            'status' => $data['status'],
            'applied_at' => now(),
        ]);

        $this->auditLog->log($request, "Created leave application #{$leaveId}");
        $this->notifyLeaveNeedsRecommendation($request, $leaveId, $staffId, $name, $data);

        $applicantName = $name;
        $subject = "New Leave Application by {$applicantName}";
        $body = $this->emailBody()->render([
            'intro' => 'A new leave application has been submitted in KIJO.',
            'status' => ['label' => 'Submitted', 'tone' => 'warning'],
            'detailsHeading' => 'Leave Details',
            'details' => [
                'Applicant' => $applicantName,
                'Leave Type' => $data['type'],
                'Start' => $data['start_date'].' '.$data['start_time'],
                'End' => $data['end_date'].' '.$data['end_time'],
                'Duration' => $data['duration_days'].' day(s)',
                'Reason' => $data['reason'] ?? 'N/A',
            ],
            'actionUrl' => $this->emailUrls()->frontendUrl("/staff/leaves/records/{$leaveId}"),
            'actionLabel' => 'Open in KIJO',
        ]);

        $recipients = $this->workflowRecipients()->recipientsForStage(
            LeaveWorkflowRecipientService::STAGE_SUBMITTED_RECOMMENDERS,
            ['Manager', 'System Admin'],
        );
        $mailSent = $this->sendHtmlMailToRecipients(
            $recipients,
            $subject,
            $body,
            $this->emailBody()->presentation('Leave', 'New Leave Application', 'Recommendation required', $subject),
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Leave application submitted successfully.',
            'leave_id' => $leaveId,
            'mail_sent' => $mailSent,
            'mail_message' => $mailSent
                ? 'Leave application submitted and notification email sent.'
                : 'Leave application submitted, but notification email could not be sent. Check mail settings and recipient list.',
        ]);
    }

    public function getAllLeavesData(Request $request): JsonResponse
    {
        if (! $this->isPrivileged($request)) {
            return $this->unauthorizedResponse();
        }

        $year = (int) $request->query('year', 0);

        $columns = [
                'hla.*',
                'sg.full_name as applicant_name',
                'sg.name_code as applicant_code',
                'reviewer.full_name as reviewer_name',
                'reviewer.name_code as reviewer_code',
                'approver.full_name as approver_name',
                'approver.name_code as approver_code',
                'canceller.full_name as canceller_name',
                'canceller.name_code as canceller_code',
        ];

        $columns[] = Schema::hasColumn('staff_general', 'status')
            ? 'sg.status as applicant_status'
            : DB::raw('NULL as applicant_status');

        if (Schema::hasColumn('staff_general', 'terminated_at')) {
            $columns[] = 'sg.terminated_at as applicant_terminated_at';
        }

        $leaves = DB::table('hr_leaves_application as hla')
            ->select($columns)
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

        return response()->json([
            'status' => 'success',
            'leaves' => $leaves,
            'action_permissions' => $this->leaveActionPermissions($request),
        ]);
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
        if (! $this->isPrivileged($request)) {
            return $this->unauthorizedResponse();
        }

        $data = $request->validated();
        $leaveId = (int) $data['id'];
        $action = $data['action'];
        $remarks = $data['remarks'] ?? null;
        $actorId = (int) $request->session()->get('staff_id');
        $actorName = (string) $request->session()->get('full_name', $request->session()->get('name_code', 'Staff'));

        DB::beginTransaction();
        try {
            $leave = DB::table('hr_leaves_application')
                ->lockForUpdate()
                ->where('id', $leaveId)
                ->first();

            if (! $leave) {
                DB::rollBack();

                return response()->json(['status' => 'error', 'message' => 'Leave application not found.'], 404);
            }

            $status = strtolower((string) $leave->status);

            if ($action === 'recommend') {
                if (in_array($status, ['cancelled', 'rejected', 'approved'], true)) {
                    DB::rollBack();

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Leave cannot be recommended in its current state.',
                    ], 422);
                }
                if (! empty($leave->reviewed_by)) {
                    DB::rollBack();

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Leave has already been reviewed.',
                    ], 422);
                }
                if (! $this->canActForLeaveStage(
                    $request,
                    LeaveWorkflowRecipientService::STAGE_SUBMITTED_RECOMMENDERS,
                    ['Manager', 'System Admin'],
                )) {
                    DB::rollBack();

                    return $this->unauthorizedLeaveActionResponse('recommend this leave');
                }

                DB::table('hr_leaves_application')->where('id', $leaveId)->update([
                    'reviewed_by' => $actorId,
                    'reviewed_at' => now(),
                    'reviewed_status' => 'Recommended',
                    'reviewed_remarks' => $remarks,
                ]);

            } elseif ($action === 'approve') {
                if (in_array($status, ['cancelled', 'rejected', 'approved'], true)) {
                    DB::rollBack();

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Leave cannot be approved in its current state.',
                    ], 422);
                }
                if (empty($leave->reviewed_by)) {
                    DB::rollBack();

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Leave must be reviewed/recommended before it can be approved.',
                    ], 422);
                }
                if (! $this->canActForLeaveStage(
                    $request,
                    LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS,
                    ['HR', 'System Admin'],
                )) {
                    DB::rollBack();

                    return $this->unauthorizedLeaveActionResponse('approve this leave');
                }

                DB::table('hr_leaves_application')->where('id', $leaveId)->update([
                    'approved_by' => $actorId,
                    'approved_at' => now(),
                    'approved_status' => 'Approved',
                    'approved_remarks' => $remarks,
                    'status' => 'Approved',
                ]);

                $this->adjustAllocationUsedDays($leave, (float) $leave->duration_days);

            } elseif ($action === 'reject') {
                if (in_array($status, ['cancelled', 'rejected', 'approved'], true)) {
                    DB::rollBack();

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Leave cannot be rejected in its current state.',
                    ], 422);
                }

                if (! empty($leave->reviewed_by)) {
                    if (! $this->canActForLeaveStage(
                        $request,
                        LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS,
                        ['HR', 'System Admin'],
                    )) {
                        DB::rollBack();

                        return $this->unauthorizedLeaveActionResponse('reject this reviewed leave');
                    }

                    // Already reviewed - reject at approver level
                    DB::table('hr_leaves_application')->where('id', $leaveId)->update([
                        'approved_by' => $actorId,
                        'approved_at' => now(),
                        'approved_status' => 'Rejected',
                        'approved_remarks' => $remarks,
                        'status' => 'Rejected',
                    ]);
                } else {
                    if (! $this->canActForLeaveStage(
                        $request,
                        LeaveWorkflowRecipientService::STAGE_SUBMITTED_RECOMMENDERS,
                        ['Manager', 'System Admin'],
                    )) {
                        DB::rollBack();

                        return $this->unauthorizedLeaveActionResponse('reject this unreviewed leave');
                    }

                    // Not yet reviewed - reject at reviewer level
                    DB::table('hr_leaves_application')->where('id', $leaveId)->update([
                        'reviewed_by' => $actorId,
                        'reviewed_at' => now(),
                        'reviewed_status' => 'Rejected',
                        'reviewed_remarks' => $remarks,
                        'status' => 'Rejected',
                    ]);
                }
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['status' => 'error', 'message' => 'An error occurred: '.$e->getMessage()], 500);
        }

        $this->auditLog->log($request, "Leave action '{$action}' performed on leave #{$leaveId}");

        // Fetch applicant email for notification
        $applicant = DB::table('staff_general')->where('staff_id', $leave->staff_id)->first();
        $applicantEmail = $applicant->email ?? null;
        $applicantNameStr = (string) ($applicant->full_name ?? ('Staff #'.$leave->staff_id));
        $actorNameSafe = $actorName;

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
            $approverSubject = "Please Approve Leave Application by {$applicantNameStr}";
            $approverBody = $this->emailBody()->render([
                'intro' => "A leave application by {$applicantNameStr} has been recommended and is pending your approval.",
                'status' => ['label' => 'Pending Approval', 'tone' => 'warning'],
                'detailsHeading' => 'Leave Details',
                'details' => [
                    'Applicant' => $applicantNameStr,
                    'Leave Type' => (string) $leave->type,
                    'Duration' => (string) $leave->duration_days.' day(s) ('.(string) $leave->start_date.' to '.(string) $leave->end_date.')',
                    'Recommended by' => $actorNameSafe,
                ],
                'actionUrl' => $this->emailUrls()->frontendUrl("/staff/leaves/records/{$leaveId}"),
                'actionLabel' => 'Open in KIJO',
            ]);
            $this->sendHtmlMailToRecipients(
                $this->workflowRecipients()->recipientsForStage(
                    LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS,
                    ['HR', 'System Admin'],
                ),
                $approverSubject,
                $approverBody,
                $this->emailBody()->presentation('Leave', 'Leave Application Pending Approval', 'Approval required', $approverSubject),
            );
        }

        $subjectMap = [
            'recommend' => 'Your Leave Application Has Been Recommended',
            'approve' => 'Your Leave Application Has Been Approved',
            'reject' => 'Your Leave Application Has Been Rejected',
        ];
        $copySubjectMap = [
            'approve' => "Leave Application by {$applicantNameStr} Has Been Approved",
            'reject' => "Leave Application by {$applicantNameStr} Has Been Rejected",
        ];
        $actionLabel = match ($action) {
            'recommend' => 'Recommended',
            'approve' => 'Approved',
            'reject' => 'Rejected',
            default => ucfirst($action).'d',
        };
        $applicantBody = $this->leaveActionEmailBody(
            "Dear {$applicantNameStr},",
            "Your leave application has been {$actionLabel}.",
            $leave,
            $actionLabel,
            $actorNameSafe,
            $this->emailUrls()->frontendUrl("/my/leaves/records/{$leaveId}"),
        );
        $copyBody = $this->leaveActionEmailBody(
            "A leave application by {$applicantNameStr} has been {$actionLabel}.",
            null,
            $leave,
            $actionLabel,
            $actorNameSafe,
            $this->emailUrls()->frontendUrl("/staff/leaves/records/{$leaveId}"),
        );
        $actionPresentation = $this->emailBody()->presentation('Leave', $subjectMap[$action], 'Workflow update', $subjectMap[$action]);

        // Notify applicant on any action
        if ($this->isValidEmail($applicantEmail)) {
            $this->sendHtmlMailNow(
                $applicantEmail,
                $applicantNameStr,
                $subjectMap[$action],
                $applicantBody,
                [],
                $actionPresentation,
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
            $copySubject = $copySubjectMap[$action];
            $copyPresentation = $this->emailBody()->presentation('Leave', $copySubject, 'Workflow update', $copySubject);
            $this->sendHtmlMailToRecipients(
                $stageRecipients,
                $copySubject,
                $copyBody,
                $copyPresentation,
            );
        }

        return response()->json(['status' => 'success', 'message' => 'Leave action processed successfully.']);
    }

    public function cancelLeave(Request $request): JsonResponse
    {
        $request->validate(['id' => ['required', 'integer', 'min:1']]);

        $staffId = (int) $request->session()->get('staff_id');
        $name = (string) $request->session()->get('full_name', $request->session()->get('name_code', 'Staff'));
        $leaveId = (int) $request->input('id');

        DB::beginTransaction();
        try {
            $leaveQuery = DB::table('hr_leaves_application')
                ->lockForUpdate()
                ->where('id', $leaveId);

            if (! $this->isPrivileged($request)) {
                $leaveQuery->where('staff_id', $staffId);
            }

            $leave = $leaveQuery->first();

            if (! $leave) {
                DB::rollBack();

                return response()->json([
                    'status' => 'error',
                    'message' => 'Leave application not found or you do not have permission to cancel it.',
                ], 403);
            }

            $previousStatus = (string) $leave->status;
            if (strtolower($previousStatus) === 'cancelled') {
                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Leave application is already cancelled.',
                ]);
            }

            DB::table('hr_leaves_application')->where('id', $leaveId)->update([
                'status' => 'Cancelled',
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

            return response()->json(['status' => 'error', 'message' => 'An error occurred: '.$e->getMessage()], 500);
        }

        $this->auditLog->log($request, "Cancelled leave application #{$leaveId}");
        $activeRecipientIds = $this->handleLeaveCancellationNotifications($request, $leave, $leaveId, $staffId, $previousStatus);

        $nameSafe = $name;

        $isRevokedApprovedLeave = strtolower($previousStatus) === 'approved';
        $statusLabel = $isRevokedApprovedLeave ? 'Revoked' : 'Cancelled';
        $body = $this->emailBody()->render([
            'intro' => "A leave application has been {$statusLabel} by {$nameSafe}.",
            'status' => ['label' => $statusLabel, 'tone' => 'warning'],
            'detailsHeading' => 'Leave Details',
            'details' => [
                'Leave Type' => (string) $leave->type,
                'Duration' => (string) $leave->duration_days.' day(s) ('.(string) $leave->start_date.' to '.(string) $leave->end_date.')',
                'Action by' => $nameSafe,
            ],
            'actionUrl' => $this->emailUrls()->frontendUrl("/staff/leaves/records/{$leaveId}"),
            'actionLabel' => 'Open in KIJO',
        ]);
        $stageKey = $isRevokedApprovedLeave
            ? LeaveWorkflowRecipientService::STAGE_REVOKED_NOTIFY
            : LeaveWorkflowRecipientService::STAGE_CANCELLED_NOTIFY;
        $stageRecipients = $this->configuredOrWorkflowParticipantRecipients(
            $stageKey,
            $leave,
            $activeRecipientIds,
            [(int) $leave->staff_id],
        );
        $stageSubject = $isRevokedApprovedLeave
            ? "Leave Application Revoked by {$nameSafe}"
            : "Leave Application Cancelled by {$nameSafe}";
        $stagePresentation = $this->emailBody()->presentation('Leave', $stageSubject, 'Workflow update', $stageSubject);
        $this->sendHtmlMailToRecipients(
            $stageRecipients,
            $stageSubject,
            $body,
            $stagePresentation,
        );

        if ($isRevokedApprovedLeave && (int) $leave->staff_id !== $staffId) {
            $applicant = DB::table('staff_general')->where('staff_id', $leave->staff_id)->first();
            $applicantEmail = $applicant->email ?? null;
            if ($this->isValidEmail($applicantEmail)) {
                $applicantName = (string) ($applicant->full_name ?? ('Staff #'.$leave->staff_id));
                $this->sendHtmlMailNow(
                    $applicantEmail,
                    $applicantName,
                    'Your Leave Application Has Been Revoked',
                    $this->leaveCancellationApplicantBody($applicantName, $leave, $nameSafe, 'Revoked', $this->emailUrls()->frontendUrl("/my/leaves/records/{$leaveId}")),
                    [],
                    $this->emailBody()->presentation('Leave', 'Your Leave Application Has Been Revoked', 'Workflow update', 'Your Leave Application Has Been Revoked'),
                );
            }
        }

        if (! $isRevokedApprovedLeave && (int) $leave->staff_id !== $staffId) {
            $applicant = DB::table('staff_general')->where('staff_id', $leave->staff_id)->first();
            $applicantEmail = $applicant->email ?? null;
            if ($this->isValidEmail($applicantEmail)) {
                $applicantName = (string) ($applicant->full_name ?? ('Staff #'.$leave->staff_id));
                $this->sendHtmlMailNow(
                    $applicantEmail,
                    $applicantName,
                    'Your Leave Application Has Been Cancelled',
                    $this->leaveCancellationApplicantBody($applicantName, $leave, $nameSafe, 'Cancelled', $this->emailUrls()->frontendUrl("/my/leaves/records/{$leaveId}")),
                    [],
                    $this->emailBody()->presentation('Leave', 'Your Leave Application Has Been Cancelled', 'Workflow update', 'Your Leave Application Has Been Cancelled'),
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
        array $presentation = [],
    ): bool {
        if (! $this->isValidEmail($to)) {
            return false;
        }

        $cc = array_values(array_filter(
            array_unique(array_map(static fn ($email) => trim((string) $email), $cc)),
            fn ($email) => $email !== '' && strtolower($email) !== strtolower($to) && $this->isValidEmail($email),
        ));

        try {
            SendHtmlMailJob::dispatchSync($to, $toName, $subject, $body, $cc, null, null, $presentation);

            Log::info('Leave notification email sent.', [
                'subject' => $subject,
                'recipient_count' => 1 + count($cc),
                'success' => true,
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            Log::warning('Leave notification email failed.', [
                'subject' => $subject,
                'recipient_count' => 1 + count($cc),
                'success' => false,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sendHtmlMailToRecipients(array $recipients, string $subject, string $body, array $presentation = []): bool
    {
        $sent = false;
        $seen = [];
        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            $key = strtolower($email);
            if ($email === '' || isset($seen[$key]) || ! $this->isValidEmail($email)) {
                continue;
            }
            $seen[$key] = true;

            $recipientName = trim((string) ($recipient['full_name'] ?? ''));
            if ($recipientName === '') {
                $recipientName = trim((string) ($recipient['name_code'] ?? ''));
            }
            if ($recipientName === '') {
                $recipientName = 'KIJO User';
            }

            $sent = $this->sendHtmlMailNow($email, $recipientName, $subject, $body, [], $presentation) || $sent;
        }

        return $sent;
    }

    private function leaveActionEmailBody(
        string $firstIntro,
        ?string $secondIntro,
        object $leave,
        string $statusLabel,
        string $actorName,
        string $actionUrl,
    ): string {
        return $this->emailBody()->render([
            'intro' => array_values(array_filter([$firstIntro, $secondIntro])),
            'status' => ['label' => $statusLabel, 'tone' => $statusLabel === 'Rejected' ? 'danger' : 'success'],
            'detailsHeading' => 'Leave Details',
            'details' => [
                'Leave Type' => (string) $leave->type,
                'Duration' => (string) $leave->duration_days.' day(s) ('.(string) $leave->start_date.' to '.(string) $leave->end_date.')',
                'Action by' => $actorName,
            ],
            'actionUrl' => $actionUrl,
            'actionLabel' => 'Open in KIJO',
        ]);
    }

    private function leaveCancellationApplicantBody(
        string $applicantName,
        object $leave,
        string $actorName,
        string $statusLabel,
        string $actionUrl,
    ): string {
        return $this->emailBody()->render([
            'intro' => [
                "Dear {$applicantName},",
                "Your leave application has been {$statusLabel}.",
            ],
            'status' => ['label' => $statusLabel, 'tone' => 'warning'],
            'detailsHeading' => 'Leave Details',
            'details' => [
                'Leave Type' => (string) $leave->type,
                'Duration' => (string) $leave->duration_days.' day(s) ('.(string) $leave->start_date.' to '.(string) $leave->end_date.')',
                'Action by' => $actorName,
            ],
            'actionUrl' => $actionUrl,
            'actionLabel' => 'Open in KIJO',
        ]);
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

        if (! $allocation) {
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

    private function emailBody(): SystemEmailBodyBuilder
    {
        return app(SystemEmailBodyBuilder::class);
    }

    private function emailUrls(): SystemEmailUrlBuilder
    {
        return app(SystemEmailUrlBuilder::class);
    }

    private function canActForLeaveStage(Request $request, string $stageKey, array $fallbackRoles): bool
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return false;
        }
        if ($this->hasAnyRole($request, ['System Admin'])) {
            return true;
        }

        return in_array(
            $staffId,
            $this->workflowRecipients()->stageStaffIds($stageKey, $fallbackRoles),
            true,
        );
    }

    private function leaveActionPermissions(Request $request): array
    {
        return [
            'can_recommend' => $this->canActForLeaveStage(
                $request,
                LeaveWorkflowRecipientService::STAGE_SUBMITTED_RECOMMENDERS,
                ['Manager', 'System Admin'],
            ),
            'can_approve' => $this->canActForLeaveStage(
                $request,
                LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS,
                ['HR', 'System Admin'],
            ),
        ];
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

        if (! empty($configured)) {
            return array_values(array_filter(
                $configured,
                fn (array $recipient): bool => ! in_array((int) $recipient['staff_id'], $exclude, true),
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
            ['Manager', 'System Admin'],
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
                ['HR', 'System Admin'],
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
            if (! empty($copyRecipientIds)) {
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
                LeaveNotificationType::ACTION,
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
            if (! empty($copyRecipientIds)) {
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
            LeaveNotificationType::ACTION,
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

            if (! empty($copyRecipientIds)) {
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
        if (! empty($recipientIds)) {
            $notifications->createForStaff($recipientIds, [
                'actor_staff_id' => $actorId,
                'module_key' => 'staff.leaves',
                'entity_type' => 'leave_application',
                'entity_id' => $leaveId,
                    'type' => 'leave.cancelled',
                    'title' => 'Leave request cancelled',
                    'message' => 'A leave request was cancelled.',
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
