<?php

namespace App\Services\Invoices;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceQuoteLookupService
{
    public function quoteTraining(Request $request, int $id): JsonResponse
    {
        if ($id < 1) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid quote ID.'], 422);
        }
        $quote = DB::table('quotes_training')->where('id', $id)->first();
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }
        return response()->json($quote);
    }

    public function quoteEquipment(Request $request, int $id): JsonResponse
    {
        if ($id < 1) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid quote ID.'], 422);
        }

        $quote = DB::table('quotes_equipment as qe')
            ->where('qe.id', $id)
            ->selectRaw('qe.id, qe.service_group, qe.quote_running_no, qe.quote_ref_no, qe.revision_no,
                qe.created_at, qe.updated_at, qe.status, qe.status_remarks, qe.award_date,
                qe.client_award_ref_no, qe.created_by_id, qe.created_by_name, qe.created_by_code,
                qe.client_id, qe.client_name, qe.client_ssm, qe.client_address, qe.client_city,
                qe.client_state, qe.client_zip, qe.pic_name, qe.pic_email, qe.pic_phone, qe.pic_position,
                qe.inquiry_remarks, qe.discount, qe.delivery_charge, qe.misc_charge,
                qe.sst_percent, qe.sst_amount, qe.sub_total AS subtotal, qe.grand_total, qe.attach_proposal')
            ->first();

        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Equipment quote not found.'], 404);
        }

        $items = DB::table('quotes_equipment_items as qi')
            ->leftJoin('catalog_items as ci', 'ci.id', '=', 'qi.item_id')
            ->where('qi.quote_id', $id)
            ->orderBy('qi.id')
            ->get(['qi.id', 'qi.item_id', 'qi.quantity', 'qi.unit_price', 'qi.marked_up_price', 'qi.line_total',
                   'ci.item_name', 'ci.description', 'ci.unit']);

        $quote->equipment_items = $items;
        return response()->json($quote);
    }

    public function quoteManpower(Request $request, int $id): JsonResponse
    {
        if ($id < 1) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid quote ID.'], 422);
        }
        $quote = DB::table('quotes_manpower')->where('id', $id)->first();
        if (!$quote) {
            return response()->json(['error' => 'Manpower quote not found'], 404);
        }
        return response()->json($quote);
    }

    public function quoteIh(Request $request, int $id): JsonResponse
    {
        if ($id < 1) {
            return response()->json(['status' => 'error', 'message' => 'A valid id parameter is required.'], 422);
        }

        $quote = DB::table('quotes_ih')->where('id', $id)->first();
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Hygiene quote not found.'], 404);
        }

        $quote->travel_charge     = (float) $quote->travel_charge;
        $quote->sample_counts     = (int)   $quote->sample_counts;
        $quote->num_work_units    = (int)   $quote->num_work_units;
        $quote->unit_price        = (float) $quote->unit_price;
        $quote->discount          = (float) $quote->discount;
        $quote->sst_percent       = (float) $quote->sst_percent;
        $quote->sst_amount        = (float) $quote->sst_amount;
        $quote->sub_total         = (float) $quote->sub_total;
        $quote->grand_total       = (float) $quote->grand_total;

        return response()->json(['status' => 'success', 'data' => $quote]);
    }

    public function quoteSpecial(Request $request, int $id): JsonResponse
    {
        if ($id < 1) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or missing quote id'], 422);
        }

        $quote = DB::table('quotes_special')
            ->where('id', $id)
            ->selectRaw('id, client_name, client_ssm, client_address, client_city, client_state, client_zip,
                pic_name, pic_email, pic_phone, pic_position, client_award_ref_no,
                sp_id AS service_id, service_title, service_code, general_remarks, inquiry_remarks,
                unit_cost, discount, sst_percent, sst_amount, sub_total, grand_total, proposal_language')
            ->first();

        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found'], 404);
        }

        $items = DB::table('quotes_special_items')
            ->where('quote_id', $id)
            ->orderBy('id')
            ->get(['id', 'service_id', 'line_item_title', 'description', 'unit', 'unit_price', 'quantity', 'line_total']);

        $quote->special_items = $items;
        return response()->json($quote);
    }
}
