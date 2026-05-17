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

class QuoteRecordTrainingSpecialService
{
    private function trainingQuoteRecordService(): TrainingQuoteRecordService
    {
        return app(TrainingQuoteRecordService::class);
    }

    private function specialTrainingQuoteRecordService(): SpecialTrainingQuoteRecordService
    {
        return app(SpecialTrainingQuoteRecordService::class);
    }

    public function listTraining(): JsonResponse
    {
        return $this->trainingQuoteRecordService()->listTraining();
    }

    public function addTrainingFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordService()->addTrainingFollowUp($request);
    }

    public function awardTraining(AwardQuoteRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordService()->awardTraining($request);
    }

    public function failTraining(FailQuoteRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordService()->failTraining($request);
    }

    public function reAwardTraining(AwardQuoteRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordService()->reAwardTraining($request);
    }

    public function unAwardTraining(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordService()->unAwardTraining($request);
    }

    public function destroyTraining(Request $request, int $id = 0): JsonResponse
    {
        return $this->trainingQuoteRecordService()->destroyTraining($request, $id);
    }

    public function relatedDocsTraining(Request $request): JsonResponse
    {
        return $this->trainingQuoteRecordService()->relatedDocsTraining($request);
    }

    public function pdfTraining(Request $request, int $id = 0)
    {
        return $this->trainingQuoteRecordService()->pdfTraining($request, $id);
    }

    public function syncClientTraining(SyncClientRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordService()->syncClientTraining($request);
    }

    public function listSpecial(): JsonResponse
    {
        return $this->specialTrainingQuoteRecordService()->listSpecial();
    }

    public function addSpecialFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->specialTrainingQuoteRecordService()->addSpecialFollowUp($request);
    }

    public function awardSpecial(AwardQuoteRequest $request): JsonResponse
    {
        return $this->specialTrainingQuoteRecordService()->awardSpecial($request);
    }

    public function failSpecial(FailQuoteRequest $request): JsonResponse
    {
        return $this->specialTrainingQuoteRecordService()->failSpecial($request);
    }

    public function reAwardSpecial(AwardQuoteRequest $request): JsonResponse
    {
        return $this->specialTrainingQuoteRecordService()->reAwardSpecial($request);
    }

    public function unAwardSpecial(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->specialTrainingQuoteRecordService()->unAwardSpecial($request);
    }

    public function destroySpecial(Request $request, int $id = 0): JsonResponse
    {
        return $this->specialTrainingQuoteRecordService()->destroySpecial($request, $id);
    }

    public function relatedDocsSpecial(Request $request): JsonResponse
    {
        return $this->specialTrainingQuoteRecordService()->relatedDocsSpecial($request);
    }

    public function pdfSpecial(Request $request, int $id = 0)
    {
        return $this->specialTrainingQuoteRecordService()->pdfSpecial($request, $id);
    }

    public function syncClientSpecial(SyncClientRequest $request): JsonResponse
    {
        return $this->specialTrainingQuoteRecordService()->syncClientSpecial($request);
    }

    public function specialLineItemsByService(SpecialLineItemsByServiceRequest $request): JsonResponse
    {
        return $this->specialTrainingQuoteRecordService()->specialLineItemsByService($request);
    }
}
