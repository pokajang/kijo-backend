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

class TrainingQuoteRecordService
{
    private function trainingQuoteRecordListingService(): TrainingQuoteRecordListingService
    {
        return app(TrainingQuoteRecordListingService::class);
    }

    private function trainingQuoteRecordFollowUpService(): TrainingQuoteRecordFollowUpService
    {
        return app(TrainingQuoteRecordFollowUpService::class);
    }

    private function trainingQuoteRecordAwardWorkflowService(): TrainingQuoteRecordAwardWorkflowService
    {
        return app(TrainingQuoteRecordAwardWorkflowService::class);
    }

    private function trainingQuoteRecordPdfService(): TrainingQuoteRecordPdfService
    {
        return app(TrainingQuoteRecordPdfService::class);
    }

    private function trainingQuoteRecordClientSyncService(): TrainingQuoteRecordClientSyncService
    {
        return app(TrainingQuoteRecordClientSyncService::class);
    }

    public function listTraining(): JsonResponse
    {
        return $this->trainingQuoteRecordListingService()->listTraining();
    }

    public function addTrainingFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordFollowUpService()->addTrainingFollowUp($request);
    }

    public function awardTraining(AwardQuoteRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordAwardWorkflowService()->awardTraining($request);
    }

    public function failTraining(FailQuoteRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordAwardWorkflowService()->failTraining($request);
    }

    public function reAwardTraining(AwardQuoteRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordAwardWorkflowService()->reAwardTraining($request);
    }

    public function unAwardTraining(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordAwardWorkflowService()->unAwardTraining($request);
    }

    public function destroyTraining(Request $request, int $id = 0): JsonResponse
    {
        return $this->trainingQuoteRecordListingService()->destroyTraining($request, $id);
    }

    public function relatedDocsTraining(Request $request): JsonResponse
    {
        return $this->trainingQuoteRecordListingService()->relatedDocsTraining($request);
    }

    public function pdfTraining(Request $request, int $id = 0)
    {
        return $this->trainingQuoteRecordPdfService()->pdfTraining($request, $id);
    }

    public function syncClientTraining(SyncClientRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordClientSyncService()->syncClientTraining($request);
    }
}
