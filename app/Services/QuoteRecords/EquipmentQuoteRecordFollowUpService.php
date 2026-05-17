<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use Illuminate\Http\JsonResponse;

class EquipmentQuoteRecordFollowUpService
{
    public function __construct(private QuoteFollowUpService $followUps) {}

    public function addEquipmentFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->followUps->add($request, 'quotes_equipment', 'equipment', 'Equipment');
    }
}
