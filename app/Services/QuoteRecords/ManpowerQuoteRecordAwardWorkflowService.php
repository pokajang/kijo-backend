<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AwardQuoteRequest;
use App\Http\Requests\QuoteRecord\FailQuoteRequest;
use App\Http\Requests\QuoteRecord\UnAwardQuoteRequest;
use App\Services\AuditLogService;
use App\Services\Projects\ProjectCollaboratorAssignmentService;
use App\Services\Projects\ProjectValueService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManpowerQuoteRecordAwardWorkflowService
{
    public function __construct(private AuditLogService $auditLog) {}

    public function awardManpower(AwardQuoteRequest $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');
        $remarks = trim((string) $request->input('remarks', ''));
        $awardDate = $request->input('award_date') ?: now()->format('Y-m-d');
        $description = (string) $request->input('description', '');
        $clientRefNo = $request->input('client_award_ref_no');

        DB::beginTransaction();
        try {
            DB::table('quotes_manpower')->where('id', $quoteId)->update([
                'status' => 'Awarded',
                'status_remarks' => $remarks,
                'award_date' => $awardDate,
                'client_award_ref_no' => $clientRefNo,
                'updated_at' => now(),
            ]);

            $quote = DB::table('quotes_manpower')->where('id', $quoteId)->first();
            if (! $quote) {
                throw new \Exception('Manpower quotation not found.');
            }

            $duplicate = DB::table('projects_main')
                ->where('quote_id', $quoteId)
                ->whereRaw("LOWER(project_type) LIKE '%manpower%'")
                ->count();
            if ($duplicate > 0) {
                throw new \Exception('This Manpower quotation is already linked to a project.');
            }

            $newProjectId = DB::table('projects_main')->insertGetId($this->withProjectProposalLanguage([
                'client_id' => $quote->client_id,
                'quote_id' => $quoteId,
                'project_name' => $quote->service_title,
                'project_type' => 'Manpower Supply',
                'quote_type' => 'manpower',
                'po_loa_number' => $clientRefNo,
                'description' => $description,
                'status' => 'Active',
                'quote_value' => $quote->grand_total,
                'award_date' => $awardDate,
                'created_at' => now(),
            ], $quote->proposal_language ?? 'en'));

            app(ProjectValueService::class)->applyAwardModalAdjustment($newProjectId, $request, 'manpower', $quoteId);
            $this->insertProjectProgress($newProjectId, 'Manpower quotation marked as Awarded. Project started.', $request);
            app(ProjectCollaboratorAssignmentService::class)
                ->assignInitialCollaborators($newProjectId, $request);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        $this->auditLog->log($request, "Marked Manpower quotation ID #{$quoteId} as Awarded and created project ID #{$newProjectId}");

        return response()->json([
            'status' => 'success',
            'message' => 'Manpower quotation awarded and project created successfully.',
            'project_id' => $newProjectId,
        ]);
    }

    public function failManpower(FailQuoteRequest $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');
        $remarks = trim((string) $request->input('remarks', ''));

        $row = DB::table('quotes_manpower')->where('id', $quoteId)->first();
        if (! $row) {
            return response()->json(['status' => 'error', 'message' => 'Manpower quotation not found.'], 404);
        }
        if (strtolower(trim($row->status)) === 'failed') {
            return response()->json(['status' => 'error', 'message' => 'Quotation is already marked as Failed.'], 422);
        }

        DB::table('quotes_manpower')->where('id', $quoteId)->update([
            'status' => 'Failed',
            'status_remarks' => $remarks,
            'updated_at' => now(),
        ]);

        $this->auditLog->log($request, "Marked Manpower quotation ID #{$quoteId} as Failed");

        return response()->json(['status' => 'success', 'message' => 'Quotation marked as Failed.']);
    }

    public function reAwardManpower(AwardQuoteRequest $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');
        $remarks = trim((string) $request->input('remarks', ''));
        $awardDate = $request->input('award_date') ?: now()->format('Y-m-d');
        $description = trim((string) $request->input('description', 'Re-awarded project from existing awarded quotation.'));
        $clientRefNo = $request->input('client_award_ref_no');

        DB::beginTransaction();
        try {
            $quote = DB::table('quotes_manpower')->where('id', $quoteId)->first();
            if (! $quote) {
                throw new \Exception('Manpower quotation not found.');
            }
            if (strtolower(trim((string) $quote->status)) !== 'awarded') {
                throw new \Exception('Only Awarded quotations can be re-awarded.');
            }

            $newProjectId = DB::table('projects_main')->insertGetId($this->withProjectProposalLanguage([
                'client_id' => $quote->client_id,
                'quote_id' => $quoteId,
                'project_name' => $quote->service_title,
                'project_type' => 'Manpower Supply',
                'quote_type' => 'manpower',
                'po_loa_number' => $clientRefNo,
                'description' => $description,
                'status' => 'Active',
                'quote_value' => $quote->grand_total,
                'award_date' => $awardDate,
                'created_at' => now(),
            ], $quote->proposal_language ?? 'en'));

            app(ProjectValueService::class)->applyAwardModalAdjustment($newProjectId, $request, 'manpower', $quoteId);
            $this->insertProjectProgress($newProjectId, 'New project created from Re-Award (existing quote).', $request);
            app(ProjectCollaboratorAssignmentService::class)
                ->assignInitialCollaborators($newProjectId, $request);

            $awardCount = DB::table('projects_main')
                ->where('quote_id', $quoteId)
                ->whereRaw("LOWER(project_type) LIKE '%manpower%'")
                ->count();
            $awardCount = max(1, $awardCount);

            $statusRemark = $remarks !== '' ? $remarks : 'Re-Awarded';

            DB::table('quotes_manpower')->where('id', $quoteId)->update([
                'status' => 'Awarded',
                'status_remarks' => $statusRemark,
                'award_date' => $awardDate,
                'client_award_ref_no' => $clientRefNo,
                'updated_at' => now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        $this->auditLog->log($request, "Re-awarded manpower quote ID #{$quoteId} and created project ID #{$newProjectId}");

        return response()->json([
            'status' => 'success',
            'message' => 'Re-awarded successfully. Project created.',
            'award_count' => $awardCount,
            'status_remarks' => $statusRemark,
        ]);
    }

    public function unAwardManpower(UnAwardQuoteRequest $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');

        DB::beginTransaction();
        try {
            $quote = DB::table('quotes_manpower')
                ->where('id', $quoteId)
                ->lockForUpdate()
                ->first();

            if (! $quote) {
                throw new \Exception('Manpower quotation not found.');
            }
            if (strtolower(trim((string) $quote->status)) !== 'awarded') {
                throw new \Exception('Only Awarded quotations can be un-awarded.');
            }

            $projects = DB::select("
                SELECT id, award_date, created_at
                FROM projects_main
                WHERE quote_id = ?
                  AND LOWER(project_type) LIKE '%manpower%'
                ORDER BY COALESCE(created_at, '1970-01-01') DESC, id DESC
            ", [$quoteId]);

            $linkedCount = count($projects);
            $targetProjectId = $linkedCount > 0 ? (int) $projects[0]->id : null;

            if ($targetProjectId) {
                $this->guardLinkedProject($targetProjectId);
                $this->deleteProjectWithChildren($targetProjectId);
            }

            $remainingProjects = max(0, $linkedCount - ($targetProjectId ? 1 : 0));

            if ($remainingProjects > 0) {
                $latest = DB::table('projects_main')
                    ->where('quote_id', $quoteId)
                    ->whereRaw("LOWER(project_type) LIKE '%manpower%'")
                    ->orderByRaw("COALESCE(award_date, created_at, '1970-01-01') DESC")
                    ->orderByDesc('id')
                    ->first();

                DB::table('quotes_manpower')->where('id', $quoteId)->update([
                    'status' => 'Awarded',
                    'status_remarks' => $remainingProjects > 1 ? 'Re-Awarded' : 'Awarded',
                    'award_date' => $latest->award_date ?? null,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('quotes_manpower')->where('id', $quoteId)->update([
                    'status' => 'Open',
                    'status_remarks' => 'Un-awarded by user.',
                    'award_date' => null,
                    'client_award_ref_no' => null,
                    'updated_at' => now(),
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $code = $e instanceof QueryException ? 500 : 400;

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }

        $deletedCount = $targetProjectId ? 1 : 0;
        $this->auditLog->log($request, "Un-awarded Manpower quotation ID #{$quoteId}; removed {$deletedCount} latest linked project(s), remaining {$remainingProjects}");

        if (! $targetProjectId) {
            $message = 'No linked project found. Quotation reset to Open.';
        } elseif ($remainingProjects > 0) {
            $message = "Latest award removed. Quotation remains Awarded with {$remainingProjects} linked project(s).";
        } else {
            $message = 'Quotation un-awarded successfully.';
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'deleted_projects' => $deletedCount,
            'remaining_projects' => $remainingProjects,
        ]);
    }

    private function insertProjectProgress(int $projectId, string $text, Request $request): void
    {
        if ($projectId <= 0 || $text === '') {
            return;
        }
        try {
            DB::table('project_progress')->insert([
                'project_id' => $projectId,
                'progress_date' => now()->format('Y-m-d'),
                'progress_text' => $text,
                'updated_by' => (int) $request->session()->get('staff_id', 0) ?: null,
                'updated_on' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function deleteProjectWithChildren(int $projectId): void
    {
        foreach ([
            'project_closing_details',
            'project_collaborators',
            'project_progress',
            'project_vendors',
            'project_expenses',
        ] as $table) {
            DB::table($table)->where('project_id', $projectId)->delete();
        }
        DB::table('projects_main')->where('id', $projectId)->delete();
    }

    private function guardLinkedProject(int $projectId): void
    {
        $invoices = DB::table('invoices')->where('project_id', $projectId)->count();
        if ($invoices > 0) {
            throw new \Exception("Cannot un-award. Linked project #{$projectId} has invoice records.");
        }

        $dos = DB::table('do_details')->where('project_id', $projectId)->count();
        if ($dos > 0) {
            throw new \Exception("Cannot un-award. Linked project #{$projectId} has delivery order records.");
        }

        $vendorLoas = DB::table('project_vendors')->where('project_id', $projectId)->count();
        if ($vendorLoas > 0) {
            throw new \Exception("Cannot un-award. Linked project #{$projectId} has vendor LOA records.");
        }

        $vendorPayments = DB::table('vendor_payments')
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->count();
        if ($vendorPayments > 0) {
            throw new \Exception("Cannot un-award. Linked project #{$projectId} has vendor payment records.");
        }
    }

    private function withProjectProposalLanguage(array $payload, mixed $language): array
    {
        if (Schema::hasColumn('projects_main', 'current_project_value')) {
            $payload['current_project_value'] = null;
        }
        if (Schema::hasColumn('projects_main', 'proposal_language')) {
            $payload['proposal_language'] = $language ?: 'en';
        }

        return $payload;
    }
}
