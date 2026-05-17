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

class EquipmentQuoteRecordService
{
    private function equipmentQuoteRecordListingService(): EquipmentQuoteRecordListingService
    {
        return app(EquipmentQuoteRecordListingService::class);
    }

    private function equipmentQuoteRecordFollowUpService(): EquipmentQuoteRecordFollowUpService
    {
        return app(EquipmentQuoteRecordFollowUpService::class);
    }

    private function equipmentQuoteRecordAwardWorkflowService(): EquipmentQuoteRecordAwardWorkflowService
    {
        return app(EquipmentQuoteRecordAwardWorkflowService::class);
    }

    private function equipmentQuoteRecordPdfService(): EquipmentQuoteRecordPdfService
    {
        return app(EquipmentQuoteRecordPdfService::class);
    }

    private function equipmentQuoteRecordClientSyncService(): EquipmentQuoteRecordClientSyncService
    {
        return app(EquipmentQuoteRecordClientSyncService::class);
    }

    public function listEquipment(Request $request): JsonResponse
    {
        return $this->equipmentQuoteRecordListingService()->listEquipment($request);
    }

    public function addEquipmentFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordFollowUpService()->addEquipmentFollowUp($request);
    }

    public function awardEquipment(AwardQuoteRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordAwardWorkflowService()->awardEquipment($request);
    }

    public function failEquipment(FailQuoteRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordAwardWorkflowService()->failEquipment($request);
    }

    public function reAwardEquipment(AwardQuoteRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordAwardWorkflowService()->reAwardEquipment($request);
    }

    public function unAwardEquipment(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordAwardWorkflowService()->unAwardEquipment($request);
    }

    public function destroyEquipment(Request $request, int $id = 0): JsonResponse
    {
        return $this->equipmentQuoteRecordListingService()->destroyEquipment($request, $id);
    }

    public function relatedDocsEquipment(Request $request): JsonResponse
    {
        return $this->equipmentQuoteRecordListingService()->relatedDocsEquipment($request);
    }

    public function pdfEquipment(Request $request, int $id = 0): mixed
    {
        return $this->equipmentQuoteRecordPdfService()->pdfEquipment($request, $id);
    }

    public function syncClientEquipment(SyncClientRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordClientSyncService()->syncClientEquipment($request);
    }
}
