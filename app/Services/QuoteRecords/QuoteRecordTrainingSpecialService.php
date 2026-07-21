<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use App\Http\Requests\QuoteRecord\AwardQuoteRequest;
use App\Http\Requests\QuoteRecord\FailQuoteRequest;
use App\Http\Requests\QuoteRecord\SpecialLineItemsByServiceRequest;
use App\Http\Requests\QuoteRecord\SyncClientRequest;
use App\Http\Requests\QuoteRecord\UnAwardQuoteRequest;
use App\Services\QuoteApprovals\QuoteApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        if ($denial = $this->approvalDenial($request, 'training')) {
            return $denial;
        }

        return $this->trainingQuoteRecordService()->awardTraining($request);
    }

    public function failTraining(FailQuoteRequest $request): JsonResponse
    {
        return $this->cancelApprovalAfter(
            $this->trainingQuoteRecordService()->failTraining($request),
            $request,
            'training',
            'Quotation marked as Failed.',
        );
    }

    public function reAwardTraining(AwardQuoteRequest $request): JsonResponse
    {
        if ($denial = $this->approvalDenial($request, 'training')) {
            return $denial;
        }

        return $this->trainingQuoteRecordService()->reAwardTraining($request);
    }

    public function unAwardTraining(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->trainingQuoteRecordService()->unAwardTraining($request);
    }

    public function destroyTraining(Request $request, int $id = 0): JsonResponse
    {
        return $this->cancelApprovalAfter(
            $this->trainingQuoteRecordService()->destroyTraining($request, $id),
            $request,
            'training',
            'Quotation deleted.',
            $id,
        );
    }

    public function relatedDocsTraining(Request $request): JsonResponse
    {
        return $this->trainingQuoteRecordService()->relatedDocsTraining($request);
    }

    public function pdfTraining(Request $request, int $id = 0)
    {
        if ($denial = $this->approvalDenial($request, 'training', $id)) {
            return $denial;
        }

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
        if ($denial = $this->approvalDenial($request, 'special')) {
            return $denial;
        }

        return $this->specialTrainingQuoteRecordService()->awardSpecial($request);
    }

    public function failSpecial(FailQuoteRequest $request): JsonResponse
    {
        return $this->cancelApprovalAfter(
            $this->specialTrainingQuoteRecordService()->failSpecial($request),
            $request,
            'special',
            'Quotation marked as Failed.',
        );
    }

    public function reAwardSpecial(AwardQuoteRequest $request): JsonResponse
    {
        if ($denial = $this->approvalDenial($request, 'special')) {
            return $denial;
        }

        return $this->specialTrainingQuoteRecordService()->reAwardSpecial($request);
    }

    public function unAwardSpecial(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->specialTrainingQuoteRecordService()->unAwardSpecial($request);
    }

    public function destroySpecial(Request $request, int $id = 0): JsonResponse
    {
        return $this->cancelApprovalAfter(
            $this->specialTrainingQuoteRecordService()->destroySpecial($request, $id),
            $request,
            'special',
            'Quotation deleted.',
            $id,
        );
    }

    public function relatedDocsSpecial(Request $request): JsonResponse
    {
        return $this->specialTrainingQuoteRecordService()->relatedDocsSpecial($request);
    }

    public function pdfSpecial(Request $request, int $id = 0)
    {
        if ($denial = $this->approvalDenial($request, 'special', $id)) {
            return $denial;
        }

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

    private function approvalDenial(Request $request, string $service, int $id = 0): ?JsonResponse
    {
        $quoteId = $id > 0 ? $id : (int) ($request->route('id') ?: $request->input('quote_id', $request->input('id', 0)));
        $denial = $quoteId > 0 ? app(QuoteApprovalService::class)->issuanceDenial($service, $quoteId, $request) : null;

        return $denial ? response()->json($denial, 409) : null;
    }

    private function cancelApprovalAfter(
        JsonResponse $response,
        Request $request,
        string $service,
        string $reason,
        int $id = 0,
    ): JsonResponse {
        $payload = $response->getData(true);
        if ($response->getStatusCode() < 300 && ($payload['status'] ?? null) === 'success') {
            $quoteId = $id > 0 ? $id : (int) ($request->route('id') ?: $request->input('quote_id', $request->input('id', 0)));
            if ($quoteId > 0) {
                app(QuoteApprovalService::class)->cancelCurrent($service, $quoteId, $reason);
            }
        }

        return $response;
    }
}
