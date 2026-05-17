<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use Illuminate\Http\JsonResponse;

class TrainingQuoteRecordFollowUpService
{
    public function __construct(private QuoteFollowUpService $followUps) {}

    public function addTrainingFollowUp(AddFollowUpRequest $request): JsonResponse
    {
        return $this->followUps->add(
            $request,
            'quotes_training',
            'training',
            'training',
            true,
            'Quote not found.',
            'Follow-up record added successfully.'
        );
    }
}
