<?php

namespace App\Services\Assistant\UserTrace;

class UserTraceAggregator
{
    public function __construct(
        private readonly UserQuoteTraceAnalyzer $quotes,
        private readonly UserLeaveTraceAnalyzer $leaves,
        private readonly UserEmploymentTraceAnalyzer $employment,
        private readonly UserKpiTraceAnalyzer $kpi,
        private readonly UserTaskTraceAnalyzer $tasks,
    ) {}

    /**
     * @return AssistantUserTraceResult[]
     */
    public function analyze(AssistantUserTraceIntent $intent, string $question, AssistantUserTraceIdentity $identity): array
    {
        if ($intent->aggregate) {
            $results = [];
            foreach ($intent->aggregateDomains as $domain) {
                $result = $this->analyzeDomain($domain, $question, $identity, $intent->dateRange);
                if ($result !== null) {
                    $results[] = $result;
                }
            }

            return $results;
        }

        $result = $this->analyzeDomain((string) $intent->domain, $question, $identity, $intent->dateRange);

        return $result ? [$result] : [];
    }

    private function analyzeDomain(string $domain, string $question, AssistantUserTraceIdentity $identity, array $dateRange): ?AssistantUserTraceResult
    {
        return match ($domain) {
            'quote' => $this->quotes->analyze($question, $identity, $dateRange),
            'leave' => $this->leaves->analyze($question, $identity, $dateRange),
            'employment' => $this->employment->analyze($question, $identity, [
                'label' => 'all time',
                'start' => null,
                'end' => null,
                'is_all_time' => true,
            ]),
            'kpi' => $this->kpi->analyze($question, $identity, $dateRange),
            'task' => $this->tasks->analyze($question, $identity, $dateRange),
            default => null,
        };
    }
}
