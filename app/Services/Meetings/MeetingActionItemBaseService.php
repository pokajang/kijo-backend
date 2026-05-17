<?php

namespace App\Services\Meetings;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class MeetingActionItemBaseService
{
    protected function normalizeDueDate(?string $value): string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $trimmed);
        if (! $dt || $dt->format('Y-m-d') !== $trimmed) {
            return '';
        }
        return $trimmed;
    }

    protected function generateId(): string
    {
        return 'act_' . bin2hex(random_bytes(8));
    }

    protected function encode(array $items): string
    {
        $payload = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode action items.');
        }
        return $payload;
    }

    protected function pendingCount(array $items): int
    {
        return count(array_filter(
            $items,
            fn ($item) => $this->normalizeStatus((string) ($item['status'] ?? 'Pending')) !== 'Done'
        ));
    }

    protected function appendAuditLog(
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

    protected function isMeetingContentLocked(string $verificationStatus): bool
    {
        return in_array($verificationStatus, ['Verified', 'Concurred'], true);
    }

    protected function lockedMeetingContentMessage(): string
    {
        return 'Verified or concurred meeting minutes are locked. Revoke verification/concurrence before editing.';
    }

    protected function internalServerErrorResponse(\Throwable $e)
    {
        report($e);
        return response()->json(['success' => false, 'message' => 'Server error.'], 500);
    }
}
