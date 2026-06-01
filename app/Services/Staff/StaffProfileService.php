<?php

namespace App\Services\Staff;

use App\Http\Requests\Staff\UpdateProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffProfileService extends StaffBaseService
{
    public function getProfile(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $profile = DB::table('staff_general as g')
            ->leftJoin('staff_profile as p', function ($join) {
                $join->on('g.staff_id', '=', 'p.staff_id')->whereNull('p.deleted_at');
            })
            ->select([
                'g.staff_id',
                'g.full_name',
                'g.email',
                'g.mobile_number',
                'g.name_code',
                'g.crm_position',
                'p.birth_date',
                'p.nric',
                'p.current_address',
                'p.emergency_name1',
                'p.emergency_relationship1',
                'p.emergency_phone1',
                'p.emergency_address1',
                'p.emergency_name2',
                'p.emergency_relationship2',
                'p.emergency_phone2',
                'p.emergency_address2',
                'p.chronic_illness',
                'p.allergies',
                'p.disabilities',
                'p.current_medication',
                'p.other_concerns',
            ])
            ->where('g.staff_id', $staffId)
            ->whereNull('g.deleted_at')
            ->first();

        if (! $profile) {
            return response()->json(['status' => 'error', 'message' => 'Profile not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Profile loaded.',
            'data' => $profile,
            'profile' => $profile,
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $data = $request->validated();

        $profileMap = [
            'birthDate' => 'birth_date',
            'nric' => 'nric',
            'currentAddress' => 'current_address',
            'emergencyName1' => 'emergency_name1',
            'emergencyRelationship1' => 'emergency_relationship1',
            'emergencyPhone1' => 'emergency_phone1',
            'emergencyAddress1' => 'emergency_address1',
            'emergencyName2' => 'emergency_name2',
            'emergencyRelationship2' => 'emergency_relationship2',
            'emergencyPhone2' => 'emergency_phone2',
            'emergencyAddress2' => 'emergency_address2',
            'chronicIllness' => 'chronic_illness',
            'allergies' => 'allergies',
            'disabilities' => 'disabilities',
            'currentMedication' => 'current_medication',
            'otherConcerns' => 'other_concerns',
        ];

        DB::beginTransaction();
        try {
            $staff = DB::table('staff_general')
                ->where('staff_id', $staffId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (! $staff) {
                DB::rollBack();

                return response()->json(['status' => 'error', 'message' => 'Profile not found.'], 404);
            }

            $identityErrors = [];
            if (
                array_key_exists('email', $data)
                && trim((string) $data['email']) !== trim((string) $staff->email)
            ) {
                $identityErrors['email'] = ['Email is managed by your system account.'];
            }
            if (
                array_key_exists('nameCode', $data)
                && strtoupper(trim((string) $data['nameCode'])) !== strtoupper(trim((string) $staff->name_code))
            ) {
                $identityErrors['nameCode'] = ['Name code is maintained by administration.'];
            }
            if (! empty($identityErrors)) {
                DB::rollBack();

                return response()->json([
                    'status' => 'error',
                    'message' => 'Readonly identity fields cannot be changed from My Account.',
                    'errors' => $identityErrors,
                ], 422);
            }

            $profileExists = DB::table('staff_profile')
                ->where('staff_id', $staffId)
                ->whereNull('deleted_at')
                ->exists();

            $profileUpdates = [];
            foreach ($profileMap as $inputKey => $column) {
                if (array_key_exists($inputKey, $data)) {
                    $profileUpdates[$column] = $data[$inputKey];
                }
            }

            if ($profileExists) {
                if (! empty($profileUpdates)) {
                    $profileUpdates['updated_at'] = now();
                    DB::table('staff_profile')
                        ->where('staff_id', $staffId)
                        ->update($profileUpdates);
                }
            } else {
                $newProfile = ['staff_id' => $staffId];
                foreach ($profileMap as $inputKey => $column) {
                    $newProfile[$column] = $data[$inputKey] ?? null;
                }
                $newProfile['created_at'] = now();
                $newProfile['updated_at'] = now();
                DB::table('staff_profile')->insert($newProfile);
            }

            $generalUpdates = [];
            if (array_key_exists('crmPosition', $data)) {
                $generalUpdates['crm_position'] = $data['crmPosition'];
            }

            foreach ([
                'fullName' => 'full_name',
                'mobileNumber' => 'mobile_number',
            ] as $inputKey => $column) {
                if (! array_key_exists($inputKey, $data)) {
                    continue;
                }

                $value = $data[$inputKey];
                if ($inputKey === 'email') {
                    $value = trim((string) $value);
                }

                if ($value !== '') {
                    $generalUpdates[$column] = $value;
                }
            }

            if (! empty($generalUpdates)) {
                $generalUpdates['updated_at'] = now();
                DB::table('staff_general')
                    ->where('staff_id', $staffId)
                    ->update($generalUpdates);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Upserted profile for staff ID #{$staffId}");

        return response()->json([
            'status' => 'success',
            'message' => 'Profile saved successfully.',
            'data' => ['staff_id' => $staffId],
        ]);
    }
}
