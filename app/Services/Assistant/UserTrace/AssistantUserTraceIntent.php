<?php

namespace App\Services\Assistant\UserTrace;

class AssistantUserTraceIntent
{
    public function __construct(
        public readonly string $subject,
        public readonly ?string $domain,
        public readonly string $metric,
        public readonly array $dateRange,
        public readonly bool $supported,
        public readonly bool $denied = false,
        public readonly ?string $denialReason = null,
        public readonly bool $aggregate = false,
        public readonly array $aggregateDomains = [],
        public readonly ?array $catalogEntry = null,
    ) {}

    public function metricKey(): string
    {
        return (string) ($this->catalogEntry['metric_key'] ?? ($this->domain ? 'user_trace.'.$this->domain : 'user_trace'));
    }
}
