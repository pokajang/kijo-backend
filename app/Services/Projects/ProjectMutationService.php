<?php

namespace App\Services\Projects;

use App\Http\Requests\Project\CloseProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProjectMutationService
{
    private static bool $dompdfAutoloaderRegistered = false;

    public function __construct(private AuditLogService $auditLog) {}

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data = $request->validated();
        $projectType = trim($data['project_type'] ?? '');
        $typeLower = strtolower($projectType);

        $quoteType = null;
        if ($typeLower !== '') {
            if (str_contains($typeLower, 'training')) {
                $quoteType = 'training';
            } elseif (str_contains($typeLower, 'industrial') || str_contains($typeLower, 'ih')) {
                $quoteType = 'ih';
            } elseif (str_contains($typeLower, 'manpower')) {
                $quoteType = 'manpower';
            } elseif (str_contains($typeLower, 'equipment')) {
                $quoteType = 'equipment';
            } elseif (str_contains($typeLower, 'special')) {
                $quoteType = 'special';
            }
        }

        $insert = [
            'client_id' => $data['client_id'],
            'project_name' => $data['project_name'],
            'project_type' => $projectType,
            'quote_type' => $quoteType,
            'po_loa_number' => $data['po_loa_number'] ?? '',
            'quote_value' => $data['quote_value'] ?? 0.00,
            'award_date' => $data['award_date'] ?? null,
            'service_start_date' => $data['service_start_date'] ?? null,
            'service_end_date' => $data['service_end_date'] ?? null,
            'description' => $data['description'] ?? '',
            'status' => 'Active',
            'created_at' => now(),
            'created_by' => $staffId,
        ];
        if (Schema::hasColumn('projects_main', 'proposal_language')) {
            $insert['proposal_language'] = $data['proposal_language'] ?? 'en';
        }
        if (Schema::hasColumn('projects_main', 'current_project_value')) {
            $insert['current_project_value'] = null;
        }

        $newProjectId = DB::table('projects_main')->insertGetId($insert);

        $this->insertProgress(
            $newProjectId,
            'Project started without linking to a quotation record.',
            $request
        );

        $this->auditLog->log($request, "New project \"{$data['project_name']}\" created with ID #{$newProjectId}");

        return response()->json(['status' => 'success', 'message' => 'Project created successfully.']);
    }

    public function update(UpdateProjectRequest $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data = $request->validated();
        $projectId = (int) ($data['project_id'] ?? $request->input('id'));

        $updates = [
            'project_name' => $data['project_name'],
            'project_type' => $data['project_type'] ?? '',
            'award_date' => $data['award_date'] ?? null,
            'service_start_date' => $data['service_start_date'] ?? null,
            'service_end_date' => $data['service_end_date'] ?? null,
            'description' => $data['description'] ?? '',
            'po_loa_number' => $data['po_loa_number'] ?? '',
            'updated_at' => now(),
            'updated_by' => $staffId,
        ];
        if (Schema::hasColumn('projects_main', 'proposal_language')) {
            $updates['proposal_language'] = $data['proposal_language']
                ?? DB::table('projects_main')->where('id', $projectId)->value('proposal_language')
                ?? 'en';
        }

        DB::table('projects_main')->where('id', $projectId)->update($updates);

        $poLoa = $data['po_loa_number'] ?? '';
        $this->auditLog->log(
            $request,
            "Updated project #{$projectId}: \"{$data['project_name']}\", LOA/PO# {$poLoa}"
        );

        return response()->json(['status' => 'success', 'message' => 'Project updated successfully.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $invoiceCount = DB::table('invoices')->where('project_id', $id)->count();
        if ($invoiceCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Project is referenced in invoices. Please remove related invoices first.',
            ]);
        }

        $doCount = DB::table('do_details')->where('project_id', $id)->count();
        if ($doCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Project is referenced in delivery orders. Please remove related DOs first.',
            ]);
        }

        DB::beginTransaction();
        try {
            $project = DB::table('projects_main')
                ->select(['quote_id', 'project_type'])
                ->where('id', $id)
                ->first();

            if ($project && ! empty($project->quote_id) && ! empty($project->project_type)) {
                $quoteId = (int) $project->quote_id;
                $typeLower = strtolower(trim($project->project_type));

                $tableMap = [
                    'training' => 'quotes_training',
                    'equipment supply' => 'quotes_equipment',
                    'industrial hygiene' => 'quotes_ih',
                    'manpower supply' => 'quotes_manpower',
                    'special service' => 'quotes_special',
                ];

                if (isset($tableMap[$typeLower])) {
                    DB::table($tableMap[$typeLower])->where('id', $quoteId)->update([
                        'status' => 'Open',
                        'status_remarks' => 'User deleted the project. Status reset to Open.',
                    ]);
                }
            }

            foreach (['project_closing_details', 'project_collaborators', 'project_progress', 'project_vendors'] as $table) {
                DB::table($table)->where('project_id', $id)->delete();
            }

            DB::table('projects_main')->where('id', $id)->delete();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Failed to delete project: '.$e->getMessage()]);
        }

        $this->auditLog->log($request, "Deleted project ID #{$id} and reset linked quote (if any) to 'Open'");

        return response()->json(['status' => 'success', 'message' => 'Project deleted and related quote reset to Open.']);
    }

    public function close(CloseProjectRequest $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data = $request->validated();
        $projectId = (int) $data['project_id'];
        $closeType = $data['closeType'];

        DB::beginTransaction();
        try {
            $project = DB::table('projects_main')
                ->where('id', $projectId)
                ->lockForUpdate()
                ->first(['id', 'status']);

            if (! $project) {
                DB::rollBack();

                return response()->json(['status' => 'error', 'message' => 'Project not found.']);
            }

            $projectStatus = strtolower(trim((string) ($project->status ?? '')));
            if (in_array($projectStatus, ['completed', 'terminated', 'closed'], true)) {
                DB::rollBack();

                return response()->json(['status' => 'error', 'message' => 'Project is already closed.']);
            }

            DB::table('project_closing_details')->insert([
                'project_id' => $projectId,
                'close_date' => $data['closeDate'],
                'close_type' => $closeType,
                'reason' => $data['reason'],
                'claims_ok' => (int) ($data['claims'] ?? false),
                'vendors_ok' => (int) ($data['vendors'] ?? false),
                'services_ok' => (int) ($data['services'] ?? false),
                'closed_by' => $staffId,
                'closed_at' => now(),
            ]);

            DB::table('projects_main')->where('id', $projectId)->update(['status' => $closeType]);

            $nameCode = DB::table('staff_general')
                ->where('staff_id', $staffId)
                ->value('name_code') ?: "STAFF#{$staffId}";

            $progressMsg = "Project marked as {$closeType} by {$nameCode}.";
            $this->insertProgress($projectId, $progressMsg, $request);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Database error: '.$e->getMessage()]);
        }

        $this->auditLog->log($request, "Project ID #{$projectId} was marked as {$closeType}");

        return response()->json(['status' => 'success', 'message' => 'Project closed successfully.']);
    }

    public function reloadPoNumber(Request $request): JsonResponse
    {
        $projectId = (int) ($request->input('project_id') ?? $request->input('id', 0));
        if ($projectId < 1) {
            return response()->json(['status' => 'error', 'message' => 'Missing project_id.']);
        }

        $project = DB::table('projects_main')
            ->select(['id', 'project_type', 'quote_id', 'po_loa_number'])
            ->where('id', $projectId)
            ->first();

        if (! $project) {
            return response()->json(['status' => 'error', 'message' => 'Project not found.']);
        }

        if (trim((string) ($project->po_loa_number ?? '')) !== '') {
            return response()->json(['status' => 'exists', 'message' => 'PO/LOA number already set.']);
        }

        if (empty($project->quote_id)) {
            return response()->json(['status' => 'error', 'message' => 'Project has no quote reference.']);
        }

        $tableMap = [
            'Training' => 'quotes_training',
            'Equipment Supply' => 'quotes_equipment',
            'Manpower Supply' => 'quotes_manpower',
            'Industrial Hygiene' => 'quotes_ih',
            'Special Service' => 'quotes_special',
        ];

        $table = $tableMap[trim((string) $project->project_type)] ?? null;
        if (! $table) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported project type.']);
        }

        $refNo = DB::table($table)
            ->where('id', $project->quote_id)
            ->value('client_award_ref_no');

        $refNo = is_string($refNo) ? trim($refNo) : '';
        if ($refNo === '') {
            return response()->json(['status' => 'error', 'message' => 'Quotation has no PO/LOA number.']);
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        DB::table('projects_main')->where('id', $projectId)->update([
            'po_loa_number' => $refNo,
            'updated_at' => now(),
            'updated_by' => $staffId,
        ]);

        $this->auditLog->log($request, "Reloaded PO/LOA number for project #{$projectId}: {$refNo}");

        return response()->json(['status' => 'success', 'po_loa_number' => $refNo]);
    }

    private function insertProgress(
        int $projectId,
        string $activity,
        $request,
        ?int $updatedBy = null,
        ?string $date = null
    ): void {
        if (! $projectId || $activity === '') {
            return;
        }

        $staffId = $updatedBy ?? (int) $request->session()->get('staff_id', 0);
        $date = $date ?? now()->format('Y-m-d');

        try {
            DB::table('project_progress')->insert([
                'project_id' => $projectId,
                'progress_date' => $date,
                'progress_text' => $activity,
                'updated_by' => $staffId ?: null,
                'updated_on' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
