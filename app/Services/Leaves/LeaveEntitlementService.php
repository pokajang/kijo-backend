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

class LeaveEntitlementService extends LeaveBaseService
{

    public function getAllEntitlements(Request $request): JsonResponse
    {
        if (!$this->canManageEntitlements($request)) {
            return $this->unauthorizedResponse();
        }

        $allocations = DB::select("
            SELECT a.*, s.full_name, s.name_code
            FROM hr_leaves_allocation a
            LEFT JOIN staff_general s ON a.staff_id = s.staff_id
            ORDER BY s.full_name, a.year DESC, a.leave_type
        ");

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

        $this->auditLog->log($request, "Assigned leave entitlement #{$id} (staff #{$data['staff_id']}, {$data['type']}, {$data['year']})");

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

        $affected = DB::table('hr_leaves_allocation')
            ->where('id', $data['id'])
            ->update([
                'staff_id'   => $data['staff_id'],
                'leave_type' => $data['type'],
                'year'       => $data['year'],
                'total_days' => $data['days'],
            ]);

        if (!$affected) {
            return response()->json(['status' => 'error', 'message' => 'Entitlement not found.'], 404);
        }

        $this->auditLog->log($request, "Updated leave entitlement #{$data['id']}");

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

        $affected = DB::table('hr_leaves_allocation')->where('id', $id)->delete();

        if (!$affected) {
            return response()->json(['status' => 'error', 'message' => 'Entitlement not found.'], 404);
        }

        $this->auditLog->log($request, "Deleted leave entitlement #{$id}");

        return response()->json(['status' => 'success', 'message' => 'Leave entitlement deleted successfully.']);
    }
}
