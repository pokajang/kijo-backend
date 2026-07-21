<?php

namespace App\Services\Quotes;

use App\Http\Requests\Quote\StoreEquipmentQuoteRequest;
use App\Http\Requests\Quote\StoreIhQuoteRequest;
use App\Http\Requests\Quote\StoreManpowerQuoteRequest;
use App\Http\Requests\Quote\StoreSpecialQuoteRequest;
use App\Http\Requests\Quote\StoreTrainingQuoteRequest;
use App\Http\Requests\Quote\UpdateEquipmentQuoteRequest;
use App\Http\Requests\Quote\UpdateIhQuoteRequest;
use App\Http\Requests\Quote\UpdateManpowerQuoteRequest;
use App\Http\Requests\Quote\UpdateSpecialQuoteRequest;
use App\Http\Requests\Quote\UpdateTrainingQuoteRequest;
use App\Services\QuoteApprovals\QuoteApprovalService;
use App\Services\Quotes\Crud\EquipmentQuoteService;
use App\Services\Quotes\Crud\IhQuoteService;
use App\Services\Quotes\Crud\ManpowerQuoteService;
use App\Services\Quotes\Crud\SpecialQuoteService;
use App\Services\Quotes\Crud\TrainingQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteCrudService
{
    public function __construct(
        private EquipmentQuoteService $equipmentQuotes,
        private ManpowerQuoteService $manpowerQuotes,
        private IhQuoteService $ihQuotes,
        private SpecialQuoteService $specialQuotes,
        private TrainingQuoteService $trainingQuotes,
    ) {}

    public function showEquipment(Request $request, int $id): JsonResponse
    {
        return $this->equipmentQuotes->showEquipment($request, $id);
    }

    public function storeEquipment(StoreEquipmentQuoteRequest $request): JsonResponse
    {
        if ($denial = $this->preparerDenial($request)) {
            return $denial;
        }

        return $this->finalize($this->equipmentQuotes->storeEquipment($request), 'equipment');
    }

    public function updateEquipment(UpdateEquipmentQuoteRequest $request, int $id): JsonResponse
    {
        return $this->finalize($this->equipmentQuotes->updateEquipment($request, $id), 'equipment', $id);
    }

    public function showManpower(Request $request, int $id): JsonResponse
    {
        return $this->manpowerQuotes->showManpower($request, $id);
    }

    public function storeManpower(StoreManpowerQuoteRequest $request): JsonResponse
    {
        if ($denial = $this->preparerDenial($request)) {
            return $denial;
        }

        return $this->finalize($this->manpowerQuotes->storeManpower($request), 'manpower');
    }

    public function updateManpower(UpdateManpowerQuoteRequest $request, int $id): JsonResponse
    {
        return $this->finalize($this->manpowerQuotes->updateManpower($request, $id), 'manpower', $id);
    }

    public function showIh(Request $request, int $id): JsonResponse
    {
        return $this->ihQuotes->showIh($request, $id);
    }

    public function storeIh(StoreIhQuoteRequest $request): JsonResponse
    {
        if ($denial = $this->preparerDenial($request)) {
            return $denial;
        }

        return $this->finalize($this->ihQuotes->storeIh($request), 'ih');
    }

    public function updateIh(UpdateIhQuoteRequest $request, int $id): JsonResponse
    {
        return $this->finalize($this->ihQuotes->updateIh($request, $id), 'ih', $id);
    }

    public function showSpecial(Request $request, int $id): JsonResponse
    {
        return $this->specialQuotes->showSpecial($request, $id);
    }

    public function storeSpecial(StoreSpecialQuoteRequest $request): JsonResponse
    {
        if ($denial = $this->preparerDenial($request)) {
            return $denial;
        }

        return $this->finalize($this->specialQuotes->storeSpecial($request), 'special');
    }

    public function updateSpecial(UpdateSpecialQuoteRequest $request, int $id): JsonResponse
    {
        return $this->finalize($this->specialQuotes->updateSpecial($request, $id), 'special', $id);
    }

    public function showTraining(Request $request, int $id): JsonResponse
    {
        return $this->trainingQuotes->showTraining($request, $id);
    }

    public function storeTraining(StoreTrainingQuoteRequest $request): JsonResponse
    {
        if ($denial = $this->preparerDenial($request)) {
            return $denial;
        }

        return $this->finalize($this->trainingQuotes->storeTraining($request), 'training');
    }

    public function updateTraining(UpdateTrainingQuoteRequest $request, int $id): JsonResponse
    {
        return $this->finalize($this->trainingQuotes->updateTraining($request, $id), 'training', $id);
    }

    private function finalize(JsonResponse $response, string $service, ?int $quoteId = null): JsonResponse
    {
        if ($response->getStatusCode() >= 300) {
            return $response;
        }
        $payload = $response->getData(true);
        if (($payload['status'] ?? null) !== 'success') {
            return $response;
        }
        $resolvedId = $quoteId
            ?: (int) ($payload['quote_id'] ?? $payload['data']['quote_id'] ?? $payload['data']['id'] ?? 0);
        if ($resolvedId > 0) {
            app(QuoteApprovalService::class)->current($service, $resolvedId);
        }

        return $response;
    }

    private function preparerDenial(Request $request): ?JsonResponse
    {
        if ((int) $request->session()->get('staff_id', 0) > 0) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'code' => 'QUOTE_PREPARER_REQUIRED',
            'message' => 'Your staff identity is unavailable. Sign in again before creating a quotation.',
        ], 422);
    }
}
