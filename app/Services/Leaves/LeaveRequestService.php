<?php

namespace App\Services\Leaves;

use App\Http\Requests\Leave\AssignEntitlementRequest;
use App\Http\Requests\Leave\LeaveActionRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Requests\Leave\UpdateEntitlementRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\AuditLogService;
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

        $mailSent = false;
        try {
            SendHtmlMailJob::dispatch(
                'hr.amiosh@gmail.com',
                'Human Resource',
                "New Leave Application by {$applicantName}",
                $body,
                ['azam@amiosh.com'],
            );
            $mailSent = true;
        } catch (\Throwable) {
            // email is best-effort; leave creation already succeeded
        }

        return response()->json([
            'status'    => 'success',
            'message'   => 'Leave application submitted successfully.',
            'leave_id'  => $leaveId,
            'mail_sent' => $mailSent,
        ]);
    }

    public function getAllLeavesData(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->unauthorizedResponse();
        }

        $year = (int) $request->query('year', 0);
        $yearClause = ($year >= 2000 && $year <= 2100) ? 'WHERE YEAR(hla.start_date) = ?' : '';
        $bindings = ($year >= 2000 && $year <= 2100) ? [$year] : [];

        $leaves = DB::select("
            SELECT hla.*,
                   sg.full_name       AS applicant_name,
                   sg.name_code       AS applicant_code,
                   reviewer.full_name AS reviewer_name,
                   reviewer.name_code AS reviewer_code,
                   approver.full_name AS approver_name,
                   approver.name_code AS approver_code
            FROM hr_leaves_application hla
            LEFT JOIN staff_general sg       ON hla.staff_id    = sg.staff_id
            LEFT JOIN staff_general reviewer ON hla.reviewed_by = reviewer.staff_id
            LEFT JOIN staff_general approver ON hla.approved_by = approver.staff_id
            {$yearClause}
            ORDER BY hla.applied_at DESC
        ", $bindings);

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

                DB::table('hr_leaves_application')->where('id', $leaveId)->update([
                    'approved_by'      => $actorId,
                    'approved_at'      => now(),
                    'approved_status'  => 'Approved',
                    'approved_remarks' => $remarks,
                    'status'           => 'Approved',
                ]);

                // Deduct from leave allocation
                DB::table('hr_leaves_allocation')
                    ->where('staff_id', $leave->staff_id)
                    ->where('leave_type', $leave->type)
                    ->whereYear('year', date('Y', strtotime($leave->start_date)))
                    ->update([
                        'used_days' => DB::raw('used_days + ' . (float) $leave->duration_days),
                    ]);

            } elseif ($action === 'reject') {
                if (in_array($status, ['cancelled', 'rejected', 'approved'], true)) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Leave cannot be rejected in its current state.',
                    ], 422);
                }

                if (!empty($leave->reviewed_by)) {
                    // Already reviewed - reject at approver level
                    DB::table('hr_leaves_application')->where('id', $leaveId)->update([
                        'approved_by'      => $actorId,
                        'approved_at'      => now(),
                        'approved_status'  => 'Rejected',
                        'approved_remarks' => $remarks,
                        'status'           => 'Rejected',
                    ]);
                } else {
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

        // Dispatch emails asynchronously
        if ($action === 'recommend') {
            $approverBody = "
                <p>A leave application by <strong>{$applicantNameStr}</strong> has been recommended and is pending your approval.</p>
                <p><strong>Leave Type:</strong> " . htmlspecialchars((string) $leave->type) . "</p>
                <p><strong>Duration:</strong> " . htmlspecialchars((string) $leave->duration_days) . " day(s)
                   (" . htmlspecialchars((string) $leave->start_date) . " to " . htmlspecialchars((string) $leave->end_date) . ")</p>
                <p><strong>Recommended by:</strong> {$actorNameSafe}</p>
            ";
            SendHtmlMailJob::dispatch(
                'kamarul@amiosh.com',
                'Kamarul',
                "Please Approve Leave Application by {$applicantNameStr}",
                $approverBody,
            );
            SendHtmlMailJob::dispatch(
                'hr.amiosh@gmail.com',
                'Human Resource',
                "Please Approve Leave Application by {$applicantNameStr}",
                $approverBody,
            );
        }

        // Notify applicant on any action
        if ($this->isValidEmail($applicantEmail)) {
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

            if ($action === 'approve') {
                SendHtmlMailJob::dispatch(
                    $applicantEmail,
                    $applicantNameStr,
                    $subjectMap[$action],
                    $applicantBody,
                    ['kamarul@amiosh.com', 'aminrozak@amiosh.com', 'hr.amiosh@gmail.com', 'azlin@amiosh.com'],
                );
            } else {
                SendHtmlMailJob::dispatch(
                    $applicantEmail,
                    $applicantNameStr,
                    $subjectMap[$action],
                    $applicantBody,
                );
            }
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
            $leave = DB::table('hr_leaves_application')
                ->lockForUpdate()
                ->where('id', $leaveId)
                ->where('staff_id', $staffId)
                ->first();

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
                DB::table('hr_leaves_allocation')
                    ->where('staff_id', $leave->staff_id)
                    ->where('leave_type', $leave->type)
                    ->whereYear('year', date('Y', strtotime($leave->start_date)))
                    ->update([
                        'used_days' => DB::raw('GREATEST(used_days - ' . (float) $leave->duration_days . ', 0)'),
                    ]);
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'An error occurred: ' . $e->getMessage()], 500);
        }

        $this->auditLog->log($request, "Cancelled leave application #{$leaveId}");

        $nameSafe = htmlspecialchars($name);
        $body = "
            <p>A leave application has been cancelled by <strong>{$nameSafe}</strong>.</p>
            <p><strong>Leave Type:</strong> " . htmlspecialchars((string) $leave->type) . "</p>
            <p><strong>Duration:</strong> " . htmlspecialchars((string) $leave->duration_days) . " day(s)
               (" . htmlspecialchars((string) $leave->start_date) . " to " . htmlspecialchars((string) $leave->end_date) . ")</p>
        ";

        SendHtmlMailJob::dispatch(
            'hr.amiosh@gmail.com',
            'Human Resource',
            "Leave Application Cancelled by {$nameSafe}",
            $body,
            ['azam@amiosh.com'],
        );

        return response()->json(['status' => 'success', 'message' => 'Leave application cancelled successfully.']);
    }
}
