<?php

namespace App\Services\Meetings;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingVerificationService
{
    public function update(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $staffName = trim((string) $request->session()->get('full_name', ''));
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        if ($staffId <= 0 || $staffName === '' || $staffCode === '') {
            return response()->json(['success' => false, 'message' => 'No session'], 401);
        }

        $roles = $this->sessionRoles($request);
        if (! $this->hasPrivilegedApprovalRole($roles)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to update meeting verification.'], 403);
        }

        $meetingId = (int) $request->input('meeting_id', 0);
        $action = strtolower(trim((string) $request->input('action', '')));
        $commentText = trim((string) $request->input('comment_text', ''));
        $reason = trim((string) $request->input('reason', ''));

        if ($meetingId <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid meeting id.'], 400);
        }
        if (! in_array($action, ['verify', 'concur', 'unverify', 'unconcur', 'comment'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid action.'], 400);
        }
        if ($action === 'comment' && $commentText === '') {
            return response()->json(['success' => false, 'message' => 'Comment is required.'], 400);
        }
        if (($action === 'unverify' || $action === 'unconcur') && $reason === '') {
            return response()->json(['success' => false, 'message' => 'Reason is required.'], 400);
        }

        DB::beginTransaction();
        try {
            $meeting = DB::table('meeting_minutes')
                ->select(['id', 'record_status', 'verification_status', 'meeting_title', 'verified_by', 'concurred_by'])
                ->where('id', $meetingId)
                ->lockForUpdate()
                ->first();
            if (! $meeting) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Meeting record not found.'], 404);
            }
            if ((string) ($meeting->record_status ?? 'Complete') !== 'Complete') {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Finalize meeting minutes before verification.'], 400);
            }

            $currentStatus = trim((string) ($meeting->verification_status ?? 'Pending'));
            if ($currentStatus === '') {
                $currentStatus = 'Pending';
            }
            $currentVerifiedBy = (int) ($meeting->verified_by ?? 0);
            $currentConcurredBy = (int) ($meeting->concurred_by ?? 0);
            $nextStatus = $currentStatus;
            $auditAction = 'ADD_VERIFICATION_COMMENT';
            $auditSummary = 'Approval comment added';

            if ($action === 'verify') {
                if ($currentVerifiedBy > 0) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Meeting already verified. Use unverify first if needed.'], 400);
                }
                if ($currentConcurredBy > 0) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Meeting already concurred. Use unconcur first if needed.'], 400);
                }
                $nextStatus = 'Verified';
                $auditAction = 'VERIFY_MINUTES';
                $auditSummary = 'Meeting minutes verified';
                DB::table('meeting_minutes')->where('id', $meetingId)->update([
                    'verification_status' => $nextStatus,
                    'verified_by' => $staffId,
                    'verified_name' => $staffName,
                    'verified_code' => $staffCode,
                    'verified_at' => now(),
                    'concurred_by' => null,
                    'concurred_name' => null,
                    'concurred_code' => null,
                    'concurred_at' => null,
                    'updated_by' => $staffId,
                    'updated_name' => $staffName,
                    'updated_code' => $staffCode,
                    'updated_at' => now(),
                ]);
            } elseif ($action === 'concur') {
                if ($currentVerifiedBy <= 0) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Meeting must be verified before concurrence.'], 400);
                }
                if ($currentVerifiedBy === $staffId) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Verifier and concurer must be different users.'], 400);
                }
                if ($currentConcurredBy > 0) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Minutes already concurred.'], 400);
                }
                $nextStatus = 'Concurred';
                $auditAction = 'CONCUR_MINUTES';
                $auditSummary = 'Meeting minutes concurred';
                DB::table('meeting_minutes')->where('id', $meetingId)->update([
                    'verification_status' => $nextStatus,
                    'concurred_by' => $staffId,
                    'concurred_name' => $staffName,
                    'concurred_code' => $staffCode,
                    'concurred_at' => now(),
                    'updated_by' => $staffId,
                    'updated_name' => $staffName,
                    'updated_code' => $staffCode,
                    'updated_at' => now(),
                ]);
            } elseif ($action === 'unverify') {
                if ($currentVerifiedBy <= 0) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Meeting is not verified.'], 400);
                }
                if ($currentConcurredBy > 0) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Meeting is concurred. Please unconcur first.'], 400);
                }
                $nextStatus = 'Pending';
                $auditAction = 'UNVERIFY_MINUTES';
                $auditSummary = 'Meeting verification revoked. Reason: ' . substr($reason, 0, 200);
                DB::table('meeting_minutes')->where('id', $meetingId)->update([
                    'verification_status' => $nextStatus,
                    'verified_by' => null,
                    'verified_name' => null,
                    'verified_code' => null,
                    'verified_at' => null,
                    'updated_by' => $staffId,
                    'updated_name' => $staffName,
                    'updated_code' => $staffCode,
                    'updated_at' => now(),
                ]);
            } elseif ($action === 'unconcur') {
                if ($currentConcurredBy <= 0) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Meeting is not concurred.'], 400);
                }
                $nextStatus = $currentVerifiedBy > 0 ? 'Verified' : 'Pending';
                $auditAction = 'UNCONCUR_MINUTES';
                $auditSummary = 'Meeting concurrence revoked. Reason: ' . substr($reason, 0, 200);
                DB::table('meeting_minutes')->where('id', $meetingId)->update([
                    'verification_status' => $nextStatus,
                    'concurred_by' => null,
                    'concurred_name' => null,
                    'concurred_code' => null,
                    'concurred_at' => null,
                    'updated_by' => $staffId,
                    'updated_name' => $staffName,
                    'updated_code' => $staffCode,
                    'updated_at' => now(),
                ]);
            }

            if ($commentText !== '') {
                DB::table('meeting_minute_comments')->insert([
                    'meeting_id' => $meetingId,
                    'comment_type' => $this->resolveMeetingCommentType($roles),
                    'comment_text' => $commentText,
                    'actor_id' => $staffId,
                    'actor_name' => $staffName,
                    'actor_code' => $staffCode,
                    'created_at' => now(),
                ]);
                if ($action === 'comment') {
                    $auditSummary = 'Approval comment added: ' . substr($commentText, 0, 150);
                } else {
                    $auditSummary .= ' with comment';
                }
            }

            $changedFields = $action === 'comment' ? [] : ['verification_status'];
            $this->appendAuditLog($meetingId, $auditAction, $auditSummary, $changedFields, $staffId, $staffName, $staffCode);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->internalServerErrorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => match ($action) {
                'verify' => 'Meeting minutes verified successfully.',
                'concur' => 'Meeting minutes concurred successfully.',
                'unverify' => 'Meeting verification revoked successfully.',
                'unconcur' => 'Meeting concurrence revoked successfully.',
                default => 'Comment added successfully.',
            },
            'meeting_id' => $meetingId,
            'verification_status' => $nextStatus,
        ]);
    }

    private function sessionRoles(Request $request): array
    {
        $roles = $request->session()->get('roles', []);
        if (! is_array($roles)) {
            $roles = $roles ? [$roles] : [];
        }
        return array_values(array_filter(array_map(
            static fn ($role) => trim((string) $role),
            $roles
        )));
    }

    private function hasPrivilegedApprovalRole(array $roles): bool
    {
        foreach ($roles as $role) {
            $roleText = strtolower(trim((string) $role));
            if (
                str_contains($roleText, 'manager') ||
                str_contains($roleText, 'hr') ||
                str_contains($roleText, 'admin') ||
                str_contains($roleText, 'super')
            ) {
                return true;
            }
        }
        return false;
    }

    private function resolveMeetingCommentType(array $roles): string
    {
        foreach ($roles as $role) {
            if (str_contains(strtolower((string) $role), 'hr')) {
                return 'HR';
            }
        }
        foreach ($roles as $role) {
            if (str_contains(strtolower((string) $role), 'manager')) {
                return 'Manager';
            }
        }
        return 'General';
    }

    private function appendAuditLog(
        int $meetingId,
        string $actionType,
        string $summary,
        array $changedFields,
        int $actorId,
        string $actorName,
        string $actorCode
    ): void {
        $fieldLabelMap = [
            'meeting_title' => 'Meeting Title',
            'meeting_type' => 'Meeting Type',
            'meeting_datetime' => 'Meeting Date & Time',
            'venue' => 'Venue',
            'attendees' => 'Tick Attendees',
            'guest_attendees_text' => 'Guest Attendees (Optional)',
            'agenda' => 'Agenda',
            'minutes_text' => 'Minutes (Required)',
            'action_items' => 'Action Items',
            'verification_status' => 'Verification Status',
            'record_status' => 'Record Status',
            'attachment' => 'Attachment',
        ];
        $normalized = [];
        foreach ($changedFields as $field) {
            $key = trim((string) $field);
            if ($key === '') {
                continue;
            }
            $normalized[] = $fieldLabelMap[$key] ?? $key;
        }
        $payload = json_encode(array_values(array_unique($normalized)), JSON_UNESCAPED_UNICODE);
        DB::table('meeting_minute_audit_logs')->insert([
            'meeting_id' => $meetingId,
            'action_type' => $actionType,
            'action_summary' => $summary,
            'changed_fields' => $payload === false ? '[]' : $payload,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'actor_code' => $actorCode,
            'created_at' => now(),
        ]);
    }

    private function internalServerErrorResponse(\Throwable $e)
    {
        report($e);
        return response()->json(['success' => false, 'message' => 'Server error.'], 500);
    }
}
