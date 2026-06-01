<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AwardQuoteRequest;
use App\Http\Requests\QuoteRecord\FailQuoteRequest;
use App\Http\Requests\QuoteRecord\UnAwardQuoteRequest;
use App\Services\AuditLogService;
use App\Services\Projects\ProjectCollaboratorAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrainingQuoteRecordAwardWorkflowService
{
    public function __construct(private AuditLogService $auditLog) {}

    public function awardTraining(AwardQuoteRequest $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');
        $remarks = $request->input('remarks', '');
        $awardDate = $request->input('award_date', now()->format('Y-m-d'));
        $description = $request->input('description', 'Notes: To be implemented later');
        $clientRefNo = $request->input('client_award_ref_no');

        DB::beginTransaction();
        try {
            DB::table('quotes_training')
                ->where('id', $quoteId)
                ->update([
                    'status' => 'Awarded',
                    'status_remarks' => $remarks,
                    'award_date' => $awardDate,
                    'client_award_ref_no' => $clientRefNo,
                    'updated_at' => now(),
                ]);

            $quote = DB::table('quotes_training')->where('id', $quoteId)->first();
            if (! $quote) {
                throw new \Exception('Quote not found.');
            }

            $existing = DB::table('projects_main')
                ->where('quote_id', $quoteId)
                ->where('project_type', 'Training')
                ->count();
            if ($existing > 0) {
                throw new \Exception('This Training quotation is already linked to a Training project.');
            }

            [$serviceStartDate, $serviceEndDate] = $this->trainingServiceDates($quote);

            DB::table('projects_main')->insert($this->withProjectProposalLanguage([
                'client_id' => $quote->client_id,
                'quote_id' => $quoteId,
                'project_name' => $quote->training_title,
                'project_type' => 'Training',
                'quote_type' => 'training',
                'po_loa_number' => $clientRefNo,
                'description' => $description,
                'status' => 'Active',
                'quote_value' => $quote->grand_total,
                'award_date' => $awardDate,
                'service_start_date' => $serviceStartDate,
                'service_end_date' => $serviceEndDate,
                'created_at' => now(),
            ], $quote->proposal_language ?? 'en'));

            $newProjectId = (int) DB::getPdo()->lastInsertId();
            if (! $newProjectId) {
                $newProjectId = (int) DB::table('projects_main')->orderByDesc('id')->value('id');
            }

            $this->insertProgress($newProjectId, 'The quotation is marked as Awarded. Project started.', $request);
            app(ProjectCollaboratorAssignmentService::class)
                ->assignInitialCollaborators($newProjectId, $request);

            DB::commit();

            $this->auditLog->log($request, "Marked training quote ID #{$quoteId} as Awarded and created project ID #{$newProjectId}");

            return response()->json([
                'status' => 'success',
                'message' => 'Quote awarded and project created.',
                'project_id' => $newProjectId,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function failTraining(FailQuoteRequest $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');
        $remarks = $request->input('remarks');

        $row = DB::table('quotes_training')->where('id', $quoteId)->first(['status']);
        if (! $row) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        if (strtolower(trim($row->status)) === 'failed') {
            return response()->json(['status' => 'error', 'message' => 'Quote is already marked as Failed.']);
        }

        DB::table('quotes_training')
            ->where('id', $quoteId)
            ->update([
                'status' => 'Failed',
                'status_remarks' => $remarks,
                'updated_at' => now(),
            ]);

        $this->auditLog->log($request, "Marked training quote ID #{$quoteId} as Failed");

        return response()->json([
            'status' => 'success',
            'message' => 'Quote marked as Failed. If this quote was previously awarded, please ensure the related project is also closed in Project Management.',
        ]);
    }

    public function reAwardTraining(AwardQuoteRequest $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');
        $remarks = trim($request->input('remarks', ''));
        $awardDate = $request->input('award_date', now()->format('Y-m-d'));
        $description = trim($request->input('description', 'Re-awarded project from existing awarded quotation.'));
        $clientRefNo = $request->input('client_award_ref_no');

        DB::beginTransaction();
        try {
            $quote = DB::table('quotes_training')->where('id', $quoteId)->first();
            if (! $quote) {
                throw new \Exception('Quote not found.');
            }
            if (strtolower(trim((string) $quote->status)) !== 'awarded') {
                throw new \Exception('Only Awarded quotations can be re-awarded.');
            }

            [$serviceStartDate, $serviceEndDate] = $this->trainingServiceDates($quote);

            DB::table('projects_main')->insert($this->withProjectProposalLanguage([
                'client_id' => $quote->client_id,
                'quote_id' => $quoteId,
                'project_name' => $quote->training_title,
                'project_type' => 'Training',
                'quote_type' => 'training',
                'po_loa_number' => $clientRefNo,
                'description' => $description,
                'status' => 'Active',
                'quote_value' => $quote->grand_total,
                'award_date' => $awardDate,
                'service_start_date' => $serviceStartDate,
                'service_end_date' => $serviceEndDate,
                'created_at' => now(),
            ], $quote->proposal_language ?? 'en'));

            $newProjectId = (int) DB::getPdo()->lastInsertId();
            if (! $newProjectId) {
                $newProjectId = (int) DB::table('projects_main')->orderByDesc('id')->value('id');
            }

            $this->insertProgress($newProjectId, 'New project created from Re-Award (existing quote).', $request);
            app(ProjectCollaboratorAssignmentService::class)
                ->assignInitialCollaborators($newProjectId, $request);

            $awardCount = (int) DB::table('projects_main')
                ->where('quote_id', $quoteId)
                ->whereRaw("LOWER(project_type) LIKE '%training%'")
                ->count();
            $awardCount = max(1, $awardCount);

            $statusRemark = $remarks !== '' ? $remarks : 'Re-Awarded';

            DB::table('quotes_training')
                ->where('id', $quoteId)
                ->update([
                    'status' => 'Awarded',
                    'status_remarks' => $statusRemark,
                    'award_date' => $awardDate,
                    'client_award_ref_no' => $clientRefNo,
                    'updated_at' => now(),
                ]);

            DB::commit();

            $this->auditLog->log($request, "Re-awarded training quote ID #{$quoteId} and created project ID #{$newProjectId}");

            return response()->json([
                'status' => 'success',
                'message' => 'Re-awarded successfully. Project created.',
                'award_count' => $awardCount,
                'status_remarks' => $statusRemark,
                'project_id' => $newProjectId,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function unAwardTraining(UnAwardQuoteRequest $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');

        DB::beginTransaction();
        try {
            $quote = DB::table('quotes_training')
                ->where('id', $quoteId)
                ->lockForUpdate()
                ->first(['id', 'status']);

            if (! $quote) {
                throw new \Exception('Training quotation not found.');
            }
            if (strtolower(trim((string) $quote->status)) !== 'awarded') {
                throw new \Exception('Only Awarded quotations can be un-awarded.');
            }

            $projects = DB::select("
                SELECT id, award_date, created_at
                FROM projects_main
                WHERE quote_id = ?
                  AND LOWER(project_type) LIKE '%training%'
                ORDER BY COALESCE(created_at, '1970-01-01') DESC, id DESC
            ", [$quoteId]);

            $linkedCount = count($projects);
            $targetProjectId = $linkedCount > 0 ? (int) $projects[0]->id : null;

            if ($targetProjectId) {
                $invoiceCount = DB::table('invoices')->where('project_id', $targetProjectId)->count();
                if ($invoiceCount > 0) {
                    throw new \Exception("Cannot un-award. Linked project #{$targetProjectId} has invoice records.");
                }

                $doCount = DB::table('do_details')->where('project_id', $targetProjectId)->count();
                if ($doCount > 0) {
                    throw new \Exception("Cannot un-award. Linked project #{$targetProjectId} has delivery order records.");
                }

                $vendorLoaCount = DB::table('project_vendors')->where('project_id', $targetProjectId)->count();
                if ($vendorLoaCount > 0) {
                    throw new \Exception("Cannot un-award. Linked project #{$targetProjectId} has vendor LOA records.");
                }

                $vendorPayCount = DB::table('vendor_payments')
                    ->where('project_id', $targetProjectId)
                    ->whereNull('deleted_at')
                    ->count();
                if ($vendorPayCount > 0) {
                    throw new \Exception("Cannot un-award. Linked project #{$targetProjectId} has vendor payment records.");
                }

                foreach (['project_closing_details', 'project_collaborators', 'project_progress', 'project_vendors', 'project_expenses'] as $table) {
                    DB::table($table)->where('project_id', $targetProjectId)->delete();
                }

                DB::table('projects_main')->where('id', $targetProjectId)->delete();
            }

            $remainingProjects = max(0, $linkedCount - ($targetProjectId ? 1 : 0));

            if ($remainingProjects > 0) {
                $latestRemaining = DB::table('projects_main')
                    ->where('quote_id', $quoteId)
                    ->whereRaw("LOWER(project_type) LIKE '%training%'")
                    ->orderByRaw("COALESCE(award_date, created_at, '1970-01-01') DESC")
                    ->orderByDesc('id')
                    ->first(['award_date']);

                DB::table('quotes_training')
                    ->where('id', $quoteId)
                    ->update([
                        'status' => 'Awarded',
                        'status_remarks' => $remainingProjects > 1 ? 'Re-Awarded' : 'Awarded',
                        'award_date' => $latestRemaining->award_date ?? null,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('quotes_training')
                    ->where('id', $quoteId)
                    ->update([
                        'status' => 'Open',
                        'status_remarks' => 'Un-awarded by user.',
                        'award_date' => null,
                        'client_award_ref_no' => null,
                        'updated_at' => now(),
                    ]);
            }

            DB::commit();

            $deletedCount = $targetProjectId ? 1 : 0;
            $this->auditLog->log($request, "Un-awarded training quotation ID #{$quoteId}; removed {$deletedCount} latest linked training project(s), remaining {$remainingProjects}");

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
        } catch (\Exception $e) {
            DB::rollBack();
            $code = $e instanceof QueryException ? 500 : 422;

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    private function insertProgress(int $projectId, string $activity, Request $request): void
    {
        if (! $projectId || ! $activity) {
            return;
        }

        $staffId = (int) $request->session()->get('staff_id', 0);

        DB::table('project_progress')->insert([
            'project_id' => $projectId,
            'progress_date' => now()->format('Y-m-d'),
            'progress_text' => $activity,
            'updated_by' => $staffId ?: null,
            'updated_on' => now(),
        ]);
    }

    private function trainingServiceDates(object $quote): array
    {
        $isTbc = (string) ($quote->to_be_confirmed ?? '0') === '1';
        $start = (string) ($quote->proposed_date ?? '');
        $end = (string) ($quote->proposed_end_date ?? '');

        if ($isTbc || $start === '' || $start === '0000-00-00') {
            return [null, null];
        }

        if ($end === '' || $end === '0000-00-00') {
            $end = $start;
        }

        return [$start, $end];
    }

    private function withProjectProposalLanguage(array $payload, mixed $language): array
    {
        if (Schema::hasColumn('projects_main', 'proposal_language')) {
            $payload['proposal_language'] = $language ?: 'en';
        }

        return $payload;
    }
}
