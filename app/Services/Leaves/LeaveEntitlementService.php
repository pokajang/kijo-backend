<?php

namespace App\Services\Leaves;

use App\Http\Requests\Leave\AssignEntitlementRequest;
use App\Http\Requests\Leave\LeaveActionRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Requests\Leave\UpdateEntitlementRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\AuditLogService;
use Illuminate\Database\QueryException;
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

    private function normalizeRemarks(mixed $value): ?string
    {
        $remarks = trim((string) ($value ?? ''));

        return $remarks === '' ? null : $remarks;
    }

    private function entitlementRemarksColumnExists(): bool
    {
        return Schema::hasColumn('hr_leaves_allocation', 'remarks');
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

        $columns = [
            'id',
            'leave_type',
            'year',
            'total_days',
            'used_days',
            DB::raw('(total_days - used_days) AS remaining'),
        ];

        $columns[] = $this->entitlementRemarksColumnExists()
            ? 'remarks'
            : DB::raw('NULL as remarks');

        $entitlements = DB::table('hr_leaves_allocation')
            ->select($columns)
            ->where('staff_id', $staffId)
            ->orderBy('year', 'desc')
            ->orderBy('leave_type')
            ->get();

        return response()->json(['status' => 'success', 'entitlements' => $entitlements]);
    }

    private function buildEntitlementHistory(?int $staffId = null)
    {
        $activitiesQuery = DB::table('user_activities as ua')
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
            ->when($staffId !== null, function ($query) use ($staffId): void {
                $query->where(function ($scoped) use ($staffId): void {
                    $scoped
                        ->where('ua.action', 'like', "Assigned leave entitlement #% to staff #{$staffId},%")
                        ->orWhere('ua.action', 'like', "Assigned leave entitlement #% (staff #{$staffId},%")
                        ->orWhere('ua.action', 'like', "Updated leave entitlement #% to staff #{$staffId},%")
                        ->orWhere('ua.action', 'like', "Deleted leave entitlement #% (staff #{$staffId},%");
                });
            })
            ->orderByDesc('ua.created_at')
            ->orderByDesc('ua.id')
            ->limit(1000);

        $activities = $activitiesQuery->get();

        $parsedRows = $activities->map(function ($activity) {
            $parsed = $this->parseEntitlementHistoryAction((string) $activity->action);

            return $parsed ? ['activity' => $activity, 'parsed' => $parsed] : null;
        })->filter();

        if ($staffId !== null) {
            $parsedRows = $parsedRows->filter(
                fn ($row): bool => (int) ($row['parsed']['staff_id'] ?? 0) === $staffId
            );
        }

        $parsedRows = $parsedRows->values();

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

        return $parsedRows->map(function ($row) use ($staffById) {
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
    }

    public function getEntitlementHistory(Request $request): JsonResponse
    {
        if (!$this->canManageEntitlements($request)) {
            return $this->unauthorizedResponse();
        }

        $history = $this->buildEntitlementHistory();

        return response()->json([
            'status' => 'success',
            'history' => $history,
        ]);
    }

    public function getMyEntitlementHistory(Request $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id');
        $history = $staffId > 0 ? $this->buildEntitlementHistory($staffId) : collect();

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

        try {
            $id = DB::transaction(function () use ($data): int {
                $exists = DB::table('hr_leaves_allocation')
                    ->where('staff_id', $data['staff_id'])
                    ->where('leave_type', $data['type'])
                    ->where('year', $data['year'])
                    ->exists();

                if ($exists) {
                    abort(response()->json([
                        'status'  => 'error',
                        'message' => 'An entitlement for this staff, leave type, and year already exists.',
                    ], 409));
                }

                $allocation = [
                    'staff_id'   => $data['staff_id'],
                    'leave_type' => $data['type'],
                    'year'       => $data['year'],
                    'total_days' => $data['days'],
                ];

                if ($this->entitlementRemarksColumnExists()) {
                    $allocation['remarks'] = $this->normalizeRemarks($data['remarks'] ?? null);
                }

                return DB::table('hr_leaves_allocation')->insertGetId($allocation);
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'An entitlement for this staff, leave type, and year already exists.',
            ], 409);
        }

        $days = $this->formatHistoryDays($data['days']);
        $this->auditLog->log($request, "Assigned leave entitlement #{$id} to staff #{$data['staff_id']}, {$data['type']}, {$data['year']}, {$days} days");

        return response()->json([
            'status'  => 'success',
            'message' => 'Leave entitlement assigned successfully.',
            'id'      => $id,
        ]);
    }

    public function updateEntitlement(UpdateEntitlementRequest $request, ?int $routeId = null): JsonResponse
    {
        if (!$this->canManageEntitlements($request)) {
            return $this->unauthorizedResponse();
        }

        $data = $request->validated();
        if ($routeId !== null && $routeId > 0 && $routeId !== (int) $data['id']) {
            return response()->json(['status' => 'error', 'message' => 'Entitlement ID mismatch.'], 409);
        }

        try {
            $existing = DB::transaction(function () use ($data) {
                $existing = DB::table('hr_leaves_allocation')
                    ->where('id', $data['id'])
                    ->lockForUpdate()
                    ->first();

                if (!$existing) {
                    abort(response()->json(['status' => 'error', 'message' => 'Entitlement not found.'], 404));
                }

                $usedDays = (float) $existing->used_days;
                $newDays = (float) $data['days'];
                if ($newDays < $usedDays) {
                    abort(response()->json([
                        'status' => 'error',
                        'message' => 'Entitlement days cannot be lower than used days.',
                    ], 422));
                }

                $isLocked = $usedDays > 0 || $this->entitlementHasActiveApprovedLeave($existing);
                $identityChanged =
                    (int) $existing->staff_id !== (int) $data['staff_id'] ||
                    (string) $existing->leave_type !== (string) $data['type'] ||
                    (int) $existing->year !== (int) $data['year'];

                if ($isLocked && $identityChanged) {
                    abort(response()->json([
                        'status' => 'error',
                        'message' => 'Used leave entitlements cannot change staff, leave type, or year.',
                    ], 422));
                }

                $exists = DB::table('hr_leaves_allocation')
                    ->where('staff_id', $data['staff_id'])
                    ->where('leave_type', $data['type'])
                    ->where('year', $data['year'])
                    ->where('id', '!=', $data['id'])
                    ->exists();

                if ($exists) {
                    abort(response()->json([
                        'status'  => 'error',
                        'message' => 'Another entitlement for this staff, leave type, and year already exists.',
                    ], 409));
                }

                $updates = [
                    'staff_id'   => $data['staff_id'],
                    'leave_type' => $data['type'],
                    'year'       => $data['year'],
                    'total_days' => $data['days'],
                ];

                if ($this->entitlementRemarksColumnExists()) {
                    $updates['remarks'] = $this->normalizeRemarks($data['remarks'] ?? null);
                }

                DB::table('hr_leaves_allocation')
                    ->where('id', $data['id'])
                    ->update($updates);

                return $existing;
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Another entitlement for this staff, leave type, and year already exists.',
            ], 409);
        }

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
        if ($existing && ((float) $existing->used_days > 0 || $this->entitlementHasActiveApprovedLeave($existing))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Used leave entitlements cannot be deleted.',
            ], 422);
        }

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

    private function entitlementHasActiveApprovedLeave(object $entitlement): bool
    {
        return DB::table('hr_leaves_application')
            ->where('staff_id', $entitlement->staff_id)
            ->where('type', $entitlement->leave_type)
            ->whereYear('start_date', (int) $entitlement->year)
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'approved'")
            ->exists();
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }
}
