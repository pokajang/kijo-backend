<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use Illuminate\Http\JsonResponse;

class ManpowerQuoteRecordFollowUpService
{
    public function __construct(private QuoteFollowUpService $followUps) {}

    public function addManpowerFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->followUps->add($request, 'quotes_manpower', 'manpower', 'Manpower');
    }
}
