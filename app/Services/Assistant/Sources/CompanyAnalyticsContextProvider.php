<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantDiagnosticsRecorder;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\CompanyAnalytics\AssistantCompanyAnalyticsIntentRouter;
use App\Services\Assistant\CompanyAnalytics\CompanyAnalyticsAggregator;
use App\Services\Assistant\CompanyAnalytics\CompanyAnalyticsAnswerFormatter;
use App\Services\Assistant\CompanyAnalytics\CompanyAnalyticsResult;
use Illuminate\Http\Request;

class CompanyAnalyticsContextProvider extends ModuleContextProvider
{
    public function __construct(
        AssistantText $text,
        private readonly AssistantCompanyAnalyticsIntentRouter $intentRouter,
        private readonly CompanyAnalyticsAggregator $aggregator,
        private readonly CompanyAnalyticsAnswerFormatter $formatter,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'company_analytics';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return $this->intentRouter->resolve($question, $currentRoute)->supported;
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $intent = $this->intentRouter->resolve($question, $currentRoute);

        if ($intent->denied) {
            AssistantDiagnosticsRecorder::recordDenied($this->key(), 'company_analytics', (string) $intent->denialReason, [
                'metric_key' => $intent->metricKey(),
                'scope_attempted' => 'restricted_people_analytics',
            ]);

            return $this->directNoSource(
                'I can answer company-wide commercial analytics, but HR/personnel analytics such as KPI comparisons, salary, leave, attendance, appraisal, and personal staff records are restricted in this phase.',
                (string) $intent->denialReason,
            );
        }

        $results = $this->aggregator->analyze($intent, $question);
        if ($results === []) {
            return $this->directNoSource('I do not have a safe company commercial analyzer for that question yet.', 'unsupported_company_analytics_metric');
        }

        $sources = [];
        foreach ($results as $result) {
            $source = $this->analyticsSource($result);
            if ($source !== null) {
                $sources[] = $source;
            }
        }

        $answer = $this->formatter->format(
            $results,
            $intent,
            array_values(array_filter(array_map(static fn (array $source): string => (string) ($source['slug'] ?? ''), $sources))),
            $question,
        );

        return new AssistantContextResult(
            $sources,
            'live',
            $sources[0]['freshness_label'] ?? null,
            [$this->key()],
            $answer['context_quality'] ?? 'complete',
            $answer['missing_fields'] ?? [],
            [
                'direct_answer' => $answer,
                'provider_key' => $this->key(),
                'supported_intent' => 'company_analytics',
                'company_analytics_metric_key' => $intent->metricKey(),
            ],
        );
    }

    public function auditMetadata(): array
    {
        return [
            'provider_key' => $this->key(),
            'supported_routes' => ['/dashboard/sales', '/crm/quotes', '/crm/inquiries', '/client', '/project/manage', '/commercial/invoice', '/commercial/debtors', '/vendor/payments'],
            'exact_ref_support' => false,
            'detail_route_support' => false,
            'list_support' => true,
            'sanitizer_coverage' => 'covered',
            'source_status_metadata' => 'not-applicable',
            'permission_scope' => 'company-commercial-all-authenticated-staff',
            'smoke_sample' => 'who has the most sales this year',
            'tests_present' => 'covered',
            'classification' => 'summary-only',
        ];
    }

    private function analyticsSource(CompanyAnalyticsResult $result): ?array
    {
        $payload = $result->toPayload();
        $slug = 'company-analytics:'.$result->metricKey.':'.substr(sha1(json_encode([$payload, $result->dateRange])), 0, 12);

        return $this->source(
            $slug,
            'company_analytics',
            $result->title,
            $result->route,
            ['analytics' => $payload],
            920,
            'Company Analytics',
            7000,
            [
                'supported_intent' => 'company_analytics',
                'intent_tags' => ['company_analytics', $result->metricKey],
                'context_quality' => $result->missingFields === [] ? 'complete' : 'partial',
                'permission_scope' => 'company_commercial',
            ],
        );
    }

    private function directNoSource(string $message, string $reason): AssistantContextResult
    {
        return new AssistantContextResult([], 'static', null, [$this->key()], 'insufficient', ['source'], [
            'direct_answer' => [
                'answer_markdown' => $message,
                'confidence' => 'low',
                'source_slugs' => [],
                'suggested_queries' => [],
                'freshness_label' => null,
                'answer_mode' => 'static',
                'route_refs' => [],
                'context_quality' => 'insufficient',
                'provider_key' => $this->key(),
                'supported_intent' => 'company_analytics_denied',
                'resolved_entity_ids' => [],
                'missing_fields' => ['source'],
                'denied_reason' => $reason,
                'display_blocks' => [],
            ],
        ]);
    }
}
