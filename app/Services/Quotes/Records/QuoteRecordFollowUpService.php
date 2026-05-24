<?php

namespace App\Services\Quotes\Records;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteRecordFollowUpService
{
    public function __construct(private QuoteRecordConfig $config) {}

    public function addQuoteFollowUp(Request $request, string $service): JsonResponse
    {
        $service = $this->config->normalizeServiceKey($service);
        $cfg = $this->config->quoteConfig($service);
        if (!$cfg) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported service type.'], 404);
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        $quoteId = (int) $request->input('quote_id', 0);
        $remarks = trim((string) $request->input('remarks', ''));
        $followUpDate = trim((string) $request->input('follow_up_date', ''));

        if ($quoteId <= 0 || $remarks === '' || $followUpDate === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing required fields.'], 422);
        }

        if (!DB::table($cfg['table'])->where('id', $quoteId)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        if (!$this->config->hasTable('quote_followups')) {
            return response()->json(['status' => 'error', 'message' => 'Follow-up table is unavailable.'], 500);
        }

        try {
            DB::table('quote_followups')->insert([
                'quote_id' => $quoteId,
                'quote_type' => $service,
                'remarks' => $remarks,
                'follow_up_date' => $followUpDate,
                'created_by' => $staffId,
                'created_at' => now(),
            ]);
            return response()->json(['status' => 'success', 'message' => 'Follow-up record added successfully']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }
    }
}
