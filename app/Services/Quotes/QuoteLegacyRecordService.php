<?php

namespace App\Services\Quotes;

use App\Services\Quotes\Records\QuoteRecordAwardService;
use App\Services\Quotes\Records\QuoteRecordClientSyncService;
use App\Services\Quotes\Records\QuoteRecordDeletionService;
use App\Services\Quotes\Records\QuoteRecordFollowUpService;
use App\Services\Quotes\Records\QuoteRecordListingService;
use App\Services\Quotes\Records\QuoteRecordRelatedDocsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteLegacyRecordService
{
    public function __construct(
        private QuoteRecordListingService $listingService,
        private QuoteRecordFollowUpService $followUpService,
        private QuoteRecordAwardService $awardService,
        private QuoteRecordDeletionService $deletionService,
        private QuoteRecordClientSyncService $clientSyncService,
        private QuoteRecordRelatedDocsService $relatedDocsService,
    ) {}

    public function listQuoteRecords(Request $request, string $service): JsonResponse
    {
        return $this->listingService->listQuoteRecords($request, $service);
    }

    public function addQuoteFollowUp(Request $request, string $service): JsonResponse
    {
        return $this->followUpService->addQuoteFollowUp($request, $service);
    }

    public function changeQuoteToFail(Request $request, string $service): JsonResponse
    {
        return $this->awardService->changeQuoteToFail($request, $service);
    }

    public function changeQuoteToSuccess(Request $request, string $service): JsonResponse
    {
        return $this->awardService->changeQuoteToSuccess($request, $service);
    }

    public function reAwardQuote(Request $request, string $service): JsonResponse
    {
        return $this->awardService->reAwardQuote($request, $service);
    }

    public function unAwardQuote(Request $request, string $service): JsonResponse
    {
        return $this->awardService->unAwardQuote($request, $service);
    }

    public function deleteQuoteRecord(Request $request, string $service): JsonResponse
    {
        return $this->deletionService->deleteQuoteRecord($request, $service);
    }

    public function syncClientDetails(Request $request, string $service): JsonResponse
    {
        return $this->clientSyncService->syncClientDetails($request, $service);
    }

    public function discoverRelatedDocs(Request $request, string $service): JsonResponse
    {
        return $this->relatedDocsService->discoverRelatedDocs($request, $service);
    }

    public function listSpecialLineItemsByService(Request $request): JsonResponse
    {
        return $this->listingService->listSpecialLineItemsByService($request);
    }
}
