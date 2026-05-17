<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class HrMiscController extends Controller
{
    public function __construct(private AuditLogService $auditLog) {}

    private function denyUnlessHrAdmin(Request $request)
    {
        $roles = $request->session()->get('roles', []);
        if (!is_array($roles)) {
            $roles = [$roles];
        }

        $authorized = collect($roles)
            ->map(static fn ($role): string => strtolower(trim((string) $role)))
            ->intersect(['hr', 'system admin'])
            ->isNotEmpty();

        if ($authorized) {
            return null;
        }

        return response()->json([
            'status'  => 'error',
            'message' => 'Unauthorized: insufficient role for staff detail access.',
        ], 403);
    }

    /**
     * GET — return all active, non-deleted staff as a flat JSON array.
     * Matches legacy response shape exactly (no status/data envelope).
     */
    public function listStaff()
    {
        $staff = DB::table('staff_general')
            ->select([
                'staff_id as id',
                'full_name as name',
                'name_code as code',
                'email',
                'mobile_number as mobileNumber',
                'role',
            ])
            ->whereNull('deleted_at')
            ->where('status', '!=', 'Inactive')
            ->orderBy('full_name')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->values();

        return response()->json($staff);
    }

    /**
     * GET — fetch full detail for a single staff member from 3 tables.
     * Param: staff_id (required).
     */
    public function viewStaffDetail(Request $request, ?int $id = null)
    {
        if ($unauthorized = $this->denyUnlessHrAdmin($request)) {
            return $unauthorized;
        }

        $queryId = (int) $request->query('staff_id', 0);
        if ($id !== null && $id > 0 && $queryId > 0 && $id !== $queryId) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Staff ID mismatch.',
            ], 409);
        }

        $staffId = ($id !== null && $id > 0) ? $id : $queryId;
        if ($staffId <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'staff_id is required.',
            ], 422);
        }

        $general = DB::table('staff_general')
            ->select([
                'full_name',
                'name_code',
                'email',
                'mobile_number',
                'crm_position',
                'position',
                'department',
                'start_date',
                'status',
            ])
            ->where('staff_id', $staffId)
            ->first();

        if (!$general) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Staff member not found.',
            ], 404);
        }

        $userRow = DB::table('system_users')
            ->select(['email', 'role', 'created_at', 'updated_at'])
            ->where('staff_id', $staffId)
            ->first();

        // Decode role JSON field into array if present
        $user = null;
        if ($userRow) {
            $user = (array) $userRow;
            if (isset($user['role']) && is_string($user['role'])) {
                $decoded = json_decode($user['role'], true);
                $user['role'] = is_array($decoded) ? $decoded : [$user['role']];
            }
        }

        $profile = DB::table('staff_profile')
            ->select([
                'birth_date',
                'nric',
                'current_address',
                'emergency_name1',
                'emergency_name2',
                'emergency_relationship1',
                'emergency_relationship2',
                'emergency_phone1',
                'emergency_phone2',
                'emergency_address1',
                'emergency_address2',
                'chronic_illness',
                'allergies',
                'disabilities',
                'current_medication',
                'other_concerns',
            ])
            ->where('staff_id', $staffId)
            ->first();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'general' => (array) $general,
                'user'    => $user,
                'profile' => $profile ? (array) $profile : null,
            ],
        ]);
    }

    /**
     * POST — terminate a staff member.
     * Body: staff_id.
     * Privileged action: HR / Manager / Admin / Super roles only.
     */
    public function handleTerminate(Request $request)
    {
        $roles = (array) $request->session()->get('roles', []);
        $isPrivileged = collect($roles)->contains(fn ($r) =>
            str_contains(strtolower((string) $r), 'hr') ||
            str_contains(strtolower((string) $r), 'manager') ||
            str_contains(strtolower((string) $r), 'admin') ||
            str_contains(strtolower((string) $r), 'super')
        );

        if (!$isPrivileged) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized: insufficient role to perform termination.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'staff_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'staff_id is required.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $targetId = (int) $validator->validated()['staff_id'];

        $exists = DB::table('staff_general')->where('staff_id', $targetId)->exists();
        if (!$exists) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Staff member not found.',
            ], 404);
        }

        DB::beginTransaction();
        try {
            DB::table('staff_general')
                ->where('staff_id', $targetId)
                ->update([
                    'status'        => 'Inactive',
                    'terminated_at' => now(),
                    'deleted_at'    => now(),
                ]);

            DB::table('system_users')
                ->where('staff_id', $targetId)
                ->update(['is_active' => 0]);

            DB::table('staff_allowance')
                ->where('staff_id', $targetId)
                ->where('status', 'active')
                ->update([
                    'status'     => 'deleted',
                    'deleted_at' => now(),
                ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json([
                'status'  => 'error',
                'message' => 'Termination failed. Please try again.',
            ], 500);
        }

        $this->auditLog->log($request, "Terminated staff #{$targetId}");

        return response()->json([
            'status'  => 'success',
            'message' => 'Staff member has been terminated successfully.',
        ]);
    }

    /**
     * POST (form data) — record handbook signature for the authenticated staff member.
     * Fields: full_name, ic_number.
     * One signature per calendar year is enforced.
     */
    public function signHandbook(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:255'],
            'ic_number' => ['required', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'full_name and ic_number are required.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Enforce one signature per calendar year
        $alreadySigned = DB::table('hr_handbook_sign')
            ->where('staff_id', $staffId)
            ->whereYear('signed_at', DB::raw('YEAR(CURDATE())'))
            ->exists();

        if ($alreadySigned) {
            return response()->json([
                'success' => false,
                'message' => 'You have already signed the handbook for this year.',
            ]);
        }

        $data = $validator->validated();

        DB::table('hr_handbook_sign')->insert([
            'staff_id'   => $staffId,
            'full_name'  => $data['full_name'],
            'ic_number'  => $data['ic_number'],
            'signed_at'  => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->auditLog->log($request, "Signed employee handbook (staff #{$staffId})");

        return response()->json([
            'success' => true,
            'message' => 'Handbook signed successfully.',
        ]);
    }

    /**
     * GET — retrieve all handbook signatures ordered by most recent.
     */
    public function getHandbookSignatures()
    {
        $signatures = DB::table('hr_handbook_sign')
            ->orderByDesc('signed_at')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->values();

        return response()->json([
            'success' => true,
            'data'    => $signatures,
        ]);
    }
}
