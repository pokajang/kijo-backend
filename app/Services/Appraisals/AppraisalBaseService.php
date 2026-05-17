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

abstract class AppraisalBaseService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * POST - create a new appraisal record.
     * Body: section, staffId, eventDate, input (stored as feedback).
     */
    /**
     * GET - list appraisal records, optionally filtered by staff_id and year.
     * Params: staff_id (optional), year (optional, 4-digit).
     * Restricted to appraisal managers.
     */
    /**
     * GET - retrieve one appraisal record by id.
     */
    /**
     * PUT/PATCH - update an existing appraisal.
     * Body: id, eventDate, feedback.
     * Restricted to appraisal managers.
     */
    /**
     * DELETE - hard-delete an appraisal by id.
     * Body: id.
     * Restricted to appraisal managers.
     */

    protected function denyUnlessAppraisalManager(Request $request): ?JsonResponse
    {
        if ($this->canManageAppraisals($request)) {
            return null;
        }

        return response()->json([
            'status'  => 'error',
            'message' => 'Unauthorized access.',
        ], 403);
    }

    protected function canManageAppraisals(Request $request): bool
    {
        foreach ($this->sessionRoles($request) as $role) {
            $role = strtolower($role);
            if (
                str_contains($role, 'hr') ||
                str_contains($role, 'manager') ||
                str_contains($role, 'admin') ||
                str_contains($role, 'super')
            ) {
                return true;
            }
        }

        return false;
    }

    protected function sessionRoles(Request $request): array
    {
        $raw = $request->session()->get('roles', []);
        if (! is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter(array_map(
            fn ($role) => trim((string) $role),
            $raw
        ), fn ($role) => $role !== ''));
    }

    protected function finalAppraisalQuery()
    {
        return DB::table('hr_final_appraisals as fa')
            ->join('staff_general as sg', 'fa.staff_id', '=', 'sg.staff_id')
            ->leftJoin('staff_general as cb', 'fa.created_by', '=', 'cb.staff_id')
            ->select([
                'fa.id',
                'fa.staff_id',
                'fa.appraisal_date',
                'fa.work_quality',
                'fa.teamwork',
                'fa.leadership',
                'fa.overall_performance',
                'fa.supervisor_comments',
                'fa.salary_increment_recommendation',
                'fa.promotion_recommendation',
                'fa.created_by',
                'fa.created_at',
                'fa.updated_at',
                'sg.full_name as staff_name',
                'sg.name_code as staff_code',
                'sg.position as staff_position',
                'sg.department as staff_department',
                'cb.full_name as creator_name',
                'cb.name_code as creator_code',
                'cb.position as creator_position',
                'cb.department as creator_department',
            ]);
    }
}
