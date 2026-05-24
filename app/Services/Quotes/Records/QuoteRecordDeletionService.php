<?php

namespace App\Services\Quotes\Records;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteRecordDeletionService
{
    public function __construct(private QuoteRecordConfig $config) {}

    public function deleteQuoteRecord(Request $request, string $service): JsonResponse
    {
        $service = $this->config->normalizeServiceKey($service);
        $cfg = $this->config->quoteConfig($service);
        if (!$cfg) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported service type.'], 404);
        }

        $quoteId = (int) ($request->input('id') ?? $request->input('quote_id', 0));
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing quote ID.'], 422);
        }

        $quote = DB::table($cfg['table'])->where('id', $quoteId)->first();
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found.'], 404);
        }
        if (strtolower((string) ($quote->status ?? '')) === 'awarded') {
            return response()->json(['status' => 'error', 'message' => 'Cannot delete an awarded quotation.'], 403);
        }

        DB::beginTransaction();
        try {
            if ($cfg['child_table'] && $this->config->hasTable($cfg['child_table'])) {
                DB::table($cfg['child_table'])->where('quote_id', $quoteId)->delete();
            }
            if ($this->config->hasTable('quote_inquiry_sources')) {
                DB::table('quote_inquiry_sources')->where('quote_id', $quoteId)->delete();
                if (isset($quote->quote_ref_no)) {
                    DB::table('quote_inquiry_sources')->where('quote_ref_no', (string) $quote->quote_ref_no)->delete();
                }
            }
            DB::table($cfg['table'])->where('id', $quoteId)->delete();
            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Quotation deleted successfully.']);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }
    }
}
