<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\SaveInquirySourceRequest;
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
use App\Services\Quotes\QuoteCrudService;
use App\Services\Quotes\QuoteLegacyRecordService;
use App\Services\Quotes\QuotePdfService;
use App\Services\Quotes\QuoteUtilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    private function quoteCrudService(): QuoteCrudService
    {
        return app(QuoteCrudService::class);
    }

    private function quoteLegacyRecordService(): QuoteLegacyRecordService
    {
        return app(QuoteLegacyRecordService::class);
    }

    private function quotePdfService(): QuotePdfService
    {
        return app(QuotePdfService::class);
    }

    private function quoteUtilityService(): QuoteUtilityService
    {
        return app(QuoteUtilityService::class);
    }

    // -------------------------------------------------------------------------
    // EQUIPMENT (QES)
    // -------------------------------------------------------------------------

    public function showEquipment(Request $request, int $id): JsonResponse
    {
        return $this->quoteCrudService()->showEquipment($request, $id);
    }

    public function storeEquipment(StoreEquipmentQuoteRequest $request): JsonResponse
    {
        return $this->quoteCrudService()->storeEquipment($request);
    }

    public function updateEquipment(UpdateEquipmentQuoteRequest $request, int $id): JsonResponse
    {
        return $this->quoteCrudService()->updateEquipment($request, $id);
    }

    // -------------------------------------------------------------------------
    // MANPOWER (QMS)
    // -------------------------------------------------------------------------

    public function showManpower(Request $request, int $id): JsonResponse
    {
        return $this->quoteCrudService()->showManpower($request, $id);
    }

    public function storeManpower(StoreManpowerQuoteRequest $request): JsonResponse
    {
        return $this->quoteCrudService()->storeManpower($request);
    }

    public function updateManpower(UpdateManpowerQuoteRequest $request, int $id): JsonResponse
    {
        return $this->quoteCrudService()->updateManpower($request, $id);
    }

    // -------------------------------------------------------------------------
    // IH (QIH)
    // -------------------------------------------------------------------------

    public function showIh(Request $request, int $id): JsonResponse
    {
        return $this->quoteCrudService()->showIh($request, $id);
    }

    public function storeIh(StoreIhQuoteRequest $request): JsonResponse
    {
        return $this->quoteCrudService()->storeIh($request);
    }

    public function updateIh(UpdateIhQuoteRequest $request, int $id): JsonResponse
    {
        return $this->quoteCrudService()->updateIh($request, $id);
    }

    // -------------------------------------------------------------------------
    // SPECIAL (QSS)
    // -------------------------------------------------------------------------

    public function showSpecial(Request $request, int $id): JsonResponse
    {
        return $this->quoteCrudService()->showSpecial($request, $id);
    }

    public function storeSpecial(StoreSpecialQuoteRequest $request): JsonResponse
    {
        return $this->quoteCrudService()->storeSpecial($request);
    }

    public function updateSpecial(UpdateSpecialQuoteRequest $request, int $id): JsonResponse
    {
        return $this->quoteCrudService()->updateSpecial($request, $id);
    }

    // -------------------------------------------------------------------------
    // TRAINING (QTR)
    // -------------------------------------------------------------------------

    public function showTraining(Request $request, int $id): JsonResponse
    {
        return $this->quoteCrudService()->showTraining($request, $id);
    }

    public function storeTraining(StoreTrainingQuoteRequest $request): JsonResponse
    {
        return $this->quoteCrudService()->storeTraining($request);
    }

    public function updateTraining(UpdateTrainingQuoteRequest $request, int $id): JsonResponse
    {
        return $this->quoteCrudService()->updateTraining($request, $id);
    }

    // -------------------------------------------------------------------------
    // UTILITY
    // -------------------------------------------------------------------------

    public function listTrainingTopics(Request $request): JsonResponse
    {
        return $this->quoteUtilityService()->listTrainingTopics($request);
    }

    public function saveInquirySource(SaveInquirySourceRequest $request): JsonResponse
    {
        return $this->quoteUtilityService()->saveInquirySource($request);
    }

    // -------------------------------------------------------------------------
    // QUOTE RECORDS (legacy compatibility)
    // -------------------------------------------------------------------------

    public function listQuoteRecords(Request $request, string $service): JsonResponse
    {
        return $this->quoteLegacyRecordService()->listQuoteRecords($request, $service);
    }

    public function addQuoteFollowUp(Request $request, string $service): JsonResponse
    {
        return $this->quoteLegacyRecordService()->addQuoteFollowUp($request, $service);
    }

    public function changeQuoteToFail(Request $request, string $service): JsonResponse
    {
        return $this->quoteLegacyRecordService()->changeQuoteToFail($request, $service);
    }

    public function changeQuoteToSuccess(Request $request, string $service): JsonResponse
    {
        return $this->quoteLegacyRecordService()->changeQuoteToSuccess($request, $service);
    }

    public function reAwardQuote(Request $request, string $service): JsonResponse
    {
        return $this->quoteLegacyRecordService()->reAwardQuote($request, $service);
    }

    public function unAwardQuote(Request $request, string $service): JsonResponse
    {
        return $this->quoteLegacyRecordService()->unAwardQuote($request, $service);
    }

    public function deleteQuoteRecord(Request $request, string $service): JsonResponse
    {
        return $this->quoteLegacyRecordService()->deleteQuoteRecord($request, $service);
    }

    public function syncClientDetails(Request $request, string $service): JsonResponse
    {
        return $this->quoteLegacyRecordService()->syncClientDetails($request, $service);
    }

    public function discoverRelatedDocs(Request $request, string $service): JsonResponse
    {
        return $this->quoteLegacyRecordService()->discoverRelatedDocs($request, $service);
    }

    public function generateLegacyPdf(Request $request, string $service)
    {
        return $this->quotePdfService()->generateLegacyPdf($request, $service);
    }

    public function listSpecialLineItemsByService(Request $request): JsonResponse
    {
        return $this->quoteLegacyRecordService()->listSpecialLineItemsByService($request);
    }
}
