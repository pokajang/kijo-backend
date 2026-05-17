<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
use App\Services\QuoteRecords\QuoteRecordTrainingSpecialService;

class QuoteRecordTrainingSpecialController extends Controller
{
    private function quoteRecordTrainingSpecialService(): QuoteRecordTrainingSpecialService
    {
        return app(QuoteRecordTrainingSpecialService::class);
    }

    public function listTraining(): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->listTraining();
    }

    public function addTrainingFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->addTrainingFollowUp($request);
    }

    public function awardTraining(AwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->awardTraining($request);
    }

    public function failTraining(FailQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->failTraining($request);
    }

    public function reAwardTraining(AwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->reAwardTraining($request);
    }

    public function unAwardTraining(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->unAwardTraining($request);
    }

    public function destroyTraining(Request $request, int $id = 0): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->destroyTraining($request, $id);
    }

    public function relatedDocsTraining(Request $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->relatedDocsTraining($request);
    }

    public function pdfTraining(Request $request, int $id = 0)
    {
        return $this->quoteRecordTrainingSpecialService()->pdfTraining($request, $id);
    }

    public function syncClientTraining(SyncClientRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->syncClientTraining($request);
    }

    public function listSpecial(): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->listSpecial();
    }

    public function addSpecialFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->addSpecialFollowUp($request);
    }

    public function awardSpecial(AwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->awardSpecial($request);
    }

    public function failSpecial(FailQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->failSpecial($request);
    }

    public function reAwardSpecial(AwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->reAwardSpecial($request);
    }

    public function unAwardSpecial(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->unAwardSpecial($request);
    }

    public function destroySpecial(Request $request, int $id = 0): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->destroySpecial($request, $id);
    }

    public function relatedDocsSpecial(Request $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->relatedDocsSpecial($request);
    }

    public function pdfSpecial(Request $request, int $id = 0)
    {
        return $this->quoteRecordTrainingSpecialService()->pdfSpecial($request, $id);
    }

    public function syncClientSpecial(SyncClientRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->syncClientSpecial($request);
    }

    public function specialLineItemsByService(SpecialLineItemsByServiceRequest $request): JsonResponse
    {
        return $this->quoteRecordTrainingSpecialService()->specialLineItemsByService($request);
    }
}
