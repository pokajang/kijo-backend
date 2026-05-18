<?php

namespace App\Services\Meetings;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingService
{
    public function __construct(
        private MeetingActionItemService $actionItems,
        private MeetingAttachmentService $attachments
    ) {
    }

    public function store(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $staffName = trim((string) $request->session()->get('full_name', ''));
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        if ($staffId <= 0 || $staffName === '' || $staffCode === '') {
            return response()->json(['success' => false, 'message' => 'No session'], 401);
        }

        [$payload, $error] = $this->validatePayload($request);
        if ($error !== null) {
            return response()->json(['success' => false, 'message' => $error], 400);
        }

        $storedAttachment = $this->attachments->store($request);
        if (isset($storedAttachment['error'])) {
            return response()->json(['success' => false, 'message' => $storedAttachment['error']], 400);
        }

        DB::beginTransaction();
        try {
            $attendees = $this->resolveAttendees($payload['attendee_ids']);
            if (count($attendees) !== count($payload['attendee_ids'])) {
                DB::rollBack();
                $this->attachments->deleteAbsoluteFile($storedAttachment['absolute_path'] ?? '');
                return response()->json(['success' => false, 'message' => 'One or more selected attendees are invalid.'], 400);
            }

            $normalizedActionItems = $payload['form_stage'] === 'notes'
                ? $this->actionItems->normalizeForStorage($payload['action_items'], $staffId, $staffName, $staffCode)
                : '';

            $existingDraft = null;
            if ($payload['form_stage'] === 'details' && $payload['draft_key'] !== '') {
                $existingDraft = DB::table('meeting_minutes')
                    ->where('created_by', $staffId)
                    ->where('draft_key', $payload['draft_key'])
                    ->where('record_status', 'Draft')
                    ->lockForUpdate()
                    ->first();
            }

            if ($existingDraft) {
                $meetingId = (int) $existingDraft->id;
                $updates = [
                    'meeting_title' => $payload['meeting_title'],
                    'meeting_type' => $payload['meeting_type'],
                    'meeting_datetime' => $payload['meeting_datetime'],
                    'venue' => $payload['venue'] !== '' ? $payload['venue'] : null,
                    'guest_attendees_text' => $payload['guest_attendees_text'] !== '' ? $payload['guest_attendees_text'] : null,
                    'agenda' => $payload['agenda'] !== '' ? $payload['agenda'] : null,
                    'record_status' => 'Draft',
                    'updated_by' => $staffId,
                    'updated_name' => $staffName,
                    'updated_code' => $staffCode,
                    'updated_at' => now(),
                ];
                if (! empty($storedAttachment['public_path'])) {
                    $updates['attachment_path'] = $storedAttachment['public_path'];
                    $updates['attachment_name'] = $storedAttachment['original_name'];
                    $updates['attachment_size'] = $storedAttachment['size'];
                    $updates['attachment_mime'] = $storedAttachment['mime'];
                }
                DB::table('meeting_minutes')->where('id', $meetingId)->update($updates);
                DB::table('meeting_minute_attendees')->where('meeting_id', $meetingId)->delete();
                $this->insertAttendees($meetingId, $attendees);
                $changedFields = ['meeting_title', 'meeting_type', 'meeting_datetime', 'venue', 'attendees'];
                if (! empty($storedAttachment['public_path'])) {
                    $changedFields[] = 'attachment';
                }
                $this->appendAuditLog(
                    $meetingId,
                    'UPDATE_DRAFT',
                    'Meeting draft details updated',
                    $changedFields,
                    $staffId,
                    $staffName,
                    $staffCode
                );
                DB::commit();
                if (! empty($storedAttachment['public_path'])) {
                    $this->attachments->deletePublicPath((string) ($existingDraft->attachment_path ?? ''));
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Meeting draft updated successfully.',
                    'id' => $meetingId,
                    'stage' => $payload['form_stage'],
                    'record_status' => 'Draft',
                    'is_draft' => true,
                    'draft_key' => $payload['draft_key'],
                ]);
            }

            $meetingId = (int) DB::table('meeting_minutes')->insertGetId([
                'meeting_title' => $payload['meeting_title'],
                'meeting_type' => $payload['meeting_type'],
                'meeting_datetime' => $payload['meeting_datetime'],
                'venue' => $payload['venue'] !== '' ? $payload['venue'] : null,
                'guest_attendees_text' => $payload['guest_attendees_text'] !== '' ? $payload['guest_attendees_text'] : null,
                'agenda' => $payload['agenda'] !== '' ? $payload['agenda'] : null,
                'minutes_text' => $payload['form_stage'] === 'details' ? '' : $payload['minutes_text'],
                'action_items' => $normalizedActionItems !== '' ? $normalizedActionItems : null,
                'attachment_path' => $storedAttachment['public_path'] ?? null,
                'attachment_name' => $storedAttachment['original_name'] ?? null,
                'attachment_size' => $storedAttachment['size'] ?? null,
                'attachment_mime' => $storedAttachment['mime'] ?? null,
                'created_by' => $staffId,
                'created_name' => $staffName,
                'created_code' => $staffCode,
                'updated_by' => $staffId,
                'updated_name' => $staffName,
                'updated_code' => $staffCode,
                'record_status' => $payload['form_stage'] === 'details' ? 'Draft' : 'Complete',
                'draft_key' => $payload['form_stage'] === 'details' && $payload['draft_key'] !== '' ? $payload['draft_key'] : null,
                'created_at' => now(),
            ]);

            $this->insertAttendees($meetingId, $attendees);

            $changedFields = ['meeting_title', 'meeting_type', 'meeting_datetime', 'venue', 'attendees'];
            if ($payload['guest_attendees_text'] !== '') {
                $changedFields[] = 'guest_attendees_text';
            }
            if ($payload['agenda'] !== '') {
                $changedFields[] = 'agenda';
            }
            if ($payload['minutes_text'] !== '') {
                $changedFields[] = 'minutes_text';
            }
            if ($normalizedActionItems !== '') {
                $changedFields[] = 'action_items';
            }
            if (! empty($storedAttachment['public_path'])) {
                $changedFields[] = 'attachment';
            }

            $this->appendAuditLog(
                $meetingId,
                $payload['form_stage'] === 'details' ? 'CREATE_DRAFT' : 'CREATE',
                $payload['form_stage'] === 'details' ? 'Meeting draft created' : 'Meeting minute created',
                $changedFields,
                $staffId,
                $staffName,
                $staffCode
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->attachments->deleteAbsoluteFile($storedAttachment['absolute_path'] ?? '');
            return $this->internalServerErrorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => $payload['form_stage'] === 'details'
                ? 'Meeting details saved successfully.'
                : 'Meeting minutes saved successfully.',
            'id' => $meetingId,
            'stage' => $payload['form_stage'],
            'record_status' => $payload['form_stage'] === 'details' ? 'Draft' : 'Complete',
            'is_draft' => $payload['form_stage'] === 'details',
            'draft_key' => $payload['form_stage'] === 'details' ? $payload['draft_key'] : '',
        ]);
    }

    public function update(Request $request, int $id)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $staffName = trim((string) $request->session()->get('full_name', ''));
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        if ($staffId <= 0 || $staffName === '' || $staffCode === '') {
            return response()->json(['success' => false, 'message' => 'No session'], 401);
        }
        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid meeting id.'], 400);
        }

        [$payload, $error] = $this->validatePayload($request);
        if ($error !== null) {
            return response()->json(['success' => false, 'message' => $error], 400);
        }

        $storedAttachment = $this->attachments->store($request);
        if (isset($storedAttachment['error'])) {
            return response()->json(['success' => false, 'message' => $storedAttachment['error']], 400);
        }

        DB::beginTransaction();
        try {
            $existing = DB::table('meeting_minutes')->where('id', $id)->lockForUpdate()->first();
            if (! $existing) {
                DB::rollBack();
                $this->attachments->deleteAbsoluteFile($storedAttachment['absolute_path'] ?? '');
                return response()->json(['success' => false, 'message' => 'Meeting record not found.'], 404);
            }
            if ((string) ($existing->record_status ?? 'Complete') === 'Discarded') {
                DB::rollBack();
                $this->attachments->deleteAbsoluteFile($storedAttachment['absolute_path'] ?? '');
                return response()->json(['success' => false, 'message' => 'Meeting draft was discarded.'], 404);
            }
            if ((int) $existing->created_by !== $staffId) {
                DB::rollBack();
                $this->attachments->deleteAbsoluteFile($storedAttachment['absolute_path'] ?? '');
                return response()->json(['success' => false, 'message' => 'You are not allowed to edit this meeting record.'], 403);
            }
            if ($this->isContentLocked((string) ($existing->verification_status ?? 'Pending'))) {
                DB::rollBack();
                $this->attachments->deleteAbsoluteFile($storedAttachment['absolute_path'] ?? '');
                return response()->json(['success' => false, 'message' => $this->lockedContentMessage()], 400);
            }
            $wasDraft = (string) ($existing->record_status ?? 'Complete') === 'Draft';

            $oldAttendeeIds = DB::table('meeting_minute_attendees')
                ->select('staff_id')
                ->where('meeting_id', $id)
                ->pluck('staff_id')
                ->map(fn ($v) => (int) $v)
                ->all();

            $attendees = $this->resolveAttendees($payload['attendee_ids']);
            if (count($attendees) !== count($payload['attendee_ids'])) {
                DB::rollBack();
                $this->attachments->deleteAbsoluteFile($storedAttachment['absolute_path'] ?? '');
                return response()->json(['success' => false, 'message' => 'One or more selected attendees are invalid.'], 400);
            }

            $resolvedAgenda = $payload['form_stage'] === 'details' ? (string) ($existing->agenda ?? '') : $payload['agenda'];
            $resolvedGuestAttendees = $payload['form_stage'] === 'details'
                ? $payload['guest_attendees_text']
                : (string) ($existing->guest_attendees_text ?? '');
            $resolvedMinutes = $payload['form_stage'] === 'details' ? (string) ($existing->minutes_text ?? '') : $payload['minutes_text'];
            $resolvedActionItems = $payload['form_stage'] === 'details'
                ? (string) ($existing->action_items ?? '')
                : $this->actionItems->normalizeForStorage($payload['action_items'], $staffId, $staffName, $staffCode);

            $updates = [
                'meeting_title' => $payload['meeting_title'],
                'meeting_type' => $payload['meeting_type'],
                'meeting_datetime' => $payload['meeting_datetime'],
                'venue' => $payload['venue'] !== '' ? $payload['venue'] : null,
                'guest_attendees_text' => $resolvedGuestAttendees !== '' ? $resolvedGuestAttendees : null,
                'agenda' => $resolvedAgenda !== '' ? $resolvedAgenda : null,
                'minutes_text' => $resolvedMinutes,
                'action_items' => $resolvedActionItems !== '' ? $resolvedActionItems : null,
                'record_status' => $payload['form_stage'] === 'notes' ? 'Complete' : ($wasDraft ? 'Draft' : 'Complete'),
                'draft_key' => $payload['form_stage'] === 'notes'
                    ? null
                    : ($wasDraft && $payload['draft_key'] !== ''
                    ? $payload['draft_key']
                    : ($existing->draft_key ?? null)),
                'updated_by' => $staffId,
                'updated_name' => $staffName,
                'updated_code' => $staffCode,
                'updated_at' => now(),
            ];
            if (! empty($storedAttachment['public_path'])) {
                $updates['attachment_path'] = $storedAttachment['public_path'];
                $updates['attachment_name'] = $storedAttachment['original_name'];
                $updates['attachment_size'] = $storedAttachment['size'];
                $updates['attachment_mime'] = $storedAttachment['mime'];
            }

            DB::table('meeting_minutes')->where('id', $id)->update($updates);
            DB::table('meeting_minute_attendees')->where('meeting_id', $id)->delete();
            $this->insertAttendees($id, $attendees);
            sort($oldAttendeeIds);
            $newAttendeeIds = $payload['attendee_ids'];
            sort($newAttendeeIds);

            $changedFields = [];
            if ((string) ($existing->meeting_title ?? '') !== $payload['meeting_title']) {
                $changedFields[] = 'meeting_title';
            }
            if ((string) ($existing->meeting_type ?? '') !== $payload['meeting_type']) {
                $changedFields[] = 'meeting_type';
            }
            if ((string) ($existing->meeting_datetime ?? '') !== $payload['meeting_datetime']) {
                $changedFields[] = 'meeting_datetime';
            }
            if (trim((string) ($existing->venue ?? '')) !== trim($payload['venue'])) {
                $changedFields[] = 'venue';
            }
            if (trim((string) ($existing->guest_attendees_text ?? '')) !== trim($resolvedGuestAttendees)) {
                $changedFields[] = 'guest_attendees_text';
            }
            if (trim((string) ($existing->agenda ?? '')) !== trim($resolvedAgenda)) {
                $changedFields[] = 'agenda';
            }
            if (trim((string) ($existing->minutes_text ?? '')) !== trim($resolvedMinutes)) {
                $changedFields[] = 'minutes_text';
            }
            if (trim((string) ($existing->action_items ?? '')) !== trim($resolvedActionItems)) {
                $changedFields[] = 'action_items';
            }
            if ((string) ($existing->record_status ?? 'Complete') !== $updates['record_status']) {
                $changedFields[] = 'record_status';
            }
            if ($oldAttendeeIds !== $newAttendeeIds) {
                $changedFields[] = 'attendees';
            }
            if (! empty($storedAttachment['public_path']) && (string) ($existing->attachment_path ?? '') !== $storedAttachment['public_path']) {
                $changedFields[] = 'attachment';
            }

            if (! empty($changedFields)) {
                $auditAction = $payload['form_stage'] === 'details'
                    ? ($wasDraft ? 'UPDATE_DRAFT' : 'UPDATE_DETAILS')
                    : ($wasDraft ? 'COMPLETE_DRAFT' : 'UPDATE_NOTES');
                $auditSummary = $payload['form_stage'] === 'details'
                    ? ($wasDraft ? 'Meeting draft details updated' : 'Meeting details updated')
                    : ($wasDraft ? 'Meeting draft finalized' : 'Meeting notes updated');
                $this->appendAuditLog(
                    $id,
                    $auditAction,
                    $auditSummary,
                    $changedFields,
                    $staffId,
                    $staffName,
                    $staffCode
                );
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->attachments->deleteAbsoluteFile($storedAttachment['absolute_path'] ?? '');
            return $this->internalServerErrorResponse($e);
        }

        if (! empty($storedAttachment['public_path'])) {
            $this->attachments->deletePublicPath((string) ($existing->attachment_path ?? ''));
        }

        return response()->json([
            'success' => true,
            'message' => $payload['form_stage'] === 'details'
                ? 'Meeting details saved successfully.'
                : 'Meeting minutes updated successfully.',
            'id' => $id,
            'stage' => $payload['form_stage'],
            'record_status' => $payload['form_stage'] === 'notes' ? 'Complete' : ($wasDraft ? 'Draft' : 'Complete'),
            'is_draft' => $payload['form_stage'] === 'details' && $wasDraft,
            'draft_key' => $payload['form_stage'] === 'notes'
                ? ''
                : (string) ($payload['draft_key'] !== '' ? $payload['draft_key'] : ($existing->draft_key ?? '')),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $method = strtoupper((string) $request->method());
        if (! in_array($method, ['DELETE', 'GET'], true)) {
            return response()->json(['success' => false, 'message' => 'Method not allowed'], 405);
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid meeting id.'], 400);
        }

        DB::beginTransaction();
        try {
            $meeting = DB::table('meeting_minutes')->where('id', $id)->lockForUpdate()->first();
            if (! $meeting) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Meeting record not found.'], 404);
            }
            if ((int) $meeting->created_by !== $staffId && ! $this->isSystemAdmin($request)) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'You are not allowed to delete this meeting record.'], 403);
            }
            $isDraft = (string) ($meeting->record_status ?? 'Complete') === 'Draft';
            if ($isDraft) {
                $this->appendAuditLog(
                    $id,
                    'DISCARD_DRAFT',
                    'Meeting draft discarded',
                    ['record_status'],
                    $staffId,
                    (string) ($request->session()->get('full_name', $meeting->created_name ?? '')),
                    (string) ($request->session()->get('name_code', $meeting->created_code ?? ''))
                );
                DB::table('meeting_minute_attendees')->where('meeting_id', $id)->delete();
                DB::table('meeting_minutes')->where('id', $id)->update([
                    'meeting_title' => '[Discarded draft]',
                    'venue' => null,
                    'guest_attendees_text' => null,
                    'agenda' => null,
                    'minutes_text' => '',
                    'action_items' => null,
                    'attachment_path' => null,
                    'attachment_name' => null,
                    'attachment_size' => null,
                    'attachment_mime' => null,
                    'record_status' => 'Discarded',
                    'draft_key' => null,
                    'updated_by' => $staffId,
                    'updated_name' => (string) ($request->session()->get('full_name', $meeting->created_name ?? '')),
                    'updated_code' => (string) ($request->session()->get('name_code', $meeting->created_code ?? '')),
                    'updated_at' => now(),
                ]);
                DB::commit();
                $this->attachments->deletePublicPath((string) ($meeting->attachment_path ?? ''));

                return response()->json([
                    'success' => true,
                    'message' => 'Meeting draft discarded successfully.',
                ]);
            }

            DB::table('meeting_minutes')->where('id', $id)->delete();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->internalServerErrorResponse($e);
        }

        $this->attachments->deletePublicPath((string) ($meeting->attachment_path ?? ''));
        return response()->json(['success' => true, 'message' => 'Meeting minutes deleted successfully.']);
    }

    private function isSystemAdmin(Request $request): bool
    {
        $roles = $request->session()->get('roles', []);
        if (! is_array($roles)) {
            $roles = $roles ? [$roles] : [];
        }

        return collect($roles)
            ->map(static fn ($role): string => strtolower(trim((string) $role)))
            ->contains('system admin');
    }

    private function validatePayload(Request $request): array
    {
        $meetingTitle = trim((string) $request->input('meeting_title', ''));
        $meetingType = trim((string) $request->input('meeting_type', 'Ad Hoc'));
        $meetingDateTime = trim((string) $request->input('meeting_datetime', ''));
        $formStage = trim((string) $request->input('form_stage', 'notes'));
        $venue = trim((string) $request->input('venue', ''));
        $guestText = trim((string) $request->input('guest_attendees_text', ''));
        $guestText = implode("\n", array_values(array_filter(array_map(
            static fn ($line) => trim((string) $line),
            preg_split('/\r\n|\r|\n/', $guestText) ?: []
        ), static fn ($line) => $line !== '')));
        $agenda = trim((string) $request->input('agenda', ''));
        $minutesText = trim((string) $request->input('minutes_text', ''));
        $actionItems = trim((string) $request->input('action_items', ''));
        $attendeeIdsRaw = trim((string) $request->input('attendee_ids', ''));
        $draftKey = trim((string) $request->input('draft_key', ''));

        if ($meetingTitle === '') {
            return [null, 'Meeting title is required.'];
        }
        if (! in_array($meetingType, ['Monthly', 'Weekly', 'Ad Hoc'], true)) {
            return [null, 'Invalid meeting type.'];
        }
        if (! in_array($formStage, ['details', 'notes'], true)) {
            $formStage = 'notes';
        }
        if ($meetingDateTime === '') {
            return [null, 'Meeting date and time is required.'];
        }
        if ($formStage === 'notes' && $minutesText === '') {
            return [null, 'Meeting minutes are required.'];
        }
        if (strlen($meetingTitle) > 255) {
            return [null, 'Meeting title must be 255 characters or fewer.'];
        }
        if (strlen($venue) > 255) {
            return [null, 'Venue must be 255 characters or fewer.'];
        }
        if ($draftKey !== '' && ! preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $draftKey)) {
            return [null, 'Invalid draft key.'];
        }

        $normalizedDt = str_replace('T', ' ', $meetingDateTime);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalizedDt)) {
            $normalizedDt .= ':00';
        }
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $normalizedDt);
        if (! $dt || $dt->format('Y-m-d H:i:s') !== $normalizedDt) {
            return [null, 'Invalid meeting date/time format.'];
        }

        $decodedIds = json_decode($attendeeIdsRaw, true);
        if (! is_array($decodedIds)) {
            $decodedIds = array_filter(array_map('trim', explode(',', $attendeeIdsRaw)));
        }
        $attendeeIds = [];
        foreach ($decodedIds as $value) {
            $num = (int) $value;
            if ($num > 0) {
                $attendeeIds[] = $num;
            }
        }
        $attendeeIds = array_values(array_unique($attendeeIds));
        if (count($attendeeIds) === 0) {
            return [null, 'At least one attendee is required.'];
        }
        $actionItemsError = $this->validateActionItemsPayload($actionItems);
        if ($actionItemsError !== null) {
            return [null, $actionItemsError];
        }

        return [[
            'meeting_title' => $meetingTitle,
            'meeting_type' => $meetingType,
            'meeting_datetime' => $dt->format('Y-m-d H:i:s'),
            'form_stage' => $formStage,
            'venue' => $venue,
            'guest_attendees_text' => $guestText,
            'agenda' => $agenda,
            'minutes_text' => $minutesText,
            'action_items' => $actionItems,
            'attendee_ids' => $attendeeIds,
            'draft_key' => $draftKey,
        ], null];
    }

    private function validateActionItemsPayload(string $actionItems): ?string
    {
        if ($actionItems === '') {
            return null;
        }
        $decoded = json_decode($actionItems, true);
        if (! is_array($decoded)) {
            return null;
        }
        foreach ($decoded as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $actionText = trim((string) ($item['action_text'] ?? $item['actionText'] ?? ''));
            $picStaffId = trim((string) ($item['pic_staff_id'] ?? $item['picStaffId'] ?? ''));
            $dueDate = trim((string) ($item['due_date'] ?? $item['dueDate'] ?? ''));
            if ($actionText === '' && ($picStaffId !== '' || $dueDate !== '')) {
                return 'Action text is required when a PIC or due date is set.';
            }
            if ($dueDate !== '') {
                $dt = \DateTime::createFromFormat('Y-m-d', $dueDate);
                if (! $dt || $dt->format('Y-m-d') !== $dueDate) {
                    return 'Invalid action due date format. Use YYYY-MM-DD.';
                }
            }
            if ($index > 100) {
                return 'Too many action items.';
            }
        }
        return null;
    }

    private function resolveAttendees(array $attendeeIds): array
    {
        if (empty($attendeeIds)) {
            return [];
        }
        return DB::table('staff_general')
            ->select(['staff_id', 'full_name', 'name_code'])
            ->whereNull('deleted_at')
            ->whereIn('staff_id', $attendeeIds)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function insertAttendees(int $meetingId, array $attendees): void
    {
        foreach ($attendees as $a) {
            DB::table('meeting_minute_attendees')->insert([
                'meeting_id' => $meetingId,
                'staff_id' => (int) ($a['staff_id'] ?? 0),
                'staff_name' => (string) ($a['full_name'] ?? ''),
                'staff_code' => (string) ($a['name_code'] ?? ''),
                'created_at' => now(),
            ]);
        }
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

    private function isContentLocked(string $verificationStatus): bool
    {
        $status = strtolower(trim($verificationStatus));
        return in_array($status, ['verified', 'concurred'], true);
    }

    private function lockedContentMessage(): string
    {
        return 'Meeting minutes are locked because they are verified or concurred. Please revoke approval before making changes.';
    }

    private function internalServerErrorResponse(\Throwable $e)
    {
        report($e);
        return response()->json(['success' => false, 'message' => 'Server error.'], 500);
    }
}
