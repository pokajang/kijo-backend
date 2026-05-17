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

class IhQuoteRecordService
{
    private function ihQuoteRecordListingService(): IhQuoteRecordListingService
    {
        return app(IhQuoteRecordListingService::class);
    }

    private function ihQuoteRecordFollowUpService(): IhQuoteRecordFollowUpService
    {
        return app(IhQuoteRecordFollowUpService::class);
    }

    private function ihQuoteRecordAwardWorkflowService(): IhQuoteRecordAwardWorkflowService
    {
        return app(IhQuoteRecordAwardWorkflowService::class);
    }

    private function ihQuoteRecordPdfService(): IhQuoteRecordPdfService
    {
        return app(IhQuoteRecordPdfService::class);
    }

    private function ihQuoteRecordClientSyncService(): IhQuoteRecordClientSyncService
    {
        return app(IhQuoteRecordClientSyncService::class);
    }

    public function listIh(Request $request): JsonResponse
    {
        return $this->ihQuoteRecordListingService()->listIh($request);
    }

    public function addIhFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordFollowUpService()->addIhFollowUp($request);
    }

    public function awardIh(AwardQuoteRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordAwardWorkflowService()->awardIh($request);
    }

    public function failIh(FailQuoteRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordAwardWorkflowService()->failIh($request);
    }

    public function reAwardIh(AwardQuoteRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordAwardWorkflowService()->reAwardIh($request);
    }

    public function unAwardIh(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordAwardWorkflowService()->unAwardIh($request);
    }

    public function destroyIh(Request $request, int $id = 0): JsonResponse
    {
        return $this->ihQuoteRecordListingService()->destroyIh($request, $id);
    }

    public function relatedDocsIh(Request $request): JsonResponse
    {
        return $this->ihQuoteRecordListingService()->relatedDocsIh($request);
    }

    public function pdfIh(Request $request, int $id = 0)
    {
        return $this->ihQuoteRecordPdfService()->pdfIh($request, $id);
    }

    public function syncClientIh(SyncClientRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordClientSyncService()->syncClientIh($request);
    }
}
