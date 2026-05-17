<?php

namespace App\Services\Staff;

use App\Http\Requests\Staff\GenerateUserActivityReportRequest;
use App\Http\Requests\Staff\GetStaffByIdRequest;
use App\Http\Requests\Staff\ListActivityRequest;
use App\Http\Requests\Staff\ListStaffRequest;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateProfileRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\AuditLogService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StaffDirectoryService extends StaffBaseService
{

    public function listStaffDetails(ListStaffRequest $request)
    {
        $data = $request->validated();
        $q = trim((string) ($data['q'] ?? ''));
        $perPage = (int) ($data['per_page'] ?? 200);

        $query = DB::table('staff_general')
            ->select([
                'staff_id',
                'full_name',
                'name_code',
                'email',
                'mobile_number',
                'position',
                'staff_type',
                'department',
            ])
            ->whereNull('deleted_at')
            ->orderBy('full_name');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('full_name', 'like', "%{$q}%")
                    ->orWhere('name_code', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('position', 'like', "%{$q}%")
                    ->orWhere('department', 'like', "%{$q}%");
            });
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Staff list loaded.',
            'data' => [
                'items' => $paginator->items(),
                'pagination' => $this->paginationMeta($paginator),
            ],
            'staff' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function manageStaff(ListStaffRequest $request)
    {
        if ($unauthorized = $this->denyUnlessStaffManager($request)) {
            return $unauthorized;
        }

        $data = $request->validated();
        $q = trim((string) ($data['q'] ?? ''));
        $perPage = (int) ($data['per_page'] ?? 200);

        $query = DB::table('staff_general as sg')
            ->leftJoin('staff_profile as sp', 'sp.staff_id', '=', 'sg.staff_id')
            ->select([
                'sg.staff_id',
                'sg.full_name',
                'sg.name_code',
                'sg.email',
                'sg.mobile_number',
                'sg.position',
                'sg.staff_type',
                'sg.department',
                'sg.start_date',
                'sg.status',
                'sg.grant_access',
                'sg.role',
                DB::raw('sp.nric as ic'),
            ])
            ->whereNull('sg.deleted_at')
            ->orderByRaw("CASE WHEN sg.status = 'Active' THEN 0 ELSE 1 END")
            ->orderByDesc('sg.created_at');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('sg.full_name', 'like', "%{$q}%")
                    ->orWhere('sg.name_code', 'like', "%{$q}%")
                    ->orWhere('sg.email', 'like', "%{$q}%")
                    ->orWhere('sg.mobile_number', 'like', "%{$q}%")
                    ->orWhere('sg.position', 'like', "%{$q}%")
                    ->orWhere('sg.department', 'like', "%{$q}%")
                    ->orWhere('sg.staff_type', 'like', "%{$q}%")
                    ->orWhere('sg.status', 'like', "%{$q}%");
            });
        }

        $paginator = $query->paginate($perPage);
        $staff = array_map(function ($row) {
            $row->role = $this->decodeRoles($row->role ?? null);
            return $row;
        }, $paginator->items());

        return response()->json([
            'status' => 'success',
            'message' => 'Staff management list loaded.',
            'data' => [
                'items' => $staff,
                'pagination' => $this->paginationMeta($paginator),
            ],
            'staff' => $staff,
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function getStaffById(GetStaffByIdRequest $request)
    {
        if ($unauthorized = $this->denyUnlessStaffManager($request)) {
            return $unauthorized;
        }

        $staffId = (int) $request->validated()['staff_id'];

        $staff = DB::table('staff_general')
            ->select([
                'staff_id',
                'full_name',
                'name_code',
                'email',
                'mobile_number',
                'position',
                'staff_type',
                'department',
                'start_date',
                'status',
                'grant_access',
                'role',
            ])
            ->where('staff_id', $staffId)
            ->whereNull('deleted_at')
            ->first();

        if (!$staff) {
            return response()->json(['status' => 'error', 'message' => 'Staff not found.'], 404);
        }

        $staff->role = $this->decodeRoles($staff->role ?? null);

        return response()->json([
            'status' => 'success',
            'message' => 'Staff loaded.',
            'data' => $staff,
            'staff' => $staff,
        ]);
    }
}
