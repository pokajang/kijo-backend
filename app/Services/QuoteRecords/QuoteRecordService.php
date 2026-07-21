<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use App\Http\Requests\QuoteRecord\AwardQuoteRequest;
use App\Http\Requests\QuoteRecord\FailQuoteRequest;
use App\Http\Requests\QuoteRecord\SyncClientRequest;
use App\Http\Requests\QuoteRecord\UnAwardQuoteRequest;
use App\Services\QuoteApprovals\QuoteApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        if ($denial = $this->approvalDenial($request, 'equipment')) {
            return $denial;
        }

        return $this->equipmentQuoteRecordService()->awardEquipment($request);
    }

    public function failEquipment(FailQuoteRequest $request): JsonResponse
    {
        return $this->cancelApprovalAfter(
            $this->equipmentQuoteRecordService()->failEquipment($request),
            $request,
            'equipment',
            'Quotation marked as Failed.',
        );
    }

    public function reAwardEquipment(AwardQuoteRequest $request): JsonResponse
    {
        if ($denial = $this->approvalDenial($request, 'equipment')) {
            return $denial;
        }

        return $this->equipmentQuoteRecordService()->reAwardEquipment($request);
    }

    public function unAwardEquipment(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->equipmentQuoteRecordService()->unAwardEquipment($request);
    }

    public function destroyEquipment(Request $request, int $id = 0): JsonResponse
    {
        return $this->cancelApprovalAfter(
            $this->equipmentQuoteRecordService()->destroyEquipment($request, $id),
            $request,
            'equipment',
            'Quotation deleted.',
            $id,
        );
    }

    public function relatedDocsEquipment(Request $request): JsonResponse
    {
        return $this->equipmentQuoteRecordService()->relatedDocsEquipment($request);
    }

    public function pdfEquipment(Request $request, int $id = 0): mixed
    {
        if ($denial = $this->approvalDenial($request, 'equipment', $id)) {
            return $denial;
        }

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
        if ($denial = $this->approvalDenial($request, 'ih')) {
            return $denial;
        }

        return $this->ihQuoteRecordService()->awardIh($request);
    }

    public function failIh(FailQuoteRequest $request): JsonResponse
    {
        return $this->cancelApprovalAfter(
            $this->ihQuoteRecordService()->failIh($request),
            $request,
            'ih',
            'Quotation marked as Failed.',
        );
    }

    public function reAwardIh(AwardQuoteRequest $request): JsonResponse
    {
        if ($denial = $this->approvalDenial($request, 'ih')) {
            return $denial;
        }

        return $this->ihQuoteRecordService()->reAwardIh($request);
    }

    public function unAwardIh(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->ihQuoteRecordService()->unAwardIh($request);
    }

    public function destroyIh(Request $request, int $id = 0): JsonResponse
    {
        return $this->cancelApprovalAfter(
            $this->ihQuoteRecordService()->destroyIh($request, $id),
            $request,
            'ih',
            'Quotation deleted.',
            $id,
        );
    }

    public function relatedDocsIh(Request $request): JsonResponse
    {
        return $this->ihQuoteRecordService()->relatedDocsIh($request);
    }

    public function pdfIh(Request $request, int $id = 0)
    {
        if ($denial = $this->approvalDenial($request, 'ih', $id)) {
            return $denial;
        }

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
        if ($denial = $this->approvalDenial($request, 'manpower')) {
            return $denial;
        }

        return $this->manpowerQuoteRecordService()->awardManpower($request);
    }

    public function failManpower(FailQuoteRequest $request): JsonResponse
    {
        return $this->cancelApprovalAfter(
            $this->manpowerQuoteRecordService()->failManpower($request),
            $request,
            'manpower',
            'Quotation marked as Failed.',
        );
    }

    public function reAwardManpower(AwardQuoteRequest $request): JsonResponse
    {
        if ($denial = $this->approvalDenial($request, 'manpower')) {
            return $denial;
        }

        return $this->manpowerQuoteRecordService()->reAwardManpower($request);
    }

    public function unAwardManpower(UnAwardQuoteRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordService()->unAwardManpower($request);
    }

    public function destroyManpower(Request $request, int $id = 0): JsonResponse
    {
        return $this->cancelApprovalAfter(
            $this->manpowerQuoteRecordService()->destroyManpower($request, $id),
            $request,
            'manpower',
            'Quotation deleted.',
            $id,
        );
    }

    public function relatedDocsManpower(Request $request): JsonResponse
    {
        return $this->manpowerQuoteRecordService()->relatedDocsManpower($request);
    }

    public function pdfManpower(Request $request, int $id = 0): mixed
    {
        if ($denial = $this->approvalDenial($request, 'manpower', $id)) {
            return $denial;
        }

        return $this->manpowerQuoteRecordService()->pdfManpower($request, $id);
    }

    public function syncClientManpower(SyncClientRequest $request): JsonResponse
    {
        return $this->manpowerQuoteRecordService()->syncClientManpower($request);
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
