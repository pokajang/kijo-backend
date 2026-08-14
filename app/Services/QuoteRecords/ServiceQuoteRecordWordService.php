<?php

namespace App\Services\QuoteRecords;

use App\Services\AuditLogService;
use App\Services\Word\QuotationWordDocumentBuilder;
use App\Services\Word\WordRenderer;
use Illuminate\Http\Request;

final class ServiceQuoteRecordWordService extends WordRenderer
{
    private const SERVICES = ['training', 'ih', 'manpower', 'special'];

    public function __construct(
        private AuditLogService $auditLog,
        private ServiceQuoteDocumentData $documentData,
        private QuotationWordDocumentBuilder $documentBuilder,
    ) {}

    public function downloadQuote(Request $request, string $service, int $id = 0): mixed
    {
        if (! in_array($service, self::SERVICES, true)) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported quotation service'], 404);
        }
        $quoteId = $id > 0 ? $id : (int) $request->query('quote_id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'quote_id is required'], 400);
        }
        $data = $this->documentData->find($service, $quoteId);
        if ($data === null) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        $document = $this->documentBuilder->build($data, $request);
        $this->auditLog->log($request, 'Generated '.strtoupper($service)." quotation Word document for quote ID #{$quoteId}");

        return $this->download($document, ($data['quoteRefNo'] ?: "quote-{$quoteId}").'_'.($data['clientName'] ?: 'client').'.docx');
    }
}
