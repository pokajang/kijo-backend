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

class QuoteRecordService
{
    private function equipmentQuoteRecordService(): EquipmentQuoteRecordService
    {
        return app(EquipmentQuoteRecordService::class);
    }

    private function ihQuoteRecordService(): IhQuoteRecordService
    {
        return app(IhQuoteRecordService::class);
    }

    private function manpowerQuoteRecordService(): ManpowerQuoteRecordService
    {
        return app(ManpowerQuoteRecordService::class);
    }

    public function listEquipment(Request $request): JsonResponse
    {
        return $this->equipmentQuoteRecordService()->listEquipment($request);
    }

    public function addEquipmentFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordService()->addEquipmentFollowUp($request);
    }

    public function awardEquipment(AwardQuoteRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordService()->awardEquipment($request);
    }

    public function failEquipment(FailQuoteRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordService()->failEquipment($request);
    }

    public function reAwardEquipment(AwardQuoteRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordService()->reAwardEquipment($request);
    }

    public function unAwardEquipment(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordService()->unAwardEquipment($request);
    }

    public function destroyEquipment(Request $request, int $id = 0): JsonResponse
    {
        return $this->equipmentQuoteRecordService()->destroyEquipment($request, $id);
    }

    public function relatedDocsEquipment(Request $request): JsonResponse
    {
        return $this->equipmentQuoteRecordService()->relatedDocsEquipment($request);
    }

    public function pdfEquipment(Request $request, int $id = 0): mixed
    {
        return $this->equipmentQuoteRecordService()->pdfEquipment($request, $id);
    }

    public function syncClientEquipment(SyncClientRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordService()->syncClientEquipment($request);
    }

    public function listIh(Request $request): JsonResponse
    {
        return $this->ihQuoteRecordService()->listIh($request);
    }

    public function addIhFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordService()->addIhFollowUp($request);
    }

    public function awardIh(AwardQuoteRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordService()->awardIh($request);
    }

    public function failIh(FailQuoteRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordService()->failIh($request);
    }

    public function reAwardIh(AwardQuoteRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordService()->reAwardIh($request);
    }

    public function unAwardIh(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordService()->unAwardIh($request);
    }

    public function destroyIh(Request $request, int $id = 0): JsonResponse
    {
        return $this->ihQuoteRecordService()->destroyIh($request, $id);
    }

    public function relatedDocsIh(Request $request): JsonResponse
    {
        return $this->ihQuoteRecordService()->relatedDocsIh($request);
    }

    public function pdfIh(Request $request, int $id = 0)
    {
        return $this->ihQuoteRecordService()->pdfIh($request, $id);
    }

    public function syncClientIh(SyncClientRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordService()->syncClientIh($request);
    }

    public function listManpower(Request $request): JsonResponse
    {
        return $this->manpowerQuoteRecordService()->listManpower($request);
    }

    public function addManpowerFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordService()->addManpowerFollowUp($request);
    }

    public function awardManpower(AwardQuoteRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordService()->awardManpower($request);
    }

    public function failManpower(FailQuoteRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordService()->failManpower($request);
    }

    public function reAwardManpower(AwardQuoteRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordService()->reAwardManpower($request);
    }

    public function unAwardManpower(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordService()->unAwardManpower($request);
    }

    public function destroyManpower(Request $request, int $id = 0): JsonResponse
    {
        return $this->manpowerQuoteRecordService()->destroyManpower($request, $id);
    }

    public function relatedDocsManpower(Request $request): JsonResponse
    {
        return $this->manpowerQuoteRecordService()->relatedDocsManpower($request);
    }

    public function pdfManpower(Request $request, int $id = 0): mixed
    {
        return $this->manpowerQuoteRecordService()->pdfManpower($request, $id);
    }

    public function syncClientManpower(SyncClientRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordService()->syncClientManpower($request);
    }
}
