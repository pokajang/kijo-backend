<?php

namespace App\Services\Projects;

use App\Http\Requests\Project\AddCollaboratorRequest;
use App\Http\Requests\Project\AddExpenseRequest;
use App\Http\Requests\Project\AddProgressRequest;
use App\Http\Requests\Project\AssignVendorRequest;
use App\Http\Requests\Project\CloseProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProgressRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectProgressService
{
    private static bool $dompdfAutoloaderRegistered = false;

    public function __construct(private AuditLogService $auditLog) {}

    public function addProgress(AddProgressRequest $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data      = $request->validated();
        $projectId = (int) $data['project_id'];

        $exists = DB::table('projects_main')->where('id', $projectId)->exists();
        if (!$exists) {
            return response()->json(['status' => 'error', 'message' => 'Project not found.']);
        }

        DB::table('project_progress')->insert([
            'project_id'    => $projectId,
            'progress_date' => $data['date'],
            'progress_text' => $data['update'],
            'updated_by'    => $staffId,
            'updated_on'    => now(),
        ]);

        $this->auditLog->log($request, "Added manual progress update to project ID {$projectId}: \"{$data['update']}\"");

        return response()->json(['status' => 'success']);
    }

    public function listProgress(Request $request): JsonResponse
    {
        $projectId = (int) $request->query('project_id', 0);
        if ($projectId < 1) {
            return response()->json(['status' => 'error', 'message' => 'Missing project ID.']);
        }

        $exists = DB::table('projects_main')->where('id', $projectId)->exists();
        if (!$exists) {
            return response()->json(['status' => 'error', 'message' => 'Project not found.']);
        }

        $rows = DB::select("
            SELECT
                pp.id,
                pp.progress_date,
                pp.progress_text,
                COALESCE(sg.name_code, '-') AS updated_by,
                pp.updated_on
            FROM project_progress pp
            LEFT JOIN staff_general sg ON pp.updated_by = sg.staff_id
            WHERE pp.project_id = ?
            ORDER BY pp.progress_date ASC, pp.updated_on ASC
        ", [$projectId]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function updateProgress(UpdateProgressRequest $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data       = $request->validated();
        $progressId = (int) $data['progress_id'];
        $projectId  = (int) $data['project_id'];

        $exists = DB::table('project_progress')
            ->where('id', $progressId)
            ->where('project_id', $projectId)
            ->exists();

        if (!$exists) {
            return response()->json(['status' => 'error', 'message' => 'Progress entry not found.']);
        }

        DB::table('project_progress')
            ->where('id', $progressId)
            ->where('project_id', $projectId)
            ->limit(1)
            ->update([
                'progress_date' => $data['date'],
                'progress_text' => $data['update'],
                'updated_by'    => $staffId,
                'updated_on'    => now(),
            ]);

        $this->auditLog->log($request, "Updated project progress entry {$progressId} for project ID {$projectId}.");

        return response()->json(['status' => 'success']);
    }

    public function deleteProgress(Request $request): JsonResponse
    {
        $progressId = (int) $request->input('progress_id', 0);
        $projectId  = (int) $request->input('project_id', 0);
        $staffId    = (int) $request->session()->get('staff_id', 0);

        if (!$progressId || !$projectId || !$staffId) {
            return response()->json(['status' => 'error', 'message' => 'Missing required fields.']);
        }

        $row = DB::table('project_progress')
            ->where('id', $progressId)
            ->where('project_id', $projectId)
            ->first();

        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Progress entry not found.']);
        }

        DB::table('project_progress')
            ->where('id', $progressId)
            ->where('project_id', $projectId)
            ->limit(1)
            ->delete();

        $this->auditLog->log($request, "Deleted project progress entry {$progressId} for project ID {$projectId}.");

        return response()->json(['status' => 'success']);
    }
}
