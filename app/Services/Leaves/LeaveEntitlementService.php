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
use Illuminate\Support\Facades\Schema;

class LeaveEntitlementService extends LeaveBaseService
{

    private function formatHistoryDays(mixed $value): string
    {
        $number = (float) $value;
        $formatted = rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function formatHistoryStaff(?object $staff, ?int $staffId = null, ?string $fallbackCode = null): string
    {
        $name = $staff?->full_name ?: null;
        $code = $staff?->name_code ?: $fallbackCode;

        if ($name && $code) {
            return "{$name} ({$code})";
        }
        if ($name) {
            return $name;
        }
        if ($code) {
            return $code;
        }

        return $staffId ? "Staff #{$staffId}" : '-';
    }

    private function parseEntitlementHistoryAction(string $action): ?array
    {
        if (preg_match('/^Assigned leave entitlement #(\d+) to staff #(\d+), (.+), (\d{4}), ([\d.]+) days$/', $action, $matches)) {
            return [
                'event_type' => 'Assigned',
                'entitlement_id' => (int) $matches[1],
                'staff_id' => (int) $matches[2],
                'leave_type' => $matches[3],
                'year' => (int) $matches[4],
                'days' => $this->formatHistoryDays($matches[5]),
            ];
        }

        if (preg_match('/^Assigned leave entitlement #(\d+) \(staff #(\d+), (.+), (\d{4})\)$/', $action, $matches)) {
            return [
                'event_type' => 'Assigned',
                'entitlement_id' => (int) $matches[1],
                'staff_id' => (int) $matches[2],
                'leave_type' => $matches[3],
                'year' => (int) $matches[4],
                'days' => null,
            ];
        }

        if (preg_match('/^Updated leave entitlement #(\d+) from staff #(\d+), (.+), (\d{4}), ([\d.]+) days to staff #(\d+), (.+), (\d{4}), ([\d.]+) days$/', $action, $matches)) {
            return [
                'event_type' => 'Updated',
                'entitlement_id' => (int) $matches[1],
                'staff_id' => (int) $matches[6],
                'leave_type' => $matches[7],
                'year' => (int) $matches[8],
                'days' => $this->formatHistoryDays($matches[9]),
            ];
        }

        if (preg_match('/^Updated leave entitlement #(\d+)$/', $action, $matches)) {
            return [
                'event_type' => 'Updated',
                'entitlement_id' => (int) $matches[1],
                'staff_id' => null,
                'leave_type' => null,
                'year' => null,
                'days' => null,
            ];
        }

        if (preg_match('/^Deleted leave entitlement #(\d+) \(staff #(\d+), (.+), (\d{4}), ([\d.]+) days\)$/', $action, $matches)) {
            return [
                'event_type' => 'Deleted',
                'entitlement_id' => (int) $matches[1],
                'staff_id' => (int) $matches[2],
                'leave_type' => $matches[3],
                'year' => (int) $matches[4],
                'days' => $this->formatHistoryDays($matches[5]),
            ];
        }

        if (preg_match('/^Deleted leave entitlement #(\d+)$/', $action, $matches)) {
            return [
                'event_type' => 'Deleted',
                'entitlement_id' => (int) $matches[1],
                'staff_id' => null,
                'leave_type' => null,
                'year' => null,
                'days' => null,
            ];
        }

        return null;
    }

    public function getAllEntitlements(Request $request): JsonResponse
    {
        if (!$this->canManageEntitlements($request)) {
            return $this->unauthorizedResponse();
        }

        $columns = [
            'a.*',
            's.full_name',
            's.name_code',
        ];

        $columns[] = Schema::hasColumn('staff_general', 'status')
            ? 's.status as staff_status'
            : DB::raw('NULL as staff_status');

        if (Schema::hasColumn('staff_general', 'terminated_at')) {
            $columns[] = 's.terminated_at as staff_terminated_at';
        }

        $allocations = DB::table('hr_leaves_allocation as a')
            ->leftJoin('staff_general as s', 'a.staff_id', '=', 's.staff_id')
            ->select($columns)
            ->orderBy('s.full_name')
            ->orderByDesc('a.year')
            ->orderBy('a.leave_type')
            ->get();

        return response()->json(['status' => 'success', 'allocations' => $allocations]);
    }

    public function getMyEntitlements(Request $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id');

        $entitlements = DB::table('hr_leaves_allocation')
            ->select([
                'id',
                'leave_type',
                'year',
                'total_days',
                'used_days',
                DB::raw('(total_days - used_days) AS remaining'),
            ])
            ->where('staff_id', $staffId)
            ->orderBy('year', 'desc')
            ->orderBy('leave_type')
            ->get();

        return response()->json(['status' => 'success', 'entitlements' => $entitlements]);
    }

    public function getEntitlementHistory(Request $request): JsonResponse
    {
        if (!$this->canManageEntitlements($request)) {
            return $this->unauthorizedResponse();
        }

        $activities = DB::table('user_activities as ua')
            ->leftJoin('staff_general as actor', 'ua.staff_id', '=', 'actor.staff_id')
            ->select([
                'ua.id',
                'ua.staff_id as actor_staff_id',
                'ua.name_code as actor_code',
                'ua.action',
                'ua.created_at',
                'actor.full_name as actor_name',
                'actor.name_code as actor_staff_code',
            ])
            ->where(function ($query): void {
                $query->where('ua.action', 'like', 'Assigned leave entitlement #%')
                    ->orWhere('ua.action', 'like', 'Updated leave entitlement #%')
                    ->orWhere('ua.action', 'like', 'Deleted leave entitlement #%');
            })
            ->orderByDesc('ua.created_at')
            ->orderByDesc('ua.id')
            ->limit(1000)
            ->get();

        $parsedRows = $activities->map(function ($activity) {
            $parsed = $this->parseEntitlementHistoryAction((string) $activity->action);

            return $parsed ? ['activity' => $activity, 'parsed' => $parsed] : null;
        })->filter()->values();

        $targetStaffIds = $parsedRows
            ->map(fn ($row) => $row['parsed']['staff_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        $staffById = $targetStaffIds->isEmpty()
            ? collect()
            : DB::table('staff_general')
                ->whereIn('staff_id', $targetStaffIds)
                ->get()
                ->keyBy(fn ($staff) => (string) $staff->staff_id);

        $history = $parsedRows->map(function ($row) use ($staffById) {
            $activity = $row['activity'];
            $parsed = $row['parsed'];
            $targetStaff = $parsed['staff_id']
                ? $staffById->get((string) $parsed['staff_id'])
                : null;
            $actorStaff = (object) [
                'full_name' => $activity->actor_name,
                'name_code' => $activity->actor_staff_code,
            ];

            return [
                'id' => (int) $activity->id,
                'event_type' => $parsed['event_type'],
                'entitlement_id' => $parsed['entitlement_id'],
                'staff_id' => $parsed['staff_id'],
                'staff' => $this->formatHistoryStaff($targetStaff, $parsed['staff_id']),
                'leave_type' => $parsed['leave_type'] ?: '-',
                'year' => $parsed['year'],
                'days' => $parsed['days'],
                'assigned_by_id' => (int) $activity->actor_staff_id,
                'assigned_by' => $this->formatHistoryStaff(
                    $actorStaff,
                    (int) $activity->actor_staff_id,
                    (string) $activity->actor_code
                ),
                'description' => (string) $activity->action,
                'created_at' => $activity->created_at ? (string) $activity->created_at : null,
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'history' => $history,
        ]);
    }

    public function assignLeavesEntitlement(AssignEntitlementRequest $request): JsonResponse
    {
        if (!$this->canManageEntitlements($request)) {
            return $this->unauthorizedResponse();
        }

        $data = $request->validated();

        $exists = DB::table('hr_leaves_allocation')
            ->where('staff_id', $data['staff_id'])
            ->where('leave_type', $data['type'])
            ->where('year', $data['year'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status'  => 'error',
                'message' => 'An entitlement for this staff, leave type, and year already exists.',
            ], 409);
        }

        $id = DB::table('hr_leaves_allocation')->insertGetId([
            'staff_id'   => $data['staff_id'],
            'leave_type' => $data['type'],
            'year'       => $data['year'],
            'total_days' => $data['days'],
        ]);

        $days = $this->formatHistoryDays($data['days']);
        $this->auditLog->log($request, "Assigned leave entitlement #{$id} to staff #{$data['staff_id']}, {$data['type']}, {$data['year']}, {$days} days");

        return response()->json([
            'status'  => 'success',
            'message' => 'Leave entitlement assigned successfully.',
            'id'      => $id,
        ]);
    }

    public function updateEntitlement(UpdateEntitlementRequest $request): JsonResponse
    {
        if (!$this->canManageEntitlements($request)) {
            return $this->unauthorizedResponse();
        }

        $data = $request->validated();

        $exists = DB::table('hr_leaves_allocation')
            ->where('staff_id', $data['staff_id'])
            ->where('leave_type', $data['type'])
            ->where('year', $data['year'])
            ->where('id', '!=', $data['id'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Another entitlement for this staff, leave type, and year already exists.',
            ], 409);
        }

        $existing = DB::table('hr_leaves_allocation')->where('id', $data['id'])->first();

        if (!$existing) {
            return response()->json(['status' => 'error', 'message' => 'Entitlement not found.'], 404);
        }

        DB::table('hr_leaves_allocation')
            ->where('id', $data['id'])
            ->update([
                'staff_id'   => $data['staff_id'],
                'leave_type' => $data['type'],
                'year'       => $data['year'],
                'total_days' => $data['days'],
            ]);

        $oldDays = $this->formatHistoryDays($existing->total_days);
        $newDays = $this->formatHistoryDays($data['days']);
        $this->auditLog->log(
            $request,
            "Updated leave entitlement #{$data['id']} from staff #{$existing->staff_id}, {$existing->leave_type}, {$existing->year}, {$oldDays} days to staff #{$data['staff_id']}, {$data['type']}, {$data['year']}, {$newDays} days"
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Leave entitlement updated successfully.',
            'id'      => $data['id'],
        ]);
    }

    public function deleteEntitlement(Request $request, ?int $id = null): JsonResponse
    {
        if (!$this->canManageEntitlements($request)) {
            return $this->unauthorizedResponse();
        }

        $bodyId = (int) $request->input('id', 0);
        if ($id !== null && $id > 0 && $bodyId > 0 && $id !== $bodyId) {
            return response()->json(['status' => 'error', 'message' => 'Entitlement ID mismatch.'], 409);
        }

        $id = ($id !== null && $id > 0) ? $id : $bodyId;
        if ($id <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or missing entitlement ID.'], 422);
        }

        $existing = DB::table('hr_leaves_allocation')->where('id', $id)->first();
        $affected = DB::table('hr_leaves_allocation')->where('id', $id)->delete();

        if (!$affected) {
            return response()->json(['status' => 'error', 'message' => 'Entitlement not found.'], 404);
        }

        $details = $existing
            ? " (staff #{$existing->staff_id}, {$existing->leave_type}, {$existing->year}, {$this->formatHistoryDays($existing->total_days)} days)"
            : '';
        $this->auditLog->log($request, "Deleted leave entitlement #{$id}{$details}");

        return response()->json(['status' => 'success', 'message' => 'Leave entitlement deleted successfully.']);
    }
}
