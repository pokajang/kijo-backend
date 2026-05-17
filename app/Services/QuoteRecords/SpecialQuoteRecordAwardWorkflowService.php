<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use App\Http\Requests\QuoteRecord\AwardQuoteRequest;
use App\Http\Requests\QuoteRecord\FailQuoteRequest;
use App\Http\Requests\QuoteRecord\SpecialLineItemsByServiceRequest;
use App\Http\Requests\QuoteRecord\SyncClientRequest;
use App\Http\Requests\QuoteRecord\UnAwardQuoteRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpecialQuoteRecordAwardWorkflowService
{
    public function __construct(private AuditLogService $auditLog) {}

    public function awardSpecial(AwardQuoteRequest $request): JsonResponse
    {
        $quoteId     = (int) $request->input('quote_id');
        $remarks     = $request->input('remarks', '');
        $awardDate   = $request->input('award_date', now()->format('Y-m-d'));
        $description = $request->input('description', '');
        $clientRefNo = $request->input('client_award_ref_no');

        DB::beginTransaction();
        try {
            DB::table('quotes_special')
                ->where('id', $quoteId)
                ->update([
                    'status'              => 'Awarded',
                    'status_remarks'      => $remarks,
                    'award_date'          => $awardDate,
                    'client_award_ref_no' => $clientRefNo,
                    'updated_at'          => now(),
                ]);

            $quote = DB::table('quotes_special')->where('id', $quoteId)->first(['client_id', 'service_title', 'grand_total', 'proposal_language']);
            if (!$quote) {
                throw new \Exception('Special quotation not found.');
            }

            $existing = DB::table('projects_main')
                ->where('quote_id', $quoteId)
                ->whereRaw("LOWER(project_type) LIKE '%special%'")
                ->count();
            if ($existing > 0) {
                throw new \Exception('This Special quotation is already linked to a Special project.');
            }

            DB::table('projects_main')->insert([
                'client_id'    => $quote->client_id,
                'quote_id'     => $quoteId,
                'project_name' => $quote->service_title,
                'project_type' => 'Special Service',
                'quote_type'   => 'special',
                'po_loa_number'=> $clientRefNo,
                'description'  => $description,
                'status'       => 'Active',
                'quote_value'  => $quote->grand_total,
                'proposal_language' => $quote->proposal_language ?? 'en',
                'award_date'   => $awardDate,
                'created_at'   => now(),
            ]);

            $newProjectId = (int) DB::getPdo()->lastInsertId();
            if (!$newProjectId) {
                $newProjectId = (int) DB::table('projects_main')->orderByDesc('id')->value('id');
            }

            $this->insertProgress($newProjectId, 'Special quotation marked as Awarded. Project started.', $request);

            DB::commit();

            $this->auditLog->log($request, "Marked Special quotation ID #{$quoteId} as Awarded and created project ID #{$newProjectId}");

            return response()->json([
                'status'     => 'success',
                'message'    => 'Special quotation awarded and project created successfully.',
                'project_id' => $newProjectId,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function failSpecial(FailQuoteRequest $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');
        $remarks = $request->input('remarks');

        $row = DB::table('quotes_special')->where('id', $quoteId)->first(['status']);
        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Special quotation not found.'], 404);
        }

        if (strtolower(trim($row->status)) === 'failed') {
            return response()->json(['status' => 'error', 'message' => 'Quotation is already marked as Failed.']);
        }

        DB::table('quotes_special')
            ->where('id', $quoteId)
            ->update([
                'status'         => 'Failed',
                'status_remarks' => $remarks,
                'updated_at'     => now(),
            ]);

        $this->auditLog->log($request, "Marked Special quotation ID #{$quoteId} as Failed");

        return response()->json(['status' => 'success', 'message' => 'Quotation marked as Failed.']);
    }

    public function reAwardSpecial(AwardQuoteRequest $request): JsonResponse
    {
        $quoteId     = (int) $request->input('quote_id');
        $remarks     = trim($request->input('remarks', ''));
        $awardDate   = $request->input('award_date', now()->format('Y-m-d'));
        $description = trim($request->input('description', 'Re-awarded project from existing awarded quotation.'));
        $clientRefNo = $request->input('client_award_ref_no');

        DB::beginTransaction();
        try {
            $quote = DB::table('quotes_special')->where('id', $quoteId)->first(['client_id', 'service_title', 'grand_total', 'status', 'proposal_language']);
            if (!$quote) {
                throw new \Exception('Special quotation not found.');
            }
            if (strtolower(trim((string) $quote->status)) !== 'awarded') {
                throw new \Exception('Only Awarded quotations can be re-awarded.');
            }

            DB::table('projects_main')->insert([
                'client_id'    => $quote->client_id,
                'quote_id'     => $quoteId,
                'project_name' => $quote->service_title,
                'project_type' => 'Special Service',
                'quote_type'   => 'special',
                'description'  => $description,
                'status'       => 'Active',
                'quote_value'  => $quote->grand_total,
                'proposal_language' => $quote->proposal_language ?? 'en',
                'award_date'   => $awardDate,
                'created_at'   => now(),
            ]);

            $newProjectId = (int) DB::getPdo()->lastInsertId();
            if (!$newProjectId) {
                $newProjectId = (int) DB::table('projects_main')->orderByDesc('id')->value('id');
            }

            $this->insertProgress($newProjectId, 'New project created from Re-Award (existing quote).', $request);

            $awardCount = (int) DB::table('projects_main')
                ->where('quote_id', $quoteId)
                ->whereRaw("LOWER(project_type) LIKE '%special%'")
                ->count();
            $awardCount = max(1, $awardCount);

            $statusRemark = $remarks !== '' ? $remarks : 'Re-Awarded';

            DB::table('quotes_special')
                ->where('id', $quoteId)
                ->update([
                    'status'              => 'Awarded',
                    'status_remarks'      => $statusRemark,
                    'award_date'          => $awardDate,
                    'client_award_ref_no' => $clientRefNo,
                    'updated_at'          => now(),
                ]);

            DB::commit();

            $this->auditLog->log($request, "Re-awarded special quote ID #{$quoteId} and created project ID #{$newProjectId}");

            return response()->json([
                'status'         => 'success',
                'message'        => 'Re-awarded successfully. Project created.',
                'award_count'    => $awardCount,
                'status_remarks' => $statusRemark,
                'project_id'     => $newProjectId,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function unAwardSpecial(UnAwardQuoteRequest $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');

        DB::beginTransaction();
        try {
            $quote = DB::table('quotes_special')
                ->where('id', $quoteId)
                ->lockForUpdate()
                ->first(['id', 'status']);

            if (!$quote) {
                throw new \Exception('Special quotation not found.');
            }
            if (strtolower(trim((string) $quote->status)) !== 'awarded') {
                throw new \Exception('Only Awarded quotations can be un-awarded.');
            }

            $projects = DB::select("
                SELECT id, award_date, created_at
                FROM projects_main
                WHERE quote_id = ?
                  AND LOWER(project_type) LIKE '%special%'
                ORDER BY COALESCE(created_at, '1970-01-01') DESC, id DESC
            ", [$quoteId]);

            $linkedCount     = count($projects);
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
                    ->whereRaw("LOWER(project_type) LIKE '%special%'")
                    ->orderByRaw("COALESCE(award_date, created_at, '1970-01-01') DESC")
                    ->orderByDesc('id')
                    ->first(['award_date']);

                DB::table('quotes_special')
                    ->where('id', $quoteId)
                    ->update([
                        'status'         => 'Awarded',
                        'status_remarks' => $remainingProjects > 1 ? 'Re-Awarded' : 'Awarded',
                        'award_date'     => $latestRemaining->award_date ?? null,
                        'updated_at'     => now(),
                    ]);
            } else {
                DB::table('quotes_special')
                    ->where('id', $quoteId)
                    ->update([
                        'status'              => 'Open',
                        'status_remarks'      => 'Un-awarded by user.',
                        'award_date'          => null,
                        'client_award_ref_no' => null,
                        'updated_at'          => now(),
                    ]);
            }

            DB::commit();

            $deletedCount = $targetProjectId ? 1 : 0;
            $this->auditLog->log($request, "Un-awarded Special quotation ID #{$quoteId}; removed {$deletedCount} latest linked project(s), remaining {$remainingProjects}");

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
        } catch (\Exception $e) {
            DB::rollBack();
            $code = $e instanceof \Illuminate\Database\QueryException ? 500 : 422;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    private function insertProgress(int $projectId, string $activity, Request $request): void
    {
        if (!$projectId || !$activity) {
            return;
        }

        $staffId = (int) $request->session()->get('staff_id', 0);

        DB::table('project_progress')->insert([
            'project_id'    => $projectId,
            'progress_date' => now()->format('Y-m-d'),
            'progress_text' => $activity,
            'updated_by'    => $staffId ?: null,
            'updated_on'    => now(),
        ]);
    }
}
