<?php

namespace App\Services\Meetings;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingActionItemMutationService extends MeetingActionItemBaseService
{

    public function add(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $staffName = trim((string) $request->session()->get('full_name', ''));
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        if ($staffId <= 0 || $staffName === '' || $staffCode === '') {
            return response()->json(['success' => false, 'message' => 'No session'], 401);
        }

        $meetingId = (int) $request->input('meeting_id', 0);
        $actionText = trim((string) $request->input('action_text', ''));
        $picStaffId = (int) $request->input('pic_staff_id', 0);
        $dueDateInput = trim((string) $request->input('due_date', ''));
        $dueDate = $this->normalizeDueDate($dueDateInput);

        if ($meetingId <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid meeting id.'], 400);
        }
        if ($actionText === '') {
            return response()->json(['success' => false, 'message' => 'Action text is required.'], 400);
        }
        if ($dueDateInput !== '' && $dueDate === '') {
            return response()->json(['success' => false, 'message' => 'Invalid due date format. Use YYYY-MM-DD.'], 400);
        }

        DB::beginTransaction();
        try {
            $meeting = DB::table('meeting_minutes')->where('id', $meetingId)->lockForUpdate()->first();
            if (! $meeting) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Meeting record not found.'], 404);
            }
            if ((int) ($meeting->created_by ?? 0) !== $staffId) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'You are not allowed to edit this meeting record.'], 403);
            }
            if ((string) ($meeting->record_status ?? 'Complete') !== 'Complete') {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Finalize meeting minutes before adding action items.'], 400);
            }
            if ($this->isMeetingContentLocked((string) ($meeting->verification_status ?? 'Pending'))) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => $this->lockedMeetingContentMessage()], 400);
            }

            $picName = '';
            $picCode = '';
            if ($picStaffId > 0) {
                $pic = DB::table('staff_general')
                    ->select(['staff_id', 'full_name', 'name_code'])
                    ->where('staff_id', $picStaffId)
                    ->whereNull('deleted_at')
                    ->first();
                if (! $pic) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Selected PIC is invalid.'], 400);
                }
                $picName = trim((string) ($pic->full_name ?? ''));
                $picCode = trim((string) ($pic->name_code ?? ''));
            }

            $normalizedExisting = $this->normalizeForStorage((string) ($meeting->action_items ?? ''), $staffId, $staffName, $staffCode);
            $items = $this->decode($normalizedExisting);
            $now = now()->format('Y-m-d H:i:s');
            $items[] = [
                'item_id' => $this->generateId(),
                'action_text' => $actionText,
                'pic_staff_id' => $picStaffId > 0 ? $picStaffId : null,
                'pic_name' => $picName,
                'pic_code' => $picCode,
                'due_date' => $dueDate,
                'status' => 'Pending',
                'created_by' => $staffId,
                'created_name' => $staffName,
                'created_code' => $staffCode,
                'created_at' => $now,
                'updated_by' => $staffId,
                'updated_name' => $staffName,
                'updated_code' => $staffCode,
                'updated_at' => $now,
                'completed_by' => null,
                'completed_name' => '',
                'completed_code' => '',
                'completed_at' => '',
            ];

            DB::table('meeting_minutes')->where('id', $meetingId)->update([
                'action_items' => $this->encode($items),
                'updated_by' => $staffId,
                'updated_name' => $staffName,
                'updated_code' => $staffCode,
                'updated_at' => now(),
            ]);

            $summary = 'Action item added';
            $short = substr($actionText, 0, 120);
            if ($short !== '') {
                $summary .= ': ' . $short;
            }
            $this->appendAuditLog($meetingId, 'ADD_ACTION_ITEM', $summary, ['action_items'], $staffId, $staffName, $staffCode);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->internalServerErrorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Action item added successfully.',
            'meeting_id' => $meetingId,
            'pending_count' => $this->pendingCount($items),
        ]);
    }

    public function updateStatus(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $staffName = trim((string) $request->session()->get('full_name', ''));
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        if ($staffId <= 0 || $staffName === '' || $staffCode === '') {
            return response()->json(['success' => false, 'message' => 'No session'], 401);
        }

        $meetingId = (int) $request->input('meeting_id', 0);
        $statusRaw = trim((string) $request->input('status', ''));
        $itemId = trim((string) $request->input('item_id', ''));
        $itemIndex = (int) $request->input('item_index', -1);
        $nextStatus = $this->normalizeStatus($statusRaw);

        if ($meetingId <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid meeting id.'], 400);
        }
        if ($statusRaw === '') {
            return response()->json(['success' => false, 'message' => 'Invalid action status.'], 400);
        }
        if ($itemId === '' && $itemIndex < 0) {
            return response()->json(['success' => false, 'message' => 'Action item reference is required.'], 400);
        }

        DB::beginTransaction();
        try {
            $meeting = DB::table('meeting_minutes')
                ->select(['id', 'meeting_title', 'created_by', 'record_status', 'verification_status', 'action_items'])
                ->where('id', $meetingId)
                ->lockForUpdate()
                ->first();
            if (! $meeting) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Meeting record not found.'], 404);
            }
            if ((string) ($meeting->record_status ?? 'Complete') !== 'Complete') {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Finalize meeting minutes before updating action items.'], 400);
            }
            if ($this->isMeetingContentLocked((string) ($meeting->verification_status ?? 'Pending'))) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => $this->lockedMeetingContentMessage()], 400);
            }

            $items = $this->decode((string) ($meeting->action_items ?? ''));
            if (count($items) === 0) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'No action items found for this meeting.'], 404);
            }

            $targetIndex = -1;
            if ($itemId !== '') {
                foreach ($items as $idx => $item) {
                    $candidate = trim((string) ($item['item_id'] ?? ''));
                    if ($candidate !== '' && hash_equals($candidate, $itemId)) {
                        $targetIndex = (int) $idx;
                        break;
                    }
                }
            }
            if ($targetIndex < 0 && $itemIndex >= 0 && $itemIndex < count($items)) {
                $targetIndex = $itemIndex;
            }
            if ($targetIndex < 0 || ! isset($items[$targetIndex])) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Action item not found.'], 404);
            }

            $target = $items[$targetIndex];
            $picStaffId = isset($target['pic_staff_id']) && is_numeric($target['pic_staff_id']) ? (int) $target['pic_staff_id'] : 0;
            $meetingCreatorId = isset($meeting->created_by) ? (int) $meeting->created_by : 0;
            if ($staffId !== $meetingCreatorId && ! ($picStaffId > 0 && $staffId === $picStaffId)) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Only meeting creator or assigned PIC can update action status.'], 403);
            }

            $currentStatus = $this->normalizeStatus((string) ($target['status'] ?? 'Pending'));
            $now = now()->format('Y-m-d H:i:s');
            $target['item_id'] = trim((string) ($target['item_id'] ?? '')) !== '' ? (string) $target['item_id'] : $this->generateId();
            $target['status'] = $nextStatus;
            $target['updated_by'] = $staffId;
            $target['updated_name'] = $staffName;
            $target['updated_code'] = $staffCode;
            $target['updated_at'] = $now;
            if ($nextStatus === 'Done') {
                $target['completed_by'] = $staffId;
                $target['completed_name'] = $staffName;
                $target['completed_code'] = $staffCode;
                $target['completed_at'] = $now;
            } else {
                $target['completed_by'] = null;
                $target['completed_name'] = '';
                $target['completed_code'] = '';
                $target['completed_at'] = '';
            }

            $items[$targetIndex] = $target;
            DB::table('meeting_minutes')->where('id', $meetingId)->update([
                'action_items' => $this->encode($items),
                'updated_by' => $staffId,
                'updated_name' => $staffName,
                'updated_code' => $staffCode,
                'updated_at' => now(),
            ]);

            $actionText = trim((string) ($target['action_text'] ?? ''));
            $shortAction = $actionText !== '' ? substr($actionText, 0, 120) : 'Action item';
            $this->appendAuditLog(
                $meetingId,
                'UPDATE_ACTION_STATUS',
                "{$shortAction}: {$currentStatus} -> {$nextStatus}",
                ['action_items'],
                $staffId,
                $staffName,
                $staffCode
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->internalServerErrorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Action status updated successfully.',
            'meeting_id' => $meetingId,
            'pending_count' => $this->pendingCount($items),
            'item' => $target,
        ]);
    }
}
