<?php

namespace App\Services\QuoteRecords;

use App\Services\Quotes\Records\QuoteRecordRelatedDocsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteRelatedDocumentLookup
{
    public function __construct(private QuoteRecordRelatedDocsService $relatedDocs) {}

    public function discover(Request $request, string $service): JsonResponse
    {
        return $this->relatedDocs->discoverRelatedDocs($request, $service);
    }
}
