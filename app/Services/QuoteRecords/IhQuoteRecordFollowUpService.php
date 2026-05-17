<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use Illuminate\Http\JsonResponse;

class IhQuoteRecordFollowUpService
{
    public function __construct(private QuoteFollowUpService $followUps) {}

    public function addIhFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->followUps->add($request, 'quotes_ih', 'ih', 'IH');
    }
}
