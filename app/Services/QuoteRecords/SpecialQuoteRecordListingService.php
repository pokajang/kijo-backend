<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\SpecialLineItemsByServiceRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpecialQuoteRecordListingService
{
    public function __construct(private AuditLogService $auditLog) {}

    public function listSpecial(): JsonResponse
    {
        $quotes = DB::select("
            SELECT
                qs.id,
                qs.quote_ref_no,
                qs.revision_no,
                qs.price_exception_request_id,
                qs.created_at,
                qs.updated_at,
                qs.status,
                qs.status_remarks,
                qs.award_date,
                qs.client_award_ref_no,
                qs.created_by_id,
                qs.created_by_name,
                qs.created_by_code,
                qs.client_id,
                qs.client_name,
                qs.client_ssm,
                qs.client_address,
                qs.client_city,
                qs.client_state,
                qs.client_zip,
                qs.pic_name,
                qs.pic_email,
                qs.pic_phone,
                qs.pic_position,
                qs.sp_id,
                qs.service_title,
                qs.service_code,
                qs.proposal_language,
                qs.general_remarks,
                qs.sst_percent,
                qs.sst_amount,
                qs.sub_total,
                qs.grand_total,
                qs.attach_proposal,
                (
                    SELECT qis.source
                    FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = qs.id
                      AND qis.service_type = 'Special Service'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ) AS inquiry_source,
                (
                    SELECT qis.remarks
                    FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = qs.id
                      AND qis.service_type = 'Special Service'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ) AS inquiry_source_remarks,
                (
                    SELECT COUNT(*)
                    FROM quote_price_exception_requests qper
                    WHERE qper.request_type = 'quote'
                      AND qper.service_group = 'special'
                      AND qper.quote_id = qs.id
                      AND qper.status IN ('pending', 'approved')
                ) AS active_price_exception_request_count,
                (
                    SELECT COUNT(*)
                    FROM projects_main pm
                    WHERE pm.quote_id = qs.id
                      AND LOWER(pm.project_type) LIKE '%special%'
                ) AS award_count
            FROM quotes_special AS qs
            WHERE qs.service_group = 'special'
            ORDER BY
                FIELD(qs.status, 'Open', 'Failed', 'Awarded'),
                qs.created_at DESC
        ");

        $followups = [];
        $awardHistory = [];

        if (! empty($quotes)) {
            $ids = array_values(array_unique(array_map(fn ($q) => (int) $q->id, $quotes)));
            $ph = implode(',', array_fill(0, count($ids), '?'));

            $allItems = DB::select("
                SELECT id, service_id, line_item_title, description, unit, unit_price,
                       quantity, line_total, created_by, created_at, updated_at, quote_id
                FROM quotes_special_items
                WHERE quote_id IN ({$ph})
                ORDER BY quote_id ASC, id ASC
            ", $ids);

            $itemsByQuote = [];
            foreach ($allItems as $item) {
                $qid = (int) $item->quote_id;
                $itemsByQuote[$qid][] = $item;
            }
            foreach ($quotes as &$q) {
                $q->line_items = $itemsByQuote[(int) $q->id] ?? [];
            }
            unset($q);

            $followups = DB::select("
                SELECT id, quote_id, quote_type, remarks, follow_up_date, created_by, created_at
                FROM quote_followups
                WHERE quote_type = 'special'
                  AND quote_id IN ({$ph})
                ORDER BY quote_id ASC, follow_up_date DESC, id DESC
            ", $ids);

            $awardHistory = DB::select("
                SELECT id, quote_id, award_date, status, quote_value, created_at
                FROM projects_main
                WHERE quote_id IN ({$ph})
                  AND LOWER(project_type) LIKE '%special%'
                ORDER BY quote_id ASC, award_date ASC, id ASC
            ", $ids);
        }

        ProjectOutcomeSummary::attach($quotes, $awardHistory);
        QuoteRecordProposalPayload::attach($quotes, 'special');

        return response()->json([
            'status' => 'success',
            'data' => $quotes,
            'followups' => $followups,
            'award_history' => $awardHistory,
        ]);
    }

    public function destroySpecial(Request $request, int $id = 0): JsonResponse
    {
        $quoteId = $id > 0 ? $id : (int) $request->input('id');
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => "Missing or invalid 'id'."], 400);
        }

        $quote = DB::table('quotes_special')
            ->where('id', $quoteId)
            ->first(['status', 'quote_ref_no', 'created_by_id', 'created_by_code']);
        if (! $quote) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found.'], 404);
        }

        if (strtolower($quote->status) === 'awarded') {
            return response()->json(['status' => 'error', 'message' => 'Cannot delete a quotation that has already been awarded.'], 403);
        }

        if ($denial = QuoteRecordDeletePermission::denial($request, $quote)) {
            return $denial;
        }

        DB::beginTransaction();
        try {
            DB::table('quote_inquiry_sources')->where('quote_ref_no', $quote->quote_ref_no)->delete();
            DB::table('quotes_special_items')->where('quote_id', $quoteId)->delete();
            $deleted = DB::table('quotes_special')->where('id', $quoteId)->delete();

            if ($deleted === 0) {
                DB::rollBack();

                return response()->json(['status' => 'error', 'message' => 'Quotation record not found or already deleted.'], 404);
            }

            $this->auditLog->log($request, "Deleted Special quote {$quote->quote_ref_no}");

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Special quotation {$quote->quote_ref_no} deleted successfully.",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function relatedDocsSpecial(Request $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid quote_id.'], 400);
        }

        return $this->fetchRelatedDocs($quoteId);
    }

    public function specialLineItemsByService(SpecialLineItemsByServiceRequest $request): JsonResponse
    {
        $serviceId = (int) $request->input('service_id');

        $items = DB::select('
            SELECT
                qi.id,
                qi.line_item_title AS title,
                qi.description,
                qi.unit,
                qi.unit_price,
                qi.created_at
            FROM quotes_special_items AS qi
            INNER JOIN (
                SELECT line_item_title, MAX(created_at) AS max_created
                FROM quotes_special_items
                WHERE service_id = ?
                GROUP BY line_item_title
            ) AS latest
                ON qi.line_item_title = latest.line_item_title
               AND qi.created_at      = latest.max_created
            WHERE qi.service_id = ?
            ORDER BY qi.id ASC
        ', [$serviceId, $serviceId]);

        return response()->json(['status' => 'success', 'data' => $items]);
    }

    private function fetchRelatedDocs(int $quoteId): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => QuoteRelatedDocsPayload::forQuote($quoteId, 'special', '%special%'),
        ]);
    }
}
