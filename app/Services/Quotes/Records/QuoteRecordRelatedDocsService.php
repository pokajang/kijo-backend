<?php

namespace App\Services\Quotes\Records;

use App\Services\QuoteRecords\QuoteRelatedDocsPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteRecordRelatedDocsService
{
    public function __construct(private QuoteRecordConfig $config) {}

    public function discoverRelatedDocs(Request $request, string $service): JsonResponse
    {
        $service = $this->config->normalizeServiceKey($service);
        $cfg = $this->config->quoteConfig($service);
        if (!$cfg) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported service type.'], 404);
        }

        $quoteId = (int) $request->input('quote_id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid quote_id.'], 422);
        }

        $patterns = $this->config->projectTypeLike($service);
        $patterns = is_array($patterns) ? $patterns : [$patterns];

        return response()->json([
            'status' => 'success',
            'data' => QuoteRelatedDocsPayload::forQuote($quoteId, $service, ...$patterns),
        ]);
    }
}
