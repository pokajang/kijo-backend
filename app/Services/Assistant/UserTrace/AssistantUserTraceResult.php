<?php

namespace App\Services\Assistant\UserTrace;

class AssistantUserTraceResult
{
    public function __construct(
        public readonly string $metricKey,
        public readonly string $title,
        public readonly string $definition,
        public readonly array $dateRange,
        public readonly array $totals = [],
        public readonly array $breakdowns = [],
        public readonly array $sampleRecords = [],
        public readonly array $nextDrilldowns = [],
        public readonly array $missingFields = [],
        public readonly string $confidence = 'high',
        public readonly string $summary = '',
        public readonly string $route = '/my/profile',
        public readonly array $diagnostics = [],
    ) {}

    public function toPayload(): array
    {
        return [
            'metric_key' => $this->metricKey,
            'scope' => 'self',
            'date_range' => $this->dateRange,
            'definition' => $this->definition,
            'totals' => $this->totals,
            'breakdowns' => $this->breakdowns,
            'sample_records' => $this->sampleRecords,
            'next_drilldowns' => $this->nextDrilldowns,
            'missing_fields' => $this->missingFields,
            'confidence' => $this->confidence,
            'summary' => $this->summary,
        ];
    }
}
