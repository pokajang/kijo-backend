<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use App\Http\Requests\QuoteRecord\AwardQuoteRequest;
use App\Http\Requests\QuoteRecord\FailQuoteRequest;
use App\Http\Requests\QuoteRecord\SyncClientRequest;
use App\Http\Requests\QuoteRecord\UnAwardQuoteRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManpowerQuoteRecordService
{
    private function manpowerQuoteRecordListingService(): ManpowerQuoteRecordListingService
    {
        return app(ManpowerQuoteRecordListingService::class);
    }

    private function manpowerQuoteRecordFollowUpService(): ManpowerQuoteRecordFollowUpService
    {
        return app(ManpowerQuoteRecordFollowUpService::class);
    }

    private function manpowerQuoteRecordAwardWorkflowService(): ManpowerQuoteRecordAwardWorkflowService
    {
        return app(ManpowerQuoteRecordAwardWorkflowService::class);
    }

    private function manpowerQuoteRecordPdfService(): ManpowerQuoteRecordPdfService
    {
        return app(ManpowerQuoteRecordPdfService::class);
    }

    private function manpowerQuoteRecordClientSyncService(): ManpowerQuoteRecordClientSyncService
    {
        return app(ManpowerQuoteRecordClientSyncService::class);
    }

    public function listManpower(Request $request): JsonResponse
    {
        return $this->manpowerQuoteRecordListingService()->listManpower($request);
    }

    public function addManpowerFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordFollowUpService()->addManpowerFollowUp($request);
    }

    public function awardManpower(AwardQuoteRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordAwardWorkflowService()->awardManpower($request);
    }

    public function failManpower(FailQuoteRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordAwardWorkflowService()->failManpower($request);
    }

    public function reAwardManpower(AwardQuoteRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordAwardWorkflowService()->reAwardManpower($request);
    }

    public function unAwardManpower(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordAwardWorkflowService()->unAwardManpower($request);
    }

    public function destroyManpower(Request $request, int $id = 0): JsonResponse
    {
        return $this->manpowerQuoteRecordListingService()->destroyManpower($request, $id);
    }

    public function relatedDocsManpower(Request $request): JsonResponse
    {
        return $this->manpowerQuoteRecordListingService()->relatedDocsManpower($request);
    }

    public function pdfManpower(Request $request, int $id = 0): mixed
    {
        return $this->manpowerQuoteRecordPdfService()->pdfManpower($request, $id);
    }

    public function syncClientManpower(SyncClientRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordClientSyncService()->syncClientManpower($request);
    }
}
