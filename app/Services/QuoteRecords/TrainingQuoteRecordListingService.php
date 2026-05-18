<?php

namespace App\Services\QuoteRecords;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainingQuoteRecordListingService
{
    public function __construct(private AuditLogService $auditLog) {}

    public function listTraining(): JsonResponse
    {
        $quotes = DB::select("
            SELECT
                id,
                quote_running_no,
                quote_ref_no,
                revision_no,
                price_exception_request_id,
                created_at,
                updated_at,
                status,
                award_date,
                client_award_ref_no,
                status_remarks,
                created_by_id,
                created_by_name,
                created_by_code,
                attach_proposal,
                client_id,
                training_id,
                client_name,
                client_ssm,
                client_address,
                client_city,
                client_state,
                client_zip,
                pic_name,
                pic_email,
                pic_phone,
                pic_position,
                training_title,
                training_type,
                payment_method,
                proposed_date,
                proposed_end_date,
                to_be_confirmed,
                venue,
                remarks,
                target_groups,
                pax,
                session_count,
                duration_per_session,
                duration_unit,
                unit_price,
                travel_charge,
                meals_provided,
                meal_price,
                discount_type,
                discount_value,
                sst_rate,
                hrd_charge,
                training_total,
                meal_total,
                mobilization_cost,
                discount_amount,
                subtotal,
                sst_amount,
                hrd_amount,
                grand_total,
                (
                    SELECT qis.source
                    FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = quotes_training.id
                      AND qis.service_type = 'Training'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ) AS inquiry_source,
                (
                    SELECT qis.remarks
                    FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = quotes_training.id
                      AND qis.service_type = 'Training'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ) AS inquiry_source_remarks,
                (
                    SELECT COUNT(*)
                    FROM quote_price_exception_requests qper
                    WHERE qper.request_type = 'quote'
                      AND qper.service_group = 'training'
                      AND qper.quote_id = quotes_training.id
                      AND qper.status IN ('pending', 'approved')
                ) AS active_price_exception_request_count,
                (
                    SELECT COUNT(*)
                    FROM projects_main pm
                    WHERE pm.quote_id = quotes_training.id
                      AND LOWER(pm.project_type) LIKE '%training%'
                ) AS award_count
            FROM quotes_training
            ORDER BY
                FIELD(status, 'Open', 'Failed', 'Awarded'),
                created_at DESC
        ");

        $followups = [];
        $awardHistory = [];

        if (! empty($quotes)) {
            $ids = array_values(array_unique(array_map(fn ($q) => (int) $q->id, $quotes)));
            $ph = implode(',', array_fill(0, count($ids), '?'));

            $followups = DB::select("
                SELECT id, quote_id, quote_type, remarks, follow_up_date, created_by, created_at
                FROM quote_followups
                WHERE quote_type = 'training'
                  AND quote_id IN ({$ph})
                ORDER BY quote_id ASC, follow_up_date DESC, id DESC
            ", $ids);

            $awardHistory = DB::select("
                SELECT id, quote_id, award_date, status, quote_value, created_at
                FROM projects_main
                WHERE quote_id IN ({$ph})
                  AND LOWER(project_type) LIKE '%training%'
                ORDER BY quote_id ASC, award_date ASC, id ASC
            ", $ids);
        }

        ProjectOutcomeSummary::attach($quotes, $awardHistory);

        return response()->json([
            'status' => 'success',
            'data' => $quotes,
            'followups' => $followups,
            'award_history' => $awardHistory,
        ]);
    }

    public function destroyTraining(Request $request, int $id = 0): JsonResponse
    {
        $quoteId = $id > 0 ? $id : (int) $request->input('id');
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => "Missing or invalid 'id'."], 400);
        }

        $quote = DB::table('quotes_training')->where('id', $quoteId)->first(['quote_ref_no', 'status']);
        if (! $quote) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found.'], 404);
        }

        if (strtolower($quote->status) === 'awarded') {
            return response()->json(['status' => 'error', 'message' => 'Cannot delete a quotation that has already been awarded.'], 403);
        }

        DB::table('quote_inquiry_sources')->where('quote_ref_no', $quote->quote_ref_no)->delete();
        $deleted = DB::table('quotes_training')->where('id', $quoteId)->delete();

        if ($deleted === 0) {
            return response()->json(['status' => 'error', 'message' => 'Quotation record not found or already deleted.'], 404);
        }

        $this->auditLog->log($request, "Deleted training quotation ID #{$quoteId}");

        return response()->json(['status' => 'success', 'message' => 'Quotation and related inquiry source deleted successfully.']);
    }

    public function relatedDocsTraining(Request $request): JsonResponse
    {
        $quoteId = (int) $request->input('quote_id');
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid quote_id.'], 400);
        }

        return $this->fetchRelatedDocs($quoteId, 'training', '%training%');
    }

    private function fetchRelatedDocs(int $quoteId, string $quoteType, string $projectTypePattern): JsonResponse
    {
        $projects = DB::select("
            SELECT id, project_type
            FROM projects_main
            WHERE quote_id = ?
              AND (
                quote_type = ?
                OR (
                    (quote_type IS NULL OR TRIM(quote_type) = '')
                    AND LOWER(project_type) LIKE ?
                )
              )
        ", [$quoteId, $quoteType, $projectTypePattern]);

        $projectIds = array_values(array_filter(array_map(fn ($r) => (int) $r->id, $projects)));

        $deliveryOrders = [];
        $jd14Forms = [];
        $invoices = [];

        if (! empty($projectIds)) {
            $ph = implode(',', array_fill(0, count($projectIds), '?'));

            $deliveryOrders = DB::select("
                SELECT id, do_number FROM do_details WHERE project_id IN ({$ph}) ORDER BY id ASC
            ", $projectIds);

            $jd14Forms = DB::select("
                SELECT id, approval_no FROM invoices_jd14form WHERE project_id IN ({$ph}) ORDER BY id ASC
            ", $projectIds);

            $invoices = DB::select("
                SELECT id, invoice_ref_no, receipt_no FROM invoices WHERE project_id IN ({$ph}) ORDER BY id ASC
            ", $projectIds);
        }

        $receipts = array_values(array_filter($invoices, fn ($inv) => ! empty($inv->receipt_no)));

        return response()->json([
            'status' => 'success',
            'data' => [
                'projects' => $projects,
                'delivery_orders' => $deliveryOrders,
                'invoices' => $invoices,
                'receipts' => $receipts,
                'jd14' => $jd14Forms,
            ],
        ]);
    }
}
