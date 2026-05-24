<?php

namespace App\Services\QuoteRecords;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EquipmentQuoteRecordListingService
{
    public function __construct(private AuditLogService $auditLog) {}

    public function listEquipment(Request $request): JsonResponse
    {
        $quotes = DB::select("
            SELECT
                qe.id,
                qe.quote_ref_no,
                qe.revision_no,
                qe.price_exception_request_id,
                qe.created_at,
                qe.updated_at,
                qe.status,
                qe.status_remarks,
                qe.award_date,
                qe.client_award_ref_no,
                qe.created_by_id,
                qe.created_by_name,
                qe.created_by_code,
                qe.client_id,
                qe.client_name,
                qe.client_ssm,
                qe.client_address,
                qe.client_city,
                qe.client_state,
                qe.client_zip,
                qe.pic_name,
                qe.pic_email,
                qe.pic_phone,
                qe.pic_position,
                qe.inquiry_remarks,
                qe.discount,
                qe.delivery_charge,
                qe.misc_charge,
                qe.sst_percent,
                qe.sst_amount,
                qe.sub_total,
                qe.grand_total,
                qe.attach_proposal,
                (
                    SELECT qis.source
                    FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = qe.id
                      AND qis.service_type = 'Equipment Supply'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ) AS inquiry_source,
                (
                    SELECT qis.remarks
                    FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = qe.id
                      AND qis.service_type = 'Equipment Supply'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ) AS inquiry_source_remarks,
                (
                    SELECT COUNT(*)
                    FROM quote_price_exception_requests qper
                    WHERE qper.request_type = 'quote'
                      AND qper.service_group = 'equipment'
                      AND qper.quote_id = qe.id
                      AND qper.status IN ('pending', 'approved')
                ) AS active_price_exception_request_count,
                (
                    SELECT COUNT(*)
                    FROM projects_main pm
                    WHERE pm.quote_id = qe.id
                      AND LOWER(pm.project_type) LIKE '%equipment%'
                ) AS award_count
            FROM quotes_equipment AS qe
            WHERE qe.service_group = 'Equipment'
            ORDER BY FIELD(qe.status, 'Open', 'Failed', 'Awarded'), qe.created_at DESC
        ");

        $quotes = array_map(fn ($q) => (array) $q, $quotes);

        if (empty($quotes)) {
            return response()->json([
                'status' => 'success',
                'data' => [],
                'followups' => [],
                'award_history' => [],
            ]);
        }

        $ids = array_values(array_unique(array_map('intval', array_column($quotes, 'id'))));
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $allItems = DB::select("
            SELECT
                qi.id,
                qi.quote_id,
                qi.item_id,
                ci.item_name,
                ci.category_id,
                ci.description,
                ci.unit,
                qi.quantity,
                qi.unit_price,
                qi.marked_up_price,
                qi.line_total,
                qi.created_by,
                qi.created_at,
                qi.updated_at
            FROM quotes_equipment_items qi
            LEFT JOIN catalog_items ci ON qi.item_id = ci.id
            WHERE qi.quote_id IN ({$ph})
            ORDER BY qi.quote_id ASC, qi.id ASC
        ", $ids);

        $itemsByQuote = [];
        foreach ($allItems as $it) {
            $itemsByQuote[(int) $it->quote_id][] = (array) $it;
        }

        foreach ($quotes as &$q) {
            $q['line_items'] = $itemsByQuote[(int) $q['id']] ?? [];
        }
        unset($q);

        $followups = DB::select("
            SELECT qf.id, qf.quote_id, qf.quote_type, qf.remarks, qf.follow_up_date, qf.created_by, qf.created_at
            FROM quote_followups qf
            WHERE qf.quote_type = 'equipment' AND qf.quote_id IN ({$ph})
            ORDER BY qf.quote_id ASC, qf.follow_up_date DESC, qf.id DESC
        ", $ids);

        $awardHistory = DB::select("
            SELECT pm.id, pm.quote_id, pm.award_date, pm.status, pm.quote_value, pm.created_at
            FROM projects_main pm
            WHERE pm.quote_id IN ({$ph})
              AND LOWER(pm.project_type) LIKE '%equipment%'
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

    public function destroyEquipment(Request $request, int $id = 0): JsonResponse
    {
        $quoteId = $id > 0 ? $id : (int) $request->input('id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => "Missing or invalid 'id'."], 400);
        }

        $row = DB::table('quotes_equipment')->where('id', $quoteId)->first();
        if (! $row) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found.'], 404);
        }
        if (strtolower($row->status) === 'awarded') {
            return response()->json(['status' => 'error', 'message' => 'Cannot delete a quotation that has already been awarded.'], 403);
        }

        if ($denial = QuoteRecordDeletePermission::denial($request, $row)) {
            return $denial;
        }

        $quoteRefNo = $row->quote_ref_no;

        DB::beginTransaction();
        try {
            DB::table('quotes_equipment_items')->where('quote_id', $quoteId)->delete();
            DB::table('quote_inquiry_sources')->where('quote_ref_no', $quoteRefNo)->delete();
            $deleted = DB::table('quotes_equipment')->where('id', $quoteId)->delete();

            if ($deleted === 0) {
                DB::rollBack();

                return response()->json(['status' => 'error', 'message' => 'Quotation record not found or already deleted.'], 404);
            }

            $this->auditLog->log($request, "Deleted Equipment quote {$quoteRefNo}");
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Database error: '.$e->getMessage()], 500);
        }

        return response()->json(['status' => 'success', 'message' => "Equipment quotation {$quoteRefNo} deleted successfully."]);
    }

    public function relatedDocsEquipment(Request $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid quote_id'], 400);
        }

        return response()->json([
            'status' => 'success',
            'data' => QuoteRelatedDocsPayload::forQuote($quoteId, 'equipment', '%equipment%'),
        ]);
    }
}
