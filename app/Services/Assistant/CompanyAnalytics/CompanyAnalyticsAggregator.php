<?php

namespace App\Services\Assistant\CompanyAnalytics;

class CompanyAnalyticsAggregator
{
    public function __construct(private readonly CompanyCommercialAnalyticsAnalyzer $analyzer) {}

    /**
     * @return CompanyAnalyticsResult[]
     */
    public function analyze(AssistantCompanyAnalyticsIntent $intent, string $question): array
    {
        if ($intent->aggregate) {
            $results = [];
            foreach ($intent->aggregateDomains as $domain) {
                $result = $this->analyzer->analyze($domain, $question, $intent->dateRange);
                if ($result !== null) {
                    $results[] = $result;
                }
            }

            return $results;
        }

        $domain = $intent->domain;
        if ($domain === null) {
            return [];
        }

        $result = $this->analyzer->analyze($domain, $question, $intent->dateRange);

        return $result ? [$result] : [];
    }
}
