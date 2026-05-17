<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use App\Http\Requests\QuoteRecord\AwardQuoteRequest;
use App\Http\Requests\QuoteRecord\FailQuoteRequest;
use App\Http\Requests\QuoteRecord\SpecialLineItemsByServiceRequest;
use App\Http\Requests\QuoteRecord\SyncClientRequest;
use App\Http\Requests\QuoteRecord\UnAwardQuoteRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpecialTrainingQuoteRecordService
{
    private function specialQuoteRecordListingService(): SpecialQuoteRecordListingService
    {
        return app(SpecialQuoteRecordListingService::class);
    }

    private function specialQuoteRecordFollowUpService(): SpecialQuoteRecordFollowUpService
    {
        return app(SpecialQuoteRecordFollowUpService::class);
    }

    private function specialQuoteRecordAwardWorkflowService(): SpecialQuoteRecordAwardWorkflowService
    {
        return app(SpecialQuoteRecordAwardWorkflowService::class);
    }

    private function specialQuoteRecordPdfService(): SpecialQuoteRecordPdfService
    {
        return app(SpecialQuoteRecordPdfService::class);
    }

    private function specialQuoteRecordClientSyncService(): SpecialQuoteRecordClientSyncService
    {
        return app(SpecialQuoteRecordClientSyncService::class);
    }

    public function listSpecial(): JsonResponse
    {
        return $this->specialQuoteRecordListingService()->listSpecial();
    }

    public function addSpecialFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->specialQuoteRecordFollowUpService()->addSpecialFollowUp($request);
    }

    public function awardSpecial(AwardQuoteRequest $request): JsonResponse
    {
        return $this->specialQuoteRecordAwardWorkflowService()->awardSpecial($request);
    }

    public function failSpecial(FailQuoteRequest $request): JsonResponse
    {
        return $this->specialQuoteRecordAwardWorkflowService()->failSpecial($request);
    }

    public function reAwardSpecial(AwardQuoteRequest $request): JsonResponse
    {
        return $this->specialQuoteRecordAwardWorkflowService()->reAwardSpecial($request);
    }

    public function unAwardSpecial(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->specialQuoteRecordAwardWorkflowService()->unAwardSpecial($request);
    }

    public function destroySpecial(Request $request, int $id = 0): JsonResponse
    {
        return $this->specialQuoteRecordListingService()->destroySpecial($request, $id);
    }

    public function relatedDocsSpecial(Request $request): JsonResponse
    {
        return $this->specialQuoteRecordListingService()->relatedDocsSpecial($request);
    }

    public function pdfSpecial(Request $request, int $id = 0)
    {
        return $this->specialQuoteRecordPdfService()->pdfSpecial($request, $id);
    }

    public function syncClientSpecial(SyncClientRequest $request): JsonResponse
    {
        return $this->specialQuoteRecordClientSyncService()->syncClientSpecial($request);
    }

    public function specialLineItemsByService(SpecialLineItemsByServiceRequest $request): JsonResponse
    {
        return $this->specialQuoteRecordListingService()->specialLineItemsByService($request);
    }
}
