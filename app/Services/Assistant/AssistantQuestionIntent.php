<?php

namespace App\Services\Assistant;

class AssistantQuestionIntent
{
    public const QUOTE_CREATION = 'quote_creation';
    public const QUOTE_NEGOTIATION = 'quote_negotiation';
    public const RECORD_STATUS = 'record_status';
    public const RECORD_DETAIL = 'record_detail';
    public const POLICY_QUESTION = 'policy_question';
    public const HOW_TO = 'how_to';
    public const SERVICE_EXPLANATION = 'service_explanation';
    public const METRIC_QUESTION = 'metric_question';
    public const LIST_SEARCH = 'list_search';
    public const ACTION_REQUEST = 'action_request';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $primaryIntent,
        public readonly array $targetTypes = [],
        public readonly array $positiveTerms = [],
        public readonly array $negativeTerms = [],
        public readonly bool $hasExactRef = false,
        public readonly bool $hasDetailRoute = false,
        public readonly string $confidence = 'low',
    ) {}

    public function is(string $intent): bool
    {
        return $this->primaryIntent === $intent;
    }

    public function targets(string $type): bool
    {
        return in_array($type, $this->targetTypes, true);
    }
}
