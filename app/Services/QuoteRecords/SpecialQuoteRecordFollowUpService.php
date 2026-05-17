<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use Illuminate\Http\JsonResponse;

class SpecialQuoteRecordFollowUpService
{
    public function __construct(private QuoteFollowUpService $followUps) {}

    public function addSpecialFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->followUps->add(
            $request,
            'quotes_special',
            'special',
            'special',
            true,
            'Quote not found.',
            'Follow-up record added successfully.'
        );
    }
}
