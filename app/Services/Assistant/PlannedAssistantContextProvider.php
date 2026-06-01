<?php

namespace App\Services\Assistant;

use Illuminate\Http\Request;

interface PlannedAssistantContextProvider
{
    public function supportsPlan(AssistantRetrievalPlan $plan, string $question, string $currentRoute, Request $request): bool;

    public function retrievePlanned(AssistantRetrievalPlan $plan, string $question, string $currentRoute, Request $request): AssistantContextResult;
}
