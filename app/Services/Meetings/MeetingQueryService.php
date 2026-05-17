<?php

namespace App\Services\Meetings;

use App\Support\AppFilePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingQueryService
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $year = trim((string) $request->query('year', ''));
        $meetingId = (int) $request->query('id', 0);
        $includeHistory = (string) $request->query('include_history', '') === '1';
        $staffId = (int) $request->session()->get('staff_id', 0);

        $query = DB::table('meeting_minutes as mm')
            ->leftJoin('meeting_minute_attendees as ma', 'ma.meeting_id', '=', 'mm.id')
            ->select([
                'mm.id',
                'mm.meeting_title',
                'mm.meeting_type',
                'mm.meeting_datetime',
                'mm.venue',
                'mm.guest_attendees_text',
                'mm.agenda',
                'mm.minutes_text',
                'mm.action_items',
                'mm.attachment_path',
                'mm.attachment_name',
                'mm.attachment_size',
                'mm.attachment_mime',
                'mm.created_by',
                'mm.created_name',
                'mm.created_code',
                'mm.updated_by',
                'mm.updated_name',
                'mm.updated_code',
                'mm.record_status',
                'mm.draft_key',
                'mm.verification_status',
                'mm.verified_by',
                'mm.verified_name',
                'mm.verified_code',
                'mm.verified_at',
                'mm.concurred_by',
                'mm.concurred_name',
                'mm.concurred_code',
                'mm.concurred_at',
                'mm.created_at',
                'mm.updated_at',
                DB::raw('COUNT(ma.id) as attendee_count'),
            ])
            ->groupBy([
                'mm.id',
                'mm.meeting_title',
                'mm.meeting_type',
                'mm.meeting_datetime',
                'mm.venue',
                'mm.guest_attendees_text',
                'mm.agenda',
                'mm.minutes_text',
                'mm.action_items',
                'mm.attachment_path',
                'mm.attachment_name',
                'mm.attachment_size',
                'mm.attachment_mime',
                'mm.created_by',
                'mm.created_name',
                'mm.created_code',
                'mm.updated_by',
                'mm.updated_name',
                'mm.updated_code',
                'mm.record_status',
                'mm.draft_key',
                'mm.verification_status',
                'mm.verified_by',
                'mm.verified_name',
                'mm.verified_code',
                'mm.verified_at',
                'mm.concurred_by',
                'mm.concurred_name',
                'mm.concurred_code',
                'mm.concurred_at',
                'mm.created_at',
                'mm.updated_at',
            ])
            ->orderByDesc('mm.meeting_datetime')
            ->orderByDesc('mm.id');

        $query->where(function ($sub) use ($staffId) {
            $sub->whereNotIn('mm.record_status', ['Draft', 'Discarded'])
                ->orWhereNull('mm.record_status');
            if ($staffId > 0) {
                $sub->orWhere(function ($draftSub) use ($staffId) {
                    $draftSub->where('mm.record_status', 'Draft')
                        ->where('mm.created_by', $staffId);
                });
            }
        });

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('mm.meeting_title', 'like', "%{$q}%")
                    ->orWhere('mm.meeting_type', 'like', "%{$q}%")
                    ->orWhere('mm.venue', 'like', "%{$q}%")
                    ->orWhere('mm.guest_attendees_text', 'like', "%{$q}%")
                    ->orWhere('mm.agenda', 'like', "%{$q}%")
                    ->orWhere('mm.minutes_text', 'like', "%{$q}%")
                    ->orWhere('mm.created_name', 'like', "%{$q}%")
                    ->orWhere('mm.created_code', 'like', "%{$q}%")
                    ->orWhere('mm.updated_name', 'like', "%{$q}%")
                    ->orWhere('mm.updated_code', 'like', "%{$q}%");
            });
        }
        if (preg_match('/^\d{4}$/', $year)) {
            $query->whereYear('mm.meeting_datetime', (int) $year);
        }
        if ($meetingId > 0) {
            $query->where('mm.id', $meetingId);
        }

        $meetings = $query->get();
        if ($meetings->isEmpty()) {
            return response()->json(['success' => true, 'items' => []]);
        }

        $meetingIds = $meetings->pluck('id')->map(fn ($v) => (int) $v)->all();
        $attendeesRows = DB::table('meeting_minute_attendees')
            ->select(['meeting_id', 'staff_id', 'staff_name', 'staff_code'])
            ->whereIn('meeting_id', $meetingIds)
            ->orderBy('staff_name')
            ->get();

        $attendeesByMeeting = [];
        foreach ($attendeesRows as $row) {
            $mid = (int) $row->meeting_id;
            $attendeesByMeeting[$mid][] = [
                'staff_id' => (int) $row->staff_id,
                'staff_name' => (string) $row->staff_name,
                'staff_code' => (string) ($row->staff_code ?? ''),
            ];
        }

        $items = [];
        foreach ($meetings as $m) {
            $id = (int) $m->id;
            $items[] = [
                'id' => $id,
                'meeting_title' => (string) $m->meeting_title,
                'meeting_type' => (string) ($m->meeting_type ?? 'Ad Hoc'),
                'meeting_datetime' => (string) $m->meeting_datetime,
                'venue' => (string) ($m->venue ?? ''),
                'guest_attendees_text' => (string) ($m->guest_attendees_text ?? ''),
                'agenda' => (string) ($m->agenda ?? ''),
                'minutes_text' => (string) ($m->minutes_text ?? ''),
                'action_items' => (string) ($m->action_items ?? ''),
                'attachment_path' => AppFilePaths::publicUrlForStoredPath($m->attachment_path ?? ''),
                'attachment_name' => (string) ($m->attachment_name ?? ''),
                'attachment_size' => isset($m->attachment_size) ? (int) $m->attachment_size : null,
                'attachment_mime' => (string) ($m->attachment_mime ?? ''),
                'created_by' => isset($m->created_by) ? (int) $m->created_by : null,
                'created_name' => (string) ($m->created_name ?? ''),
                'created_code' => (string) ($m->created_code ?? ''),
                'updated_by' => isset($m->updated_by) ? (int) $m->updated_by : null,
                'updated_name' => (string) ($m->updated_name ?? ''),
                'updated_code' => (string) ($m->updated_code ?? ''),
                'record_status' => (string) ($m->record_status ?? 'Complete'),
                'is_draft' => (string) ($m->record_status ?? 'Complete') === 'Draft',
                'draft_key' => (string) ($m->draft_key ?? ''),
                'verification_status' => (string) ($m->verification_status ?? 'Pending'),
                'verified_by' => isset($m->verified_by) ? (int) $m->verified_by : null,
                'verified_name' => (string) ($m->verified_name ?? ''),
                'verified_code' => (string) ($m->verified_code ?? ''),
                'verified_at' => (string) ($m->verified_at ?? ''),
                'concurred_by' => isset($m->concurred_by) ? (int) $m->concurred_by : null,
                'concurred_name' => (string) ($m->concurred_name ?? ''),
                'concurred_code' => (string) ($m->concurred_code ?? ''),
                'concurred_at' => (string) ($m->concurred_at ?? ''),
                'created_at' => (string) ($m->created_at ?? ''),
                'updated_at' => (string) ($m->updated_at ?? ''),
                'attendee_count' => (int) $m->attendee_count,
                'attendees' => $attendeesByMeeting[$id] ?? [],
            ];
        }

        if ($includeHistory) {
            $historyRows = DB::table('meeting_minute_audit_logs')
                ->select([
                    'id',
                    'meeting_id',
                    'action_type',
                    'action_summary',
                    'changed_fields',
                    'actor_id',
                    'actor_name',
                    'actor_code',
                    'created_at',
                ])
                ->whereIn('meeting_id', $meetingIds)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

            $historyByMeeting = [];
            foreach ($historyRows as $row) {
                $mid = (int) $row->meeting_id;
                $decoded = json_decode((string) ($row->changed_fields ?? '[]'), true);
                $historyByMeeting[$mid][] = [
                    'id' => (int) $row->id,
                    'action_type' => (string) $row->action_type,
                    'action_summary' => (string) ($row->action_summary ?? ''),
                    'changed_fields' => is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [],
                    'actor_id' => isset($row->actor_id) ? (int) $row->actor_id : null,
                    'actor_name' => (string) ($row->actor_name ?? ''),
                    'actor_code' => (string) ($row->actor_code ?? ''),
                    'created_at' => (string) ($row->created_at ?? ''),
                ];
            }

            $commentRows = DB::table('meeting_minute_comments')
                ->select([
                    'id',
                    'meeting_id',
                    'comment_type',
                    'comment_text',
                    'actor_id',
                    'actor_name',
                    'actor_code',
                    'created_at',
                ])
                ->whereIn('meeting_id', $meetingIds)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

            $commentsByMeeting = [];
            foreach ($commentRows as $row) {
                $mid = (int) $row->meeting_id;
                $commentsByMeeting[$mid][] = [
                    'id' => (int) $row->id,
                    'comment_type' => (string) ($row->comment_type ?? 'General'),
                    'comment_text' => (string) ($row->comment_text ?? ''),
                    'actor_id' => isset($row->actor_id) ? (int) $row->actor_id : null,
                    'actor_name' => (string) ($row->actor_name ?? ''),
                    'actor_code' => (string) ($row->actor_code ?? ''),
                    'created_at' => (string) ($row->created_at ?? ''),
                ];
            }

            foreach ($items as &$item) {
                $id = (int) $item['id'];
                $item['history'] = $historyByMeeting[$id] ?? [];
                $item['comments'] = $commentsByMeeting[$id] ?? [];
            }
            unset($item);
        }

        return response()->json(['success' => true, 'items' => $items]);
    }

    public function show(Request $request, int $id)
    {
        $clone = $request->duplicate(
            array_merge($request->query(), ['id' => $id]),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all()
        );

        return $this->index($clone);
    }
}
