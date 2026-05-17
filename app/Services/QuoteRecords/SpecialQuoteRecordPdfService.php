<?php

namespace App\Services\QuoteRecords;

use App\Services\Quotes\QuotePdfService;
use Illuminate\Http\Request;

class SpecialQuoteRecordPdfService
{
    public function __construct(private QuotePdfService $quotePdfService) {}

    public function pdfSpecial(Request $request, int $id = 0)
    {
        if ($id > 0) {
            $request->merge(['quote_id' => $id]);
            $request->query->set('quote_id', $id);
        }

        return $this->quotePdfService->generateLegacyPdf($request, 'special');
    }
}
