<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SportEvent\StoreSportEventRequest;
use App\Http\Requests\SportEvent\UpdateSportEventRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SportEventController extends Controller
{
    public function __construct(private AuditLogService $auditLog) {}

    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function index(Request $request)
    {
        $q = trim($request->query('q', ''));

        $query = DB::table('sport_events as se')
            ->leftJoin('sport_event_attendees as ea', 'ea.event_id', '=', 'se.id')
            ->select([
                'se.id', 'se.event_name', 'se.event_datetime',
                'se.image_path', 'se.image_name', 'se.image_size', 'se.image_mime',
                'se.created_by', 'se.created_name', 'se.created_code', 'se.created_at',
                DB::raw('COUNT(ea.id) AS attendee_count'),
            ])
            ->groupBy(
                'se.id', 'se.event_name', 'se.event_datetime', 'se.image_path',
                'se.image_name', 'se.image_size', 'se.image_mime',
                'se.created_by', 'se.created_name', 'se.created_code', 'se.created_at'
            )
            ->orderByDesc('se.event_datetime')
            ->orderByDesc('se.id');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('se.event_name', 'like', "%{$q}%")
                    ->orWhere('se.created_name', 'like', "%{$q}%")
                    ->orWhere('se.created_code', 'like', "%{$q}%");
            });
        }

        $paginator = $query->paginate(20);
        $events    = $paginator->items();

        if (empty($events)) {
            return response()->json([
                'success'    => true,
                'items'      => [],
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                ],
            ]);
        }

        $eventIds      = array_column($events, 'id');
        $placeholders  = implode(',', array_fill(0, count($eventIds), '?'));
        $attendeeRows  = DB::select(
            "SELECT event_id, staff_id, staff_name, staff_code
             FROM sport_event_attendees
             WHERE event_id IN ({$placeholders})
             ORDER BY staff_name ASC",
            $eventIds
        );

        $byEvent = [];
        foreach ($attendeeRows as $row) {
            $byEvent[$row->event_id][] = [
                'staff_id'   => (int) $row->staff_id,
                'staff_name' => $row->staff_name,
                'staff_code' => $row->staff_code ?? '',
            ];
        }

        $items = array_map(function ($e) use ($byEvent) {
            $id = (int) $e->id;
            return [
                'id'             => $id,
                'event_name'     => $e->event_name,
                'event_datetime' => $e->event_datetime,
                'image_path'     => AppFilePaths::publicUrlForStoredPath($e->image_path ?? ''),
                'image_name'     => $e->image_name ?? '',
                'image_size'     => isset($e->image_size) ? (int) $e->image_size : null,
                'image_mime'     => $e->image_mime ?? '',
                'created_by'     => isset($e->created_by) ? (int) $e->created_by : null,
                'created_name'   => $e->created_name ?? '',
                'created_code'   => $e->created_code ?? '',
                'created_at'     => $e->created_at ?? '',
                'attendee_count' => (int) $e->attendee_count,
                'attendees'      => $byEvent[$id] ?? [],
            ];
        }, $events);

        return response()->json([
            'success'    => true,
            'items'      => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreSportEventRequest $request)
    {
        $attendeeIds  = $this->parseAttendeeIds($request->input('attendee_ids', ''));
        $normalizedDt = $this->normalizeDatetime($request->input('event_datetime', ''));

        if ($normalizedDt === null) {
            return response()->json(['success' => false, 'message' => 'Invalid event date/time format.'], 400);
        }
        if (empty($attendeeIds)) {
            return response()->json(['success' => false, 'message' => 'At least one valid attendee is required.'], 400);
        }

        $staffId   = (int) $request->session()->get('staff_id');
        $staffName = $request->session()->get('full_name', '');
        $staffCode = $request->session()->get('name_code', '');

        $file                    = $request->file('image');
        [$storedPath, $publicUrl] = $this->storeImage($file);

        DB::beginTransaction();
        try {
            $attendees = $this->resolveAttendees($attendeeIds);
            if (count($attendees) !== count($attendeeIds)) {
                DB::rollBack();
                Storage::disk('public')->delete($storedPath);
                return response()->json(['success' => false, 'message' => 'One or more selected attendees are invalid.'], 400);
            }

            $eventId = DB::table('sport_events')->insertGetId([
                'event_name'     => $request->input('event_name'),
                'event_datetime' => $normalizedDt,
                'image_path'     => $publicUrl,
                'image_name'     => preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->getClientOriginalName()),
                'image_size'     => $file->getSize(),
                'image_mime'     => $file->getMimeType(),
                'created_by'     => $staffId,
                'created_name'   => $staffName,
                'created_code'   => $staffCode,
                'created_at'     => now(),
            ]);

            $this->insertAttendees($eventId, $attendees);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Storage::disk('public')->delete($storedPath);
            return response()->json(['success' => false, 'message' => 'Server error.'], 500);
        }

        $this->auditLog->log($request, "Created sport event #{$eventId}");
        return response()->json(['success' => true, 'message' => 'Sport event created successfully.', 'id' => $eventId]);
    }

    public function update(UpdateSportEventRequest $request, int $id)
    {
        $attendeeIds  = $this->parseAttendeeIds($request->input('attendee_ids', ''));
        $normalizedDt = $this->normalizeDatetime($request->input('event_datetime', ''));

        if ($normalizedDt === null) {
            return response()->json(['success' => false, 'message' => 'Invalid event date/time format.'], 400);
        }
        if (empty($attendeeIds)) {
            return response()->json(['success' => false, 'message' => 'At least one attendee is required.'], 400);
        }

        $staffId   = (int) $request->session()->get('staff_id');
        $staffName = $request->session()->get('full_name', '');
        $staffCode = $request->session()->get('name_code', '');

        $hasNewImage              = $request->hasFile('image');
        $storedPath               = null;
        $publicUrl                = null;

        if ($hasNewImage) {
            [$storedPath, $publicUrl] = $this->storeImage($request->file('image'));
        }

        DB::beginTransaction();
        try {
            $existing = DB::table('sport_events')->where('id', $id)->lockForUpdate()->first();
            if (!$existing) {
                DB::rollBack();
                if ($storedPath) Storage::disk('public')->delete($storedPath);
                return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
            }
            if ((int) $existing->created_by !== $staffId) {
                DB::rollBack();
                if ($storedPath) Storage::disk('public')->delete($storedPath);
                return response()->json(['success' => false, 'message' => 'You are not allowed to edit this event.'], 403);
            }

            $attendees = $this->resolveAttendees($attendeeIds);
            if (count($attendees) !== count($attendeeIds)) {
                DB::rollBack();
                if ($storedPath) Storage::disk('public')->delete($storedPath);
                return response()->json(['success' => false, 'message' => 'One or more selected attendees are invalid.'], 400);
            }

            $updates = [
                'event_name'     => $request->input('event_name'),
                'event_datetime' => $normalizedDt,
                'created_by'     => $staffId,
                'created_name'   => $staffName,
                'created_code'   => $staffCode,
                'updated_at'     => now(),
            ];
            if ($hasNewImage) {
                $file                  = $request->file('image');
                $updates['image_path'] = $publicUrl;
                $updates['image_name'] = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->getClientOriginalName());
                $updates['image_size'] = $file->getSize();
                $updates['image_mime'] = $file->getMimeType();
            }

            DB::table('sport_events')->where('id', $id)->update($updates);
            DB::table('sport_event_attendees')->where('event_id', $id)->delete();
            $this->insertAttendees($id, $attendees);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($storedPath) Storage::disk('public')->delete($storedPath);
            return response()->json(['success' => false, 'message' => 'Server error.'], 500);
        }

        if ($hasNewImage) {
            $this->deleteImageFile($existing->image_path ?? '');
        }

        $this->auditLog->log($request, "Updated sport event #{$id}");
        return response()->json(['success' => true, 'message' => 'Sport event updated successfully.', 'id' => $id]);
    }

    public function destroy(Request $request, int $id)
    {
        $staffId = (int) $request->session()->get('staff_id');

        DB::beginTransaction();
        try {
            $event = DB::table('sport_events')->where('id', $id)->lockForUpdate()->first();
            if (!$event) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
            }
            if ((int) $event->created_by !== $staffId) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'You are not allowed to delete this event.'], 403);
            }

            DB::table('sport_events')->where('id', $id)->delete();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Server error.'], 500);
        }

        $this->deleteImageFile($event->image_path ?? '');
        $this->auditLog->log($request, "Deleted sport event #{$id}");
        return response()->json(['success' => true, 'message' => 'Sport event deleted successfully.']);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function normalizeDatetime(string $raw): ?string
    {
        if ($raw === '') return null;
        $dt = str_replace('T', ' ', $raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dt)) $dt .= ':00';
        $obj = \DateTime::createFromFormat('Y-m-d H:i:s', $dt);
        return ($obj && $obj->format('Y-m-d H:i:s') === $dt) ? $dt : null;
    }

    private function parseAttendeeIds(string $raw): array
    {
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = array_filter(array_map('trim', explode(',', $raw)));
        }
        $ids = [];
        foreach ($decoded as $v) {
            $n = (int) $v;
            if ($n > 0) $ids[] = $n;
        }
        return array_values(array_unique($ids));
    }

    private function storeImage(\Illuminate\Http\UploadedFile $file): array
    {
        $year       = date('Y');
        $ext        = self::MIME_EXT[$file->getMimeType()] ?? 'jpg';
        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dir        = "sport-time/{$year}";
        $storedPath = "{$dir}/{$storedName}";
        Storage::disk('public')->putFileAs($dir, $file, $storedName);
        return [$storedPath, Storage::disk('public')->url($storedPath)];
    }

    private function resolveAttendees(array $ids): array
    {
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return DB::select(
            "SELECT staff_id, full_name, name_code FROM staff_general
             WHERE deleted_at IS NULL AND staff_id IN ({$placeholders})",
            $ids
        );
    }

    private function insertAttendees(int $eventId, array $attendees): void
    {
        foreach ($attendees as $a) {
            DB::table('sport_event_attendees')->insert([
                'event_id'   => $eventId,
                'staff_id'   => (int) $a->staff_id,
                'staff_name' => $a->full_name,
                'staff_code' => $a->name_code ?? '',
                'created_at' => now(),
            ]);
        }
    }

    private function deleteImageFile(string $publicPath): void
    {
        if ($publicPath === '') return;

        // New storage paths: served via /storage/...
        $storageBase = rtrim(Storage::disk('public')->url(''), '/');
        if (str_starts_with($publicPath, $storageBase . '/')) {
            $relative = ltrim(substr($publicPath, strlen($storageBase)), '/');
            Storage::disk('public')->delete($relative);
            return;
        }

        AppFilePaths::deletePublicPath($publicPath);
    }
}
