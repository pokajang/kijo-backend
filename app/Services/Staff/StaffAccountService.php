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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StaffAccountService extends StaffBaseService
{
    public function createStaff(StoreStaffRequest $request)
    {
        if ($unauthorized = $this->denyUnlessStaffManager($request)) {
            return $unauthorized;
        }

        $data = $request->validated();
        $grantAccess = (bool) ($data['grantAccess'] ?? false);
        $roles = $this->normalizeRoles($data['systemRoles'] ?? []);
        $roleJson = json_encode($roles);
        $email = trim((string) $data['email']);

        if ($email !== '') {
            $emailExists = DB::table('staff_general')
                ->where('email', $email)
                ->whereNull('deleted_at')
                ->exists();
            if ($emailExists) {
                return response()->json(['status' => 'error', 'message' => 'Email is already in use by another staff record.'], 422);
            }
        }

        if ($grantAccess && $email !== '') {
            $systemEmailExists = DB::table('system_users')
                ->where('email', $email)
                ->exists();
            if ($systemEmailExists) {
                return response()->json(['status' => 'error', 'message' => 'Email is already used by another system user.'], 422);
            }
        }

        $staffId = null;
        $tempPassword = null;

        DB::beginTransaction();
        try {
            $staffId = DB::table('staff_general')->insertGetId([
                'full_name' => trim((string) $data['fullName']),
                'name_code' => $data['nameCode'] ?? null,
                'email' => $email,
                'mobile_number' => $data['mobileNumber'] ?? null,
                'position' => $data['position'] ?? null,
                'staff_type' => $data['staffType'] ?? null,
                'department' => $data['department'] ?? null,
                'start_date' => $data['startDate'] ?? null,
                'status' => $data['status'] ?? 'Active',
                'grant_access' => $grantAccess ? 1 : 0,
                'role' => $roleJson,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('staff_profile')->insert([
                'staff_id' => $staffId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($grantAccess && $email !== '') {
                $tempPassword = Str::random(14);
                DB::table('system_users')->insert([
                    'email' => $email,
                    'password_hash' => Hash::make($tempPassword),
                    'role' => $roleJson,
                    'staff_id' => $staffId,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Registered new staff member: {$data['fullName']} (ID: {$staffId})");

        if ($grantAccess && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && $tempPassword !== null) {
            $safeName = htmlspecialchars((string) $data['fullName'], ENT_QUOTES, 'UTF-8');
            $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
            $safePassword = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');
            $loginUrl = 'https://work.amiosh.com/login';

            SendHtmlMailJob::dispatch(
                $email,
                (string) $data['fullName'],
                'Welcome to KIJO - Your Account Is Ready',
                "<p>Hi {$safeName},</p>
                 <p>Welcome to KIJO. Your account has been created and you can start using the platform at <a href=\"{$loginUrl}\">work.amiosh.com</a>.</p>
                 <p><strong>Login email:</strong> {$safeEmail}</p>
                 <p><strong>Temporary password:</strong> {$safePassword}</p>
                 <p>Please change your password after your first login at <strong>Account &gt; Settings &gt; Password</strong>.</p>
                 <p>If you have any questions, please contact the admin team.</p>"
            );
        }

        $responseData = [
            'staff_id' => $staffId,
            'full_name' => trim((string) $data['fullName']),
            'email' => $email,
        ];

        return response()->json([
            'status' => 'success',
            'message' => 'Staff registered successfully.',
            'data' => $responseData,
        ]);
    }


    public function updateStaff(UpdateStaffRequest $request)
    {
        if ($unauthorized = $this->denyUnlessStaffManager($request)) {
            return $unauthorized;
        }

        $data = $request->validated();
        $staffId = (int) $data['staffId'];
        $grantAccess = (bool) ($data['grantAccess'] ?? false);
        $roles = $this->normalizeRoles($data['systemRoles'] ?? []);
        $roleJson = json_encode($roles);
        $email = trim((string) $data['email']);

        $shouldSendWelcome = false;
        $tempPassword = null;
        $staffName = trim((string) $data['fullName']);

        DB::beginTransaction();
        try {
            $staff = DB::table('staff_general')
                ->where('staff_id', $staffId)
                ->lockForUpdate()
                ->first();

            if (!$staff) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Staff not found.'], 404);
            }

            if ($email !== '') {
                $emailExists = DB::table('staff_general')
                    ->where('email', $email)
                    ->where('staff_id', '!=', $staffId)
                    ->whereNull('deleted_at')
                    ->exists();
                if ($emailExists) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => 'Email is already in use by another staff record.'], 422);
                }

                $systemEmailExists = DB::table('system_users')
                    ->where('email', $email)
                    ->where('staff_id', '!=', $staffId)
                    ->exists();
                if ($systemEmailExists) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => 'Email is already used by another system user.'], 422);
                }
            }

            $staffUpdates = [
                'full_name' => $staffName,
                'name_code' => $data['nameCode'] ?? null,
                'email' => $email,
                'mobile_number' => $data['mobileNumber'] ?? null,
                'position' => $data['position'] ?? null,
                'staff_type' => $data['staffType'] ?? null,
                'department' => $data['department'] ?? null,
                'start_date' => $data['startDate'] ?? null,
                'status' => $data['status'] ?? 'Active',
                'grant_access' => $grantAccess ? 1 : 0,
                'role' => $roleJson,
                'updated_at' => now(),
            ];

            if (($data['status'] ?? 'Active') === 'Active') {
                if (Schema::hasColumn('staff_general', 'deleted_at')) {
                    $staffUpdates['deleted_at'] = null;
                }
                if (Schema::hasColumn('staff_general', 'terminated_at')) {
                    $staffUpdates['terminated_at'] = null;
                }
            }

            DB::table('staff_general')
                ->where('staff_id', $staffId)
                ->update($staffUpdates);

            $existingUser = DB::table('system_users')
                ->where('staff_id', $staffId)
                ->lockForUpdate()
                ->first();

            if ($grantAccess && $email !== '') {
                if ($existingUser) {
                    $updates = [
                        'email' => $email,
                        'role' => $roleJson,
                        'is_active' => 1,
                        'updated_at' => now(),
                    ];

                    if ((int) ($existingUser->is_active ?? 0) === 0) {
                        $tempPassword = Str::random(14);
                        $updates['password_hash'] = Hash::make($tempPassword);
                        $shouldSendWelcome = true;
                    }

                    DB::table('system_users')->where('staff_id', $staffId)->update($updates);
                } else {
                    $tempPassword = Str::random(14);
                    DB::table('system_users')->insert([
                        'email' => $email,
                        'password_hash' => Hash::make($tempPassword),
                        'role' => $roleJson,
                        'staff_id' => $staffId,
                        'is_active' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $shouldSendWelcome = true;
                }
            } else {
                DB::table('system_users')
                    ->where('staff_id', $staffId)
                    ->update([
                        'is_active' => 0,
                        'role' => null,
                        'updated_at' => now(),
                    ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Updated staff member ID #{$staffId}");

        if ($grantAccess && $shouldSendWelcome && filter_var($email, FILTER_VALIDATE_EMAIL) && $tempPassword !== null) {
            $safeName = htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8');
            $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
            $safePassword = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');
            $loginUrl = 'https://work.amiosh.com/login';

            SendHtmlMailJob::dispatch(
                $email,
                $staffName,
                'Welcome to KIJO - Your Account Is Ready',
                "<p>Hi {$safeName},</p>
                 <p>Your KIJO system access is active. You can log in at <a href=\"{$loginUrl}\">work.amiosh.com</a>.</p>
                 <p><strong>Login email:</strong> {$safeEmail}</p>
                 <p><strong>Temporary password:</strong> {$safePassword}</p>
                 <p>Please change your password after your first login at <strong>Account &gt; Settings &gt; Password</strong>.</p>"
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Staff updated successfully.',
            'data' => [
                'staff_id' => $staffId,
                'full_name' => $staffName,
                'email' => $email,
            ],
        ]);
    }


    public function getSystemUsers(ListStaffRequest $request)
    {
        if ($unauthorized = $this->denyUnlessStaffManager($request)) {
            return $unauthorized;
        }

        $data = $request->validated();
        $q = trim((string) ($data['q'] ?? ''));
        $perPage = (int) ($data['per_page'] ?? 200);

        $query = DB::table('system_users as su')
            ->leftJoin('staff_general as sg', 'su.staff_id', '=', 'sg.staff_id')
            ->select([
                'su.id',
                'su.email',
                'su.role',
                'su.created_at',
                'su.updated_at',
                'sg.full_name',
                'sg.department',
                'sg.status',
            ])
            ->orderByDesc('su.created_at');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('su.email', 'like', "%{$q}%")
                    ->orWhere('sg.full_name', 'like', "%{$q}%")
                    ->orWhere('sg.department', 'like', "%{$q}%")
                    ->orWhere('sg.status', 'like', "%{$q}%");
            });
        }

        $paginator = $query->paginate($perPage);
        $users = array_map(function ($row) {
            $roles = $this->decodeRoles($row->role ?? null);
            $row->role = empty($roles) ? null : implode(', ', $roles);
            return $row;
        }, $paginator->items());

        return response()->json([
            'status' => 'success',
            'message' => 'System users loaded.',
            'data' => [
                'items' => $users,
                'pagination' => $this->paginationMeta($paginator),
            ],
            'users' => $users,
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }
}
