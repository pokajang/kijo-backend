<?php

namespace App\Services\Assistant\CompanyAnalytics;

class AssistantCompanyAnalyticsIntent
{
    public function __construct(
        public readonly ?string $domain,
        public readonly string $metric,
        public readonly array $dateRange,
        public readonly bool $supported = false,
        public readonly bool $denied = false,
        public readonly ?string $denialReason = null,
        public readonly bool $aggregate = false,
        public readonly array $aggregateDomains = [],
        public readonly ?array $catalogEntry = null,
    ) {}

    public function metricKey(): string
    {
        if (is_array($this->catalogEntry) && isset($this->catalogEntry['metric_key'])) {
            return (string) $this->catalogEntry['metric_key'];
        }

        return 'company_analytics.'.($this->domain ?: 'commercial');
    }
}
