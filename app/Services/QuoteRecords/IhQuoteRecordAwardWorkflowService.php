<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use App\Http\Requests\QuoteRecord\AwardQuoteRequest;
use App\Http\Requests\QuoteRecord\FailQuoteRequest;
use App\Http\Requests\QuoteRecord\SyncClientRequest;
use App\Http\Requests\QuoteRecord\UnAwardQuoteRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IhQuoteRecordAwardWorkflowService
{

    public function __construct(private AuditLogService $auditLog) {}

    public function awardIh(AwardQuoteRequest $request): JsonResponse
    {
        $quoteId     = (int) $request->input('quote_id');
        $remarks     = trim((string) $request->input('remarks', ''));
        $awardDate   = $request->input('award_date') ?: now()->format('Y-m-d');
        $description = (string) $request->input('description', '');
        $clientRefNo = $request->input('client_award_ref_no');

        DB::beginTransaction();
        try {
            DB::table('quotes_ih')->where('id', $quoteId)->update([
                'status'              => 'Awarded',
                'status_remarks'      => $remarks,
                'award_date'          => $awardDate,
                'client_award_ref_no' => $clientRefNo,
                'updated_at'          => now(),
            ]);

            $quote = DB::table('quotes_ih')->where('id', $quoteId)->first();
            if (!$quote) {
                throw new \Exception('IH quotation not found.');
            }

            $duplicate = DB::table('projects_main')
                ->where('quote_id', $quoteId)
                ->where(function ($q) {
                    $q->whereRaw("LOWER(project_type) LIKE '%industrial%'")
                      ->orWhereRaw("LOWER(project_type) LIKE '%ih%'");
                })
                ->count();
            if ($duplicate > 0) {
                throw new \Exception('This Industrial/Hygiene quotation is already linked to a project.');
            }

            $newProjectId = DB::table('projects_main')->insertGetId([
                'client_id'     => $quote->client_id,
                'quote_id'      => $quoteId,
                'project_name'  => $quote->service_title,
                'project_type'  => 'Industrial Hygiene',
                'quote_type'    => 'ih',
                'po_loa_number' => $clientRefNo,
                'description'   => $description,
                'status'        => 'Active',
                'quote_value'   => $quote->grand_total,
                'proposal_language' => $quote->proposal_language ?? 'en',
                'award_date'    => $awardDate,
                'created_at'    => now(),
            ]);

            $this->insertProjectProgress($newProjectId, 'IH quotation marked as Awarded. Project started.', $request);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        $this->auditLog->log($request, "Marked IH quote ID #{$quoteId} as Awarded and created project ID #{$newProjectId}");

        return response()->json([
            'status'     => 'success',
            'message'    => 'IH quotation awarded and project created successfully.',
            'project_id' => $newProjectId,
        ]);
    }

    public function failIh(FailQuoteRequest $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');
        $remarks = trim((string) $request->input('remarks', ''));

        $row = DB::table('quotes_ih')->where('id', $quoteId)->first();
        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'IH quotation not found.'], 404);
        }
        if (strtolower(trim($row->status)) === 'failed') {
            return response()->json(['status' => 'error', 'message' => 'Quotation is already marked as Failed.'], 422);
        }

        DB::table('quotes_ih')->where('id', $quoteId)->update([
            'status'         => 'Failed',
            'status_remarks' => $remarks,
            'updated_at'     => now(),
        ]);

        $this->auditLog->log($request, "Marked IH quotation ID #{$quoteId} as Failed");

        return response()->json(['status' => 'success', 'message' => 'Quotation marked as Failed.']);
    }

    public function reAwardIh(AwardQuoteRequest $request): JsonResponse
    {
        $quoteId     = (int) $request->input('quote_id');
        $remarks     = trim((string) $request->input('remarks', ''));
        $awardDate   = $request->input('award_date') ?: now()->format('Y-m-d');
        $description = trim((string) $request->input('description', 'Re-awarded project from existing awarded quotation.'));
        $clientRefNo = $request->input('client_award_ref_no');

        DB::beginTransaction();
        try {
            $quote = DB::table('quotes_ih')->where('id', $quoteId)->first();
            if (!$quote) {
                throw new \Exception('IH quotation not found.');
            }
            if (strtolower(trim((string) $quote->status)) !== 'awarded') {
                throw new \Exception('Only Awarded quotations can be re-awarded.');
            }

            $newProjectId = DB::table('projects_main')->insertGetId([
                'client_id'    => $quote->client_id,
                'quote_id'     => $quoteId,
                'project_name' => $quote->service_title,
                'project_type' => 'Industrial Hygiene',
                'quote_type'   => 'ih',
                'description'  => $description,
                'status'       => 'Active',
                'quote_value'  => $quote->grand_total,
                'proposal_language' => $quote->proposal_language ?? 'en',
                'award_date'   => $awardDate,
                'created_at'   => now(),
            ]);

            $this->insertProjectProgress($newProjectId, 'New project created from Re-Award (existing quote).', $request);

            $awardCount = DB::table('projects_main')
                ->where('quote_id', $quoteId)
                ->where(function ($q) {
                    $q->whereRaw("LOWER(project_type) LIKE '%industrial%'")
                      ->orWhereRaw("LOWER(project_type) LIKE '%ih%'");
                })
                ->count();
            $awardCount = max(1, $awardCount);

            $statusRemark = $remarks !== '' ? $remarks : 'Re-Awarded';

            DB::table('quotes_ih')->where('id', $quoteId)->update([
                'status'              => 'Awarded',
                'status_remarks'      => $statusRemark,
                'award_date'          => $awardDate,
                'client_award_ref_no' => $clientRefNo,
                'updated_at'          => now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        $this->auditLog->log($request, "Re-awarded IH quote ID #{$quoteId} and created project ID #{$newProjectId}");

        return response()->json([
            'status'         => 'success',
            'message'        => 'Re-awarded successfully. Project created.',
            'award_count'    => $awardCount,
            'status_remarks' => $statusRemark,
        ]);
    }

    public function unAwardIh(UnAwardQuoteRequest $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');

        DB::beginTransaction();
        try {
            $quote = DB::table('quotes_ih')
                ->where('id', $quoteId)
                ->lockForUpdate()
                ->first();

            if (!$quote) {
                throw new \Exception('IH quotation not found.');
            }
            if (strtolower(trim((string) $quote->status)) !== 'awarded') {
                throw new \Exception('Only Awarded quotations can be un-awarded.');
            }

            $projects = DB::select("
                SELECT id, award_date, created_at
                FROM projects_main
                WHERE quote_id = ?
                  AND (LOWER(project_type) LIKE '%industrial%' OR LOWER(project_type) LIKE '%ih%')
                ORDER BY COALESCE(created_at, '1970-01-01') DESC, id DESC
            ", [$quoteId]);

            $linkedCount     = count($projects);
            $targetProjectId = $linkedCount > 0 ? (int) $projects[0]->id : null;

            if ($targetProjectId) {
                $this->guardLinkedProject($targetProjectId);
                $this->deleteProjectWithChildren($targetProjectId);
            }

            $remainingProjects = max(0, $linkedCount - ($targetProjectId ? 1 : 0));

            if ($remainingProjects > 0) {
                $latest = DB::table('projects_main')
                    ->where('quote_id', $quoteId)
                    ->where(function ($q) {
                        $q->whereRaw("LOWER(project_type) LIKE '%industrial%'")
                          ->orWhereRaw("LOWER(project_type) LIKE '%ih%'");
                    })
                    ->orderByRaw("COALESCE(award_date, created_at, '1970-01-01') DESC")
                    ->orderByDesc('id')
                    ->first();

                DB::table('quotes_ih')->where('id', $quoteId)->update([
                    'status'         => 'Awarded',
                    'status_remarks' => $remainingProjects > 1 ? 'Re-Awarded' : 'Awarded',
                    'award_date'     => $latest->award_date ?? null,
                    'updated_at'     => now(),
                ]);
            } else {
                DB::table('quotes_ih')->where('id', $quoteId)->update([
                    'status'              => 'Open',
                    'status_remarks'      => 'Un-awarded by user.',
                    'award_date'          => null,
                    'client_award_ref_no' => null,
                    'updated_at'          => now(),
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $code = $e instanceof \Illuminate\Database\QueryException ? 500 : 400;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }

        $deletedCount = $targetProjectId ? 1 : 0;
        $this->auditLog->log($request, "Un-awarded IH quotation ID #{$quoteId}; removed {$deletedCount} latest linked project(s), remaining {$remainingProjects}");

        if (!$targetProjectId) {
            $message = 'No linked project found. Quotation reset to Open.';
        } elseif ($remainingProjects > 0) {
            $message = "Latest award removed. Quotation remains Awarded with {$remainingProjects} linked project(s).";
        } else {
            $message = 'Quotation un-awarded successfully.';
        }

        return response()->json([
            'status'             => 'success',
            'message'            => $message,
            'deleted_projects'   => $deletedCount,
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
                'project_id'    => $projectId,
                'progress_date' => now()->format('Y-m-d'),
                'progress_text' => $text,
                'updated_by'    => (int) $request->session()->get('staff_id', 0) ?: null,
                'updated_on'    => now(),
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
}
