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

class FinalAppraisalService extends AppraisalBaseService
{

    public function finalIndex(Request $request)
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

        $query = $this->finalAppraisalQuery()->orderByDesc('fa.created_at');

        if ($staffId > 0) {
            $query->where('fa.staff_id', $staffId);
        }

        if ($year !== null) {
            $query->whereYear('fa.appraisal_date', (int) $year);
        }

        $records = $query->get()->map(fn ($r) => (array) $r)->values();

        return response()->json([
            'status'  => 'success',
            'records' => $records,
        ]);
    }

    public function finalStore(StoreFinalAppraisalRequest $request)
    {
        if ($response = $this->denyUnlessAppraisalManager($request)) {
            return $response;
        }

        $createdBy = (int) $request->session()->get('staff_id', 0);
        if ($createdBy <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Not authenticated.',
            ], 401);
        }

        $data = $request->validated();
        $id = (int) DB::table('hr_final_appraisals')->insertGetId([
            'staff_id' => (int) $data['staffId'],
            'appraisal_date' => $data['appraisalDate'],
            'work_quality' => (int) $data['workQuality'],
            'teamwork' => (int) $data['teamwork'],
            'leadership' => (int) $data['leadership'],
            'overall_performance' => (int) $data['overallPerformance'],
            'supervisor_comments' => $data['supervisorComments'],
            'salary_increment_recommendation' => $data['salaryIncrementRecommendation'] ?? null,
            'promotion_recommendation' => $data['promotionRecommendation'] ?? null,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->auditLog->log($request, "Created final appraisal #{$id} for staff #{$data['staffId']}");

        return response()->json([
            'status' => 'success',
            'message' => 'Final appraisal created successfully.',
            'id' => $id,
        ], 201);
    }

    public function finalShow(Request $request, int $id)
    {
        if ($response = $this->denyUnlessAppraisalManager($request)) {
            return $response;
        }

        if ($id <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'A valid final appraisal id is required.',
            ], 422);
        }

        $record = $this->finalAppraisalQuery()->where('fa.id', $id)->first();

        if (!$record) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Final appraisal not found.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'record' => (array) $record,
        ]);
    }

    public function finalUpdate(UpdateFinalAppraisalRequest $request, int $id)
    {
        if ($response = $this->denyUnlessAppraisalManager($request)) {
            return $response;
        }

        if ($id <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'A valid final appraisal id is required.',
            ], 422);
        }

        $record = DB::table('hr_final_appraisals')->where('id', $id)->first();
        if (!$record) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Final appraisal not found.',
            ], 404);
        }

        $data = $request->validated();
        if ((int) $data['staffId'] !== (int) $record->staff_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Final appraisal staff cannot be changed. Create a new final appraisal instead.',
            ], 422);
        }

        DB::table('hr_final_appraisals')->where('id', $id)->update([
            'appraisal_date' => $data['appraisalDate'],
            'work_quality' => (int) $data['workQuality'],
            'teamwork' => (int) $data['teamwork'],
            'leadership' => (int) $data['leadership'],
            'overall_performance' => (int) $data['overallPerformance'],
            'supervisor_comments' => $data['supervisorComments'],
            'salary_increment_recommendation' => $data['salaryIncrementRecommendation'] ?? null,
            'promotion_recommendation' => $data['promotionRecommendation'] ?? null,
            'updated_at' => now(),
        ]);

        $this->auditLog->log($request, "Updated final appraisal #{$id}");

        return response()->json([
            'status' => 'success',
            'message' => 'Final appraisal updated successfully.',
        ]);
    }

    public function finalDestroy(Request $request, int $id)
    {
        if ($response = $this->denyUnlessAppraisalManager($request)) {
            return $response;
        }

        if ($id <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'A valid final appraisal id is required.',
            ], 422);
        }

        $deleted = DB::table('hr_final_appraisals')->where('id', $id)->delete();
        if ($deleted === 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Final appraisal not found.',
            ], 404);
        }

        $this->auditLog->log($request, "Deleted final appraisal #{$id}");

        return response()->json([
            'status' => 'success',
            'message' => 'Final appraisal deleted successfully.',
        ]);
    }
}
