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
        return $this->equipmentQuotes->storeEquipment($request);
    }

    public function updateEquipment(UpdateEquipmentQuoteRequest $request, int $id): JsonResponse
    {
        return $this->equipmentQuotes->updateEquipment($request, $id);
    }

    public function showManpower(Request $request, int $id): JsonResponse
    {
        return $this->manpowerQuotes->showManpower($request, $id);
    }

    public function storeManpower(StoreManpowerQuoteRequest $request): JsonResponse
    {
        return $this->manpowerQuotes->storeManpower($request);
    }

    public function updateManpower(UpdateManpowerQuoteRequest $request, int $id): JsonResponse
    {
        return $this->manpowerQuotes->updateManpower($request, $id);
    }

    public function showIh(Request $request, int $id): JsonResponse
    {
        return $this->ihQuotes->showIh($request, $id);
    }

    public function storeIh(StoreIhQuoteRequest $request): JsonResponse
    {
        return $this->ihQuotes->storeIh($request);
    }

    public function updateIh(UpdateIhQuoteRequest $request, int $id): JsonResponse
    {
        return $this->ihQuotes->updateIh($request, $id);
    }

    public function showSpecial(Request $request, int $id): JsonResponse
    {
        return $this->specialQuotes->showSpecial($request, $id);
    }

    public function storeSpecial(StoreSpecialQuoteRequest $request): JsonResponse
    {
        return $this->specialQuotes->storeSpecial($request);
    }

    public function updateSpecial(UpdateSpecialQuoteRequest $request, int $id): JsonResponse
    {
        return $this->specialQuotes->updateSpecial($request, $id);
    }

    public function showTraining(Request $request, int $id): JsonResponse
    {
        return $this->trainingQuotes->showTraining($request, $id);
    }

    public function storeTraining(StoreTrainingQuoteRequest $request): JsonResponse
    {
        return $this->trainingQuotes->storeTraining($request);
    }

    public function updateTraining(UpdateTrainingQuoteRequest $request, int $id): JsonResponse
    {
        return $this->trainingQuotes->updateTraining($request, $id);
    }
}
