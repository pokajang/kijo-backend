<?php

namespace App\Services\WhatsNew;

use App\Support\AppFilePaths;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class WhatsNewReadService extends WhatsNewBaseService
{

    public function latestUnread(Request $request): JsonResponse
    {
        $staffId = $this->staffId($request);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $unreadQuery = DB::table('whats_new_notes as n')
            ->leftJoin('whats_new_reads as r', function ($join) use ($staffId) {
                $join->on('r.whats_new_note_id', '=', 'n.id')
                    ->where('r.staff_id', '=', $staffId);
            })
            ->where('n.is_published', true)
            ->whereNotNull('n.published_at')
            ->where('n.published_at', '<=', now())
            ->whereNull('r.id');

        $unreadCount = (clone $unreadQuery)->count();
        $note = $unreadQuery
            ->select('n.*')
            ->orderByDesc('n.published_at')
            ->orderByDesc('n.id')
            ->first();

        return response()->json([
            'status' => 'success',
            'data' => $note ? $this->formatNote($note, attachments: $this->attachmentsForNote((int) $note->id)) : null,
            'meta' => [
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $staffId = $this->staffId($request);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $note = DB::table('whats_new_notes')
            ->where('id', $id)
            ->where('is_published', true)
            ->first();

        if (!$note) {
            return response()->json(['status' => 'error', 'message' => 'Notice not found.'], 404);
        }

        $now = now();
        DB::table('whats_new_reads')->upsert(
            [[
                'whats_new_note_id' => $id,
                'staff_id' => $staffId,
                'read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['whats_new_note_id', 'staff_id'],
            ['read_at', 'updated_at']
        );

        return response()->json(['status' => 'success', 'message' => 'Notice marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $staffId = $this->staffId($request);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $noteIds = DB::table('whats_new_notes as n')
            ->leftJoin('whats_new_reads as r', function ($join) use ($staffId) {
                $join->on('r.whats_new_note_id', '=', 'n.id')
                    ->where('r.staff_id', '=', $staffId);
            })
            ->where('n.is_published', true)
            ->whereNotNull('n.published_at')
            ->where('n.published_at', '<=', now())
            ->whereNull('r.id')
            ->pluck('n.id');

        if ($noteIds->isEmpty()) {
            return response()->json([
                'status' => 'success',
                'message' => 'All notices are already marked as read.',
                'data' => ['marked_count' => 0],
            ]);
        }

        $now = now();
        $rows = $noteIds->map(fn ($noteId) => [
            'whats_new_note_id' => $noteId,
            'staff_id' => $staffId,
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('whats_new_reads')->insertOrIgnore($rows);

        return response()->json([
            'status' => 'success',
            'message' => 'All notices marked as read.',
            'data' => ['marked_count' => count($rows)],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $staffId = $this->staffId($request);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $isAdmin = $this->isSystemAdmin($request);

        $query = DB::table('whats_new_notes as n')
            ->leftJoin('whats_new_reads as r', function ($join) use ($staffId) {
                $join->on('r.whats_new_note_id', '=', 'n.id')
                    ->where('r.staff_id', '=', $staffId);
            })
            ->leftJoin('staff_general as created_by', 'n.created_by_staff_id', '=', 'created_by.staff_id')
            ->leftJoin('staff_general as updated_by', 'n.updated_by_staff_id', '=', 'updated_by.staff_id')
            ->select([
                'n.*',
                'r.read_at',
                DB::raw('COALESCE(created_by.name_code, NULL) as created_by_name_code'),
                DB::raw('COALESCE(updated_by.name_code, NULL) as updated_by_name_code'),
                DB::raw('(SELECT COUNT(*) FROM whats_new_reads r WHERE r.whats_new_note_id = n.id) as read_count'),
            ]);

        if (!$isAdmin) {
            $query
                ->where('n.is_published', true)
                ->whereNotNull('n.published_at')
                ->where('n.published_at', '<=', now());
        }

        $rawNotes = $query
            ->orderByDesc(DB::raw('COALESCE(n.published_at, n.updated_at, n.created_at)'))
            ->orderByDesc('n.id')
            ->limit(100)
            ->get();
        $attachmentsByNote = $this->attachmentsForNotes($rawNotes->pluck('id')->map(fn ($id) => (int) $id)->all());
        $notes = $rawNotes
            ->map(fn ($note) => $this->formatNote(
                $note,
                includeAdminFields: $isAdmin,
                attachments: $attachmentsByNote[(int) $note->id] ?? [],
            ))
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $notes,
            'meta' => [
                'can_manage' => $isAdmin,
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $staffId = $this->staffId($request);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $isAdmin = $this->isSystemAdmin($request);
        $query = DB::table('whats_new_notes as n')
            ->leftJoin('whats_new_reads as r', function ($join) use ($staffId) {
                $join->on('r.whats_new_note_id', '=', 'n.id')
                    ->where('r.staff_id', '=', $staffId);
            })
            ->leftJoin('staff_general as created_by', 'n.created_by_staff_id', '=', 'created_by.staff_id')
            ->leftJoin('staff_general as updated_by', 'n.updated_by_staff_id', '=', 'updated_by.staff_id')
            ->where('n.id', $id)
            ->select([
                'n.*',
                'r.read_at',
                DB::raw('COALESCE(created_by.name_code, NULL) as created_by_name_code'),
                DB::raw('COALESCE(updated_by.name_code, NULL) as updated_by_name_code'),
                DB::raw('(SELECT COUNT(*) FROM whats_new_reads r WHERE r.whats_new_note_id = n.id) as read_count'),
            ]);

        if (!$isAdmin) {
            $query
                ->where('n.is_published', true)
                ->whereNotNull('n.published_at')
                ->where('n.published_at', '<=', now());
        }

        $rawNote = $query->first();
        $note = $rawNote
            ? $this->formatNote($rawNote, $isAdmin, $this->attachmentsForNote($id))
            : null;

        if (!$note) {
            return response()->json(['status' => 'error', 'message' => 'Notice not found.'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $note]);
    }
}
