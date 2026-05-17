<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use App\Http\Requests\QuoteRecord\AwardQuoteRequest;
use App\Http\Requests\QuoteRecord\FailQuoteRequest;
use App\Http\Requests\QuoteRecord\SyncClientRequest;
use App\Http\Requests\QuoteRecord\UnAwardQuoteRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\QuoteRecords\QuoteRecordService;

class QuoteRecordController extends Controller
{
    private function quoteRecordService(): QuoteRecordService
    {
        return app(QuoteRecordService::class);
    }

    public function listEquipment(Request $request): JsonResponse
    {
        return $this->quoteRecordService()->listEquipment($request);
    }

    public function addEquipmentFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->addEquipmentFollowUp($request);
    }

    public function awardEquipment(AwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->awardEquipment($request);
    }

    public function failEquipment(FailQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->failEquipment($request);
    }

    public function reAwardEquipment(AwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->reAwardEquipment($request);
    }

    public function unAwardEquipment(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->unAwardEquipment($request);
    }

    public function destroyEquipment(Request $request, int $id = 0): JsonResponse
    {
        return $this->quoteRecordService()->destroyEquipment($request, $id);
    }

    public function relatedDocsEquipment(Request $request): JsonResponse
    {
        return $this->quoteRecordService()->relatedDocsEquipment($request);
    }

    public function pdfEquipment(Request $request, int $id = 0): mixed
    {
        return $this->quoteRecordService()->pdfEquipment($request, $id);
    }

    public function syncClientEquipment(SyncClientRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->syncClientEquipment($request);
    }

    public function listIh(Request $request): JsonResponse
    {
        return $this->quoteRecordService()->listIh($request);
    }

    public function addIhFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->addIhFollowUp($request);
    }

    public function awardIh(AwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->awardIh($request);
    }

    public function failIh(FailQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->failIh($request);
    }

    public function reAwardIh(AwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->reAwardIh($request);
    }

    public function unAwardIh(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->unAwardIh($request);
    }

    public function destroyIh(Request $request, int $id = 0): JsonResponse
    {
        return $this->quoteRecordService()->destroyIh($request, $id);
    }

    public function relatedDocsIh(Request $request): JsonResponse
    {
        return $this->quoteRecordService()->relatedDocsIh($request);
    }

    public function pdfIh(Request $request, int $id = 0)
    {
        return $this->quoteRecordService()->pdfIh($request, $id);
    }

    public function syncClientIh(SyncClientRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->syncClientIh($request);
    }

    public function listManpower(Request $request): JsonResponse
    {
        return $this->quoteRecordService()->listManpower($request);
    }

    public function addManpowerFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->addManpowerFollowUp($request);
    }

    public function awardManpower(AwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->awardManpower($request);
    }

    public function failManpower(FailQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->failManpower($request);
    }

    public function reAwardManpower(AwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->reAwardManpower($request);
    }

    public function unAwardManpower(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->unAwardManpower($request);
    }

    public function destroyManpower(Request $request, int $id = 0): JsonResponse
    {
        return $this->quoteRecordService()->destroyManpower($request, $id);
    }

    public function relatedDocsManpower(Request $request): JsonResponse
    {
        return $this->quoteRecordService()->relatedDocsManpower($request);
    }

    public function pdfManpower(Request $request, int $id = 0): mixed
    {
        return $this->quoteRecordService()->pdfManpower($request, $id);
    }

    public function syncClientManpower(SyncClientRequest $request): JsonResponse
    {
        return $this->quoteRecordService()->syncClientManpower($request);
    }
}
