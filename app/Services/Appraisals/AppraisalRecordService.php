<?php

namespace App\Services\Appraisals;

use App\Http\Requests\Appraisal\StoreFinalAppraisalRequest;
use App\Http\Requests\Appraisal\StoreAppraisalRequest;
use App\Http\Requests\Appraisal\UpdateFinalAppraisalRequest;
use App\Http\Requests\Appraisal\UpdateAppraisalRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppraisalRecordService extends AppraisalBaseService
{
    public function store(StoreAppraisalRequest $request)
    {
        if ($response = $this->denyUnlessAppraisalManager($request)) {
            return $response;
        }

        $data      = $request->validated();
        $createdBy = (int) $request->session()->get('staff_id', 0);
        if ($createdBy <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Not authenticated.',
            ], 401);
        }

        $appraisalId = (int) DB::table('hr_appraisal')->insertGetId([
            'staff_id'   => (int) $data['staffId'],
            'section'    => $data['section'],
            'event_date' => $data['eventDate'],
            'feedback'   => $data['input'],
            'created_by' => $createdBy,
            'created_at' => now(),
        ]);

        $this->auditLog->log($request, "Created appraisal #{$appraisalId} for staff #{$data['staffId']}");

        return response()->json([
            'status'      => 'success',
            'message'     => 'Appraisal created successfully.',
            'appraisalId' => $appraisalId,
        ], 201);
    }

    public function index(Request $request)
    {
        if ($response = $this->denyUnlessAppraisalManager($request)) {
            return $response;
        }

        $staffIdInput = $request->query('staff_id');
        $staffId = ($staffIdInput === null || $staffIdInput === '') ? 0 : (int) $staffIdInput;
        $year = $request->query('year');
        if ($year === '') {
            $year = null;
        }

        if ($staffIdInput !== null && $staffIdInput !== '' && $staffId <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'staff_id must be a positive number.',
            ], 422);
        }

        if ($year !== null && !preg_match('/^\d{4}$/', (string) $year)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'year must be a 4-digit number.',
            ], 422);
        }

        $query = DB::table('hr_appraisal as a')
            ->join('staff_general as sg', 'a.staff_id', '=', 'sg.staff_id')
            ->join('staff_general as cb', 'a.created_by', '=', 'cb.staff_id')
            ->select([
                'a.id',
                'a.staff_id',
                'a.section',
                'a.event_date',
                'a.feedback',
                'a.created_at',
                'sg.full_name as staff_name',
                'sg.name_code as staff_code',
                'sg.position as staff_position',
                'sg.department as staff_department',
                'cb.full_name as creator_name',
                'cb.name_code as creator_code',
                'cb.position as creator_position',
                'cb.department as creator_department',
            ])
            ->orderByDesc('a.created_at');

        if ($staffId > 0) {
            $query->where('a.staff_id', $staffId);
        }

        if ($year !== null) {
            $query->whereYear('a.event_date', (int) $year);
        }

        $records = $query->get()->map(fn ($r) => (array) $r)->values();

        return response()->json([
            'status'  => 'success',
            'records' => $records,
        ]);
    }

    public function show(Request $request, int $id)
    {
        if ($id <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'A valid appraisal id is required.',
            ], 422);
        }

        $record = DB::table('hr_appraisal as a')
            ->join('staff_general as sg', 'a.staff_id', '=', 'sg.staff_id')
            ->join('staff_general as cb', 'a.created_by', '=', 'cb.staff_id')
            ->select([
                'a.id',
                'a.staff_id',
                'a.section',
                'a.event_date',
                'a.feedback',
                'a.created_at',
                'sg.full_name as staff_name',
                'sg.name_code as staff_code',
                'sg.position as staff_position',
                'sg.department as staff_department',
                'cb.full_name as creator_name',
                'cb.name_code as creator_code',
                'cb.position as creator_position',
                'cb.department as creator_department',
            ])
            ->where('a.id', $id)
            ->first();

        if (!$record) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Appraisal not found.',
            ], 404);
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        if (! $this->canManageAppraisals($request) && (int) $record->staff_id !== $staffId) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized access.',
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'record' => (array) $record,
        ]);
    }

    public function personal(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $records = DB::table('hr_appraisal as a')
            ->join('staff_general as sg', 'a.staff_id', '=', 'sg.staff_id')
            ->join('staff_general as cb', 'a.created_by', '=', 'cb.staff_id')
            ->select([
                'a.id',
                'a.staff_id',
                'a.section',
                'a.event_date',
                'a.feedback',
                'a.created_at',
                'sg.full_name as staff_name',
                'sg.name_code as staff_code',
                'sg.position as staff_position',
                'sg.department as staff_department',
                'cb.full_name as creator_name',
                'cb.name_code as creator_code',
                'cb.position as creator_position',
                'cb.department as creator_department',
            ])
            ->where('a.staff_id', $staffId)
            ->orderByDesc('a.created_at')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->values();

        return response()->json([
            'status'  => 'success',
            'records' => $records,
        ]);
    }

    public function update(UpdateAppraisalRequest $request, int $id)
    {
        if ($response = $this->denyUnlessAppraisalManager($request)) {
            return $response;
        }

        $data = $request->validated();
        $bodyId = (int) $data['id'];
        if ($id <= 0 || $bodyId !== $id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'A valid matching appraisal id is required.',
            ], 422);
        }

        $exists = DB::table('hr_appraisal')->where('id', $id)->exists();
        if (!$exists) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Appraisal not found.',
            ], 404);
        }

        DB::table('hr_appraisal')->where('id', $id)->update([
            'event_date' => $data['eventDate'],
            'feedback'   => $data['feedback'],
            'updated_at' => now(),
        ]);

        $this->auditLog->log($request, "Updated appraisal #{$id}");

        return response()->json([
            'status'  => 'success',
            'message' => 'Appraisal updated successfully.',
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        if ($response = $this->denyUnlessAppraisalManager($request)) {
            return $response;
        }

        $bodyId = (int) $request->input('id', $id);
        if ($bodyId !== $id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'A valid matching appraisal id is required.',
            ], 422);
        }

        if ($id <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'A valid appraisal id is required.',
            ], 422);
        }

        $deleted = DB::table('hr_appraisal')->where('id', $id)->delete();
        if ($deleted === 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Appraisal not found.',
            ], 404);
        }

        $this->auditLog->log($request, "Deleted appraisal #{$id}");

        return response()->json([
            'status'  => 'success',
            'message' => 'Appraisal deleted successfully.',
        ]);
    }
}
