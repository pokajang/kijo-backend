<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kpi\StoreKpiParametersRequest;
use App\Http\Requests\Kpi\UpdateKpiParametersRequest;
use App\Http\Requests\Kpi\UpdateKpiTrackerRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KpiController extends Controller
{
    public function __construct(private AuditLogService $auditLog) {}

    // -------------------------------------------------------------------------
    // KPI Parameters
    // -------------------------------------------------------------------------

    /**
     * POST — body is a bare JSON array of KPI parameter objects.
     * Inserts all items in a single transaction.
     */
    public function createKpiParameters(StoreKpiParametersRequest $request): JsonResponse
    {
        $items     = $request->validated();
        $sessionId = (int) $request->session()->get('staff_id');
        $now       = now();

        DB::beginTransaction();
        try {
            $insertedIds = [];

            foreach ($items as $item) {
                $staffId = isset($item['staff_id']) ? (int) $item['staff_id'] : $sessionId;

                $id = DB::table('hr_kpi_parameters')->insertGetId([
                    'staff_id'       => $staffId,
                    'parameter_name' => $item['parameter_name'],
                    'description'    => $item['description'],
                    'annual_target'  => (int) $item['annual_target'],
                    'unit'           => $item['unit'],
                    'weightage'      => (float) $item['weightage'],
                    'year'           => (int) $item['year'],
                    'created_by'     => $sessionId,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);

                $insertedIds[] = $id;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to insert KPI parameters: ' . $e->getMessage(),
            ], 500);
        }

        $this->auditLog->log($request, 'Created ' . count($insertedIds) . ' KPI parameter(s): IDs ' . implode(', ', $insertedIds));

        return response()->json([
            'status'       => 'success',
            'message'      => 'KPI parameters created successfully.',
            'inserted_ids' => $insertedIds,
        ]);
    }

    /**
     * POST — body: staff_id (required), year (optional).
     * Returns parameters list plus distinct years for this staff member.
     */
    public function getAllKpiParameters(Request $request): JsonResponse
    {
        $request->validate([
            'staff_id' => ['required', 'numeric'],
            'year'     => ['sometimes', 'numeric'],
        ]);

        $staffId = (int) $request->input('staff_id');
        $year    = $request->input('year');

        $query = DB::table('hr_kpi_parameters')
            ->select(['id', 'parameter_name', 'description', 'annual_target', 'unit', 'weightage', 'year'])
            ->where('staff_id', $staffId);

        if ($year !== null) {
            $query->where('year', (int) $year);
        }

        $data = $query->orderByDesc('year')->orderBy('parameter_name')->get();

        $years = DB::table('hr_kpi_parameters')
            ->where('staff_id', $staffId)
            ->orderByDesc('year')
            ->distinct()
            ->pluck('year');

        return response()->json([
            'status' => 'success',
            'data'   => $data,
            'years'  => $years,
        ]);
    }

    /**
     * POST — uses session staff_id. Optional year in body.
     */
    public function getMyKpiParameters(Request $request): JsonResponse
    {
        $request->validate([
            'year' => ['sometimes', 'numeric'],
        ]);

        $staffId = (int) $request->session()->get('staff_id');
        $year    = $request->input('year');

        $query = DB::table('hr_kpi_parameters')
            ->select(['id', 'parameter_name', 'description', 'annual_target', 'unit', 'weightage', 'year'])
            ->where('staff_id', $staffId);

        if ($year !== null) {
            $query->where('year', (int) $year);
        }

        $data = $query->orderByDesc('year')->orderBy('parameter_name')->get();

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }

    /**
     * POST — body is a bare JSON array of KPI objects to update.
     * Updates are scoped to the session user as owner.
     */
    public function updateKpiParameters(UpdateKpiParametersRequest $request): JsonResponse
    {
        $items     = $request->validated();
        $sessionId = (int) $request->session()->get('staff_id');
        $now       = now();

        DB::beginTransaction();
        try {
            $updatedIds = [];

            foreach ($items as $item) {
                $affected = DB::table('hr_kpi_parameters')
                    ->where('id', (int) $item['id'])
                    ->where('staff_id', $sessionId)
                    ->update([
                        'parameter_name' => $item['parameter_name'],
                        'description'    => $item['description'],
                        'annual_target'  => (int) $item['annual_target'],
                        'unit'           => $item['unit'],
                        'weightage'      => (float) $item['weightage'],
                        'year'           => (int) $item['year'],
                        'updated_at'     => $now,
                    ]);

                if ($affected > 0) {
                    $updatedIds[] = (int) $item['id'];
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update KPI parameters: ' . $e->getMessage(),
            ], 500);
        }

        $this->auditLog->log($request, 'Updated KPI parameter(s): IDs ' . implode(', ', $updatedIds));

        return response()->json([
            'status'      => 'success',
            'message'     => 'KPI parameters updated successfully.',
            'updated_ids' => $updatedIds,
        ]);
    }

    /**
     * POST/DELETE — body: id (required, numeric).
     * Deletes if session user is owner (staff_id) or creator (created_by).
     */
    public function deleteKpiParameter(Request $request): JsonResponse
    {
        $request->validate([
            'id' => ['required', 'numeric'],
        ]);

        $id        = (int) $request->input('id');
        $sessionId = (int) $request->session()->get('staff_id');

        $affected = DB::table('hr_kpi_parameters')
            ->where('id', $id)
            ->where(function ($q) use ($sessionId) {
                $q->where('staff_id', $sessionId)
                  ->orWhere('created_by', $sessionId);
            })
            ->delete();

        if ($affected === 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'KPI parameter not found or you do not have permission to delete it.',
            ], 404);
        }

        $this->auditLog->log($request, "Deleted KPI parameter #{$id}");

        return response()->json([
            'status'  => 'success',
            'message' => 'KPI parameter deleted successfully.',
        ]);
    }

    // -------------------------------------------------------------------------
    // KPI Tracker
    // -------------------------------------------------------------------------

    /**
     * POST — body: staff_id (required), kpi_id (required), year (required), all numeric.
     */
    public function getAllKpiTracker(Request $request): JsonResponse
    {
        $request->validate([
            'staff_id' => ['required', 'numeric'],
            'kpi_id'   => ['required', 'numeric'],
            'year'     => ['required', 'numeric'],
        ]);

        $staffId = (int) $request->input('staff_id');
        $kpiId   = (int) $request->input('kpi_id');
        $year    = (int) $request->input('year');

        $data = DB::table('hr_kpi_parameters_tracker')
            ->select([
                'id', 'kpi_id', 'staff_id', 'for_month',
                'actual_value', 'remarks', 'created_by',
                'created_at', 'updated_at',
            ])
            ->where('staff_id', $staffId)
            ->where('kpi_id', $kpiId)
            ->whereYear('for_month', $year)
            ->orderBy('for_month')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }

    /**
     * POST — body: kpi_id (required), month (required, "YYYY-MM" or "YYYY-MM-DD").
     * Uses session staff_id. Returns a single row or null.
     */
    public function getKpiTracker(Request $request): JsonResponse
    {
        $request->validate([
            'kpi_id' => ['required', 'numeric'],
            'month'  => ['required', 'string'],
        ]);

        $kpiId     = (int) $request->input('kpi_id');
        $staffId   = (int) $request->session()->get('staff_id');
        $forMonth  = date('Y-m-01', strtotime($request->input('month')));

        $row = DB::table('hr_kpi_parameters_tracker')
            ->select([
                'id', 'kpi_id', 'staff_id', 'for_month',
                'actual_value', 'remarks', 'created_by',
                'created_at', 'updated_at',
            ])
            ->where('kpi_id', $kpiId)
            ->where('staff_id', $staffId)
            ->where('for_month', $forMonth)
            ->limit(1)
            ->first();

        return response()->json([
            'status' => 'success',
            'data'   => $row,
        ]);
    }

    /**
     * POST — body: kpi_id (required), year (required), both numeric.
     * Uses session staff_id.
     */
    public function getMyKpiTracker(Request $request): JsonResponse
    {
        $request->validate([
            'kpi_id' => ['required', 'numeric'],
            'year'   => ['required', 'numeric'],
        ]);

        $kpiId   = (int) $request->input('kpi_id');
        $year    = (int) $request->input('year');
        $staffId = (int) $request->session()->get('staff_id');

        $data = DB::table('hr_kpi_parameters_tracker')
            ->select([
                'id', 'kpi_id', 'staff_id', 'for_month',
                'actual_value', 'remarks', 'created_by',
                'created_at', 'updated_at',
            ])
            ->where('kpi_id', $kpiId)
            ->where('staff_id', $staffId)
            ->whereYear('for_month', $year)
            ->orderBy('for_month')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }

    /**
     * POST — body: kpi_id, month ("YYYY-MM"), target (numeric → actual_value), remarks (optional).
     * Uses session staff_id. Upserts on (kpi_id, staff_id, for_month) unique key.
     */
    public function updateKpiTracker(UpdateKpiTrackerRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $staffId   = (int) $request->session()->get('staff_id');
        $kpiId     = (int) $validated['kpi_id'];
        $forMonth  = date('Y-m-01', strtotime($validated['month']));
        $target    = $validated['target'];
        $remarks   = $validated['remarks'] ?? null;

        DB::statement(
            'INSERT INTO hr_kpi_parameters_tracker
                (kpi_id, staff_id, for_month, actual_value, remarks, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                actual_value = VALUES(actual_value),
                remarks      = VALUES(remarks),
                created_by   = VALUES(created_by),
                updated_at   = NOW()',
            [$kpiId, $staffId, $forMonth, $target, $remarks, $staffId]
        );

        $this->auditLog->log($request, "Upserted KPI tracker for kpi_id={$kpiId}, staff_id={$staffId}, month={$forMonth}");

        return response()->json([
            'status'  => 'success',
            'message' => 'KPI tracker updated successfully.',
        ]);
    }
}
