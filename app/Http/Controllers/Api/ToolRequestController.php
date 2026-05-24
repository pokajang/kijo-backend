<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ToolRequest\StoreToolRequestRequest;
use App\Http\Requests\ToolRequest\UpdateAchievementRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ToolRequestController extends Controller
{
    public function __construct(private AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $year = (int) $request->query('year', 0);

        $query = DB::table('tool_requests as tr')
            ->join('staff_general as sg', 'sg.staff_id', '=', 'tr.staff_id')
            ->select([
                'tr.id', 'tr.staff_id',
                'sg.name_code as staff_name',
                'tr.equipment_detail', 'tr.use_start_date', 'tr.use_end_date',
                'tr.purpose', 'tr.remarks', 'tr.achievement',
                'tr.created_at', 'tr.updated_at',
            ]);

        if ($year >= 2000 && $year <= 2100) {
            $query->whereYear('tr.created_at', $year);
        }

        $paginator = $query
            ->orderBy('tr.created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status'     => 'success',
            'requests'   => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreToolRequestRequest $request)
    {
        $data      = $request->validated();
        $staffId   = $request->session()->get('staff_id');
        $staffName = $request->session()->get('full_name', '');

        DB::beginTransaction();
        try {
            $newId = DB::table('tool_requests')->insertGetId([
                'staff_id'         => $staffId,
                'equipment_detail' => $data['equipmentDetail'],
                'use_start_date'   => $data['useStartDate'],
                'use_end_date'     => $data['useEndDate'],
                'purpose'          => $data['purpose'],
                'remarks'          => $data['remarks'] ?? null,
            ]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Created tool request #{$newId}");

        SendHtmlMailJob::dispatch(
            'azam@amiosh.com',
            'System Admin',
            "New Tool Request #{$newId} (Staff {$staffId} - {$staffName})",
            "<p>A new tool request has been submitted in KIJO.</p>
             <p><strong>Requester:</strong> " . htmlspecialchars($staffName) . " (ID: {$staffId})</p>
             <p><strong>Equipment Detail:</strong> " . htmlspecialchars($data['equipmentDetail']) . "</p>
             <p><strong>Use Start Date:</strong> {$data['useStartDate']}</p>
             <p><strong>Use End Date:</strong> {$data['useEndDate']}</p>
             <p><strong>Purpose:</strong><br/>" . nl2br(htmlspecialchars($data['purpose'])) . "</p>
             <p><strong>Remarks:</strong><br/>" . nl2br(htmlspecialchars($data['remarks'] ?? '-')) . "</p>
             <p>Please note: company equipment is for work-related use only.</p>",
            ['hr.amiosh@gmail.com', 'kamarul@amiosh.com'],
        );

        return response()->json(['status' => 'success', 'id' => $newId]);
    }

    public function updateAchievement(UpdateAchievementRequest $request, int $id)
    {
        $staffId = $request->session()->get('staff_id');

        DB::beginTransaction();
        try {
            $affected = DB::table('tool_requests')
                ->where('id', $id)
                ->where('staff_id', $staffId)
                ->update(['achievement' => $request->validated()['achievement']]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        if ($affected === 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No matching record found or not authorized to update.',
            ], 403);
        }

        $this->auditLog->log($request, "Updated tool request achievement #{$id}");
        return response()->json(['status' => 'success']);
    }
}
