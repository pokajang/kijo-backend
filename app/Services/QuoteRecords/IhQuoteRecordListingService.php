<?php

namespace App\Services\QuoteRecords;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IhQuoteRecordListingService
{
    public function __construct(private AuditLogService $auditLog) {}

    public function listIh(Request $request): JsonResponse
    {
        $quotes = DB::select("
            SELECT
                id, quote_running_no, quote_ref_no, revision_no, price_exception_request_id, created_at, updated_at, status,
                award_date, client_award_ref_no, status_remarks, created_by_id, created_by_name,
                created_by_code, attach_proposal, service_group,
                client_id, client_name, client_ssm, client_address, client_city, client_state, client_zip,
                pic_name, pic_email, pic_phone, pic_position,
                service_id, service_title, service_code, site_address,
                travel_charge, sample_counts, sample_unit, num_work_units,
                inquiry_remarks,
                unit_price, discount, sst_percent, sst_amount, sub_total, grand_total,
                (
                    SELECT qis.source FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = quotes_ih.id
                      AND qis.service_type = 'Industrial Hygiene'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ) AS inquiry_source,
                (
                    SELECT qis.remarks FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = quotes_ih.id
                      AND qis.service_type = 'Industrial Hygiene'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ) AS inquiry_source_remarks,
                (
                    SELECT COUNT(*) FROM quote_price_exception_requests qper
                    WHERE qper.request_type = 'quote'
                      AND qper.service_group = 'ih'
                      AND qper.quote_id = quotes_ih.id
                      AND qper.status IN ('pending', 'approved')
                ) AS active_price_exception_request_count,
                (
                    SELECT COUNT(*) FROM projects_main pm
                    WHERE pm.quote_id = quotes_ih.id
                      AND (LOWER(pm.project_type) LIKE '%industrial%' OR LOWER(pm.project_type) LIKE '%ih%')
                ) AS award_count
            FROM quotes_ih
            ORDER BY FIELD(status, 'Open', 'Failed', 'Awarded'), created_at DESC
        ");

        $quotes = array_map(fn ($q) => (array) $q, $quotes);

        if (empty($quotes)) {
            return response()->json([
                'status' => 'success', 'data' => [], 'followups' => [], 'award_history' => [],
            ]);
        }

        $ids = array_values(array_unique(array_map('intval', array_column($quotes, 'id'))));
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $followups = DB::select("
            SELECT qf.id, qf.quote_id, qf.quote_type, qf.remarks, qf.follow_up_date, qf.created_by, qf.created_at
            FROM quote_followups qf
            WHERE qf.quote_type = 'ih' AND qf.quote_id IN ({$ph})
            ORDER BY qf.quote_id ASC, qf.follow_up_date DESC, qf.id DESC
        ", $ids);

        $awardHistory = DB::select("
            SELECT pm.id, pm.quote_id, pm.award_date, pm.status, pm.quote_value, pm.created_at
            FROM projects_main pm
            WHERE pm.quote_id IN ({$ph})
              AND (LOWER(pm.project_type) LIKE '%industrial%' OR LOWER(pm.project_type) LIKE '%ih%')
            ORDER BY pm.quote_id ASC, pm.award_date ASC, pm.id ASC
        ", $ids);

        ProjectOutcomeSummary::attach($quotes, $awardHistory);

        return response()->json([
            'status' => 'success',
            'data' => $quotes,
            'followups' => array_map(fn ($r) => (array) $r, $followups),
            'award_history' => array_map(fn ($r) => (array) $r, $awardHistory),
        ]);
    }

    public function destroyIh(Request $request, int $id = 0): JsonResponse
    {
        $quoteId = $id > 0 ? $id : (int) $request->input('id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => "Missing or invalid 'id'."], 400);
        }

        $row = DB::table('quotes_ih')->where('id', $quoteId)->first();
        if (! $row) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found.'], 404);
        }
        if (strtolower($row->status) === 'awarded') {
            return response()->json(['status' => 'error', 'message' => 'Cannot delete a quotation that has already been awarded.'], 403);
        }

        $quoteRefNo = $row->quote_ref_no;

        DB::beginTransaction();
        try {
            DB::table('quote_inquiry_sources')->where('quote_ref_no', $quoteRefNo)->delete();
            $deleted = DB::table('quotes_ih')->where('id', $quoteId)->delete();

            if ($deleted === 0) {
                DB::rollBack();

                return response()->json(['status' => 'error', 'message' => 'Quotation record not found or already deleted.'], 404);
            }

            $this->auditLog->log($request, "Deleted IH quotation {$quoteRefNo}");
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['status' => 'error', 'message' => 'Database error: '.$e->getMessage()], 500);
        }

        return response()->json(['status' => 'success', 'message' => "IH quotation {$quoteRefNo} deleted successfully."]);
    }

    public function relatedDocsIh(Request $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid quote_id'], 400);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->fetchRelatedDocs($quoteId, 'ih', '%ih%', '%industrial%'),
        ]);
    }

    private function fetchRelatedDocs(int $quoteId, string $quoteType, string ...$projectTypePatterns): array
    {
        $projectQuery = DB::table('projects_main')
            ->where('quote_id', $quoteId)
            ->where(function ($q) use ($quoteType, $projectTypePatterns) {
                $q->where('quote_type', $quoteType)
                    ->orWhere(function ($inner) use ($projectTypePatterns) {
                        $inner->where(function ($nn) {
                            $nn->whereNull('quote_type')->orWhereRaw("TRIM(quote_type) = ''");
                        });
                        foreach ($projectTypePatterns as $pattern) {
                            $inner->orWhereRaw('LOWER(project_type) LIKE ?', [$pattern]);
                        }
                    });
            })
            ->select(['id', 'project_type']);

        $projects = $projectQuery->get()->map(fn ($r) => (array) $r)->toArray();
        $projectIds = array_values(array_filter(array_map(fn ($r) => (int) $r['id'], $projects)));

        $deliveryOrders = [];
        $invoices = [];
        $jd14Forms = [];

        if (! empty($projectIds)) {
            $deliveryOrders = DB::table('do_details')
                ->whereIn('project_id', $projectIds)
                ->orderBy('id')
                ->select(['id', 'do_number'])
                ->get()->map(fn ($r) => (array) $r)->toArray();

            $invoices = DB::table('invoices')
                ->whereIn('project_id', $projectIds)
                ->orderBy('id')
                ->select(['id', 'invoice_ref_no', 'receipt_no'])
                ->get()->map(fn ($r) => (array) $r)->toArray();

            $jd14Forms = DB::table('invoices_jd14form')
                ->whereIn('project_id', $projectIds)
                ->orderBy('id')
                ->select(['id', 'approval_no'])
                ->get()->map(fn ($r) => (array) $r)->toArray();
        }

        $receipts = array_values(array_filter($invoices, fn ($inv) => ! empty($inv['receipt_no'])));

        return [
            'projects' => $projects,
            'delivery_orders' => $deliveryOrders,
            'invoices' => $invoices,
            'receipts' => $receipts,
            'jd14' => $jd14Forms,
        ];
    }
}
