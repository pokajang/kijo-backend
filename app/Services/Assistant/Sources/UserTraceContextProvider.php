<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantDiagnosticsRecorder;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\UserTrace\AssistantSelfDataIntentRouter;
use App\Services\Assistant\UserTrace\AssistantTraceAnswerFormatter;
use App\Services\Assistant\UserTrace\AssistantUserIdentityResolver;
use App\Services\Assistant\UserTrace\AssistantUserTraceIdentity;
use App\Services\Assistant\UserTrace\AssistantUserTraceResult;
use App\Services\Assistant\UserTrace\UserTraceAggregator;
use Illuminate\Http\Request;

class UserTraceContextProvider extends ModuleContextProvider
{
    public function __construct(
        AssistantText $text,
        private readonly AssistantUserIdentityResolver $identityResolver,
        private readonly AssistantSelfDataIntentRouter $intentRouter,
        private readonly UserTraceAggregator $aggregator,
        private readonly AssistantTraceAnswerFormatter $formatter,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'user_trace';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return $this->intentRouter->resolve($question, $currentRoute)->supported;
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $identity = $this->identityResolver->resolve($request);
        if ($identity->staffId <= 0) {
            return $this->directNoSource('Please sign in before asking about your personal Kijo trace.', 'not_authenticated');
        }

        $intent = $this->intentRouter->resolve($question, $currentRoute);

        if ($intent->denied) {
            AssistantDiagnosticsRecorder::recordDenied($this->key(), 'user_trace', 'team_scope_not_enabled', [
                'metric_key' => $intent->metricKey(),
                'scope_attempted' => $intent->subject,
            ]);

            return $this->directNoSource(
                'I can only answer self-scoped trace questions in this phase. Ask about your own records, or use the module reports you are authorized to access.',
                'team_scope_not_enabled',
            );
        }

        if (! $intent->supported || (empty($intent->catalogEntry['analyzer'] ?? null) && ! $intent->aggregate)) {
            return $this->directNoSource(
                (string) ($intent->catalogEntry['unsupported_reason'] ?? 'I do not have a safe self-data analyzer for that question yet.'),
                'unsupported_trace_metric',
            );
        }

        $results = $this->aggregator->analyze($intent, $question, $identity);
        if ($results === []) {
            return $this->directNoSource('I could not find a safe self-data analyzer for that question yet.', 'unsupported_trace_metric');
        }

        $sources = [];
        foreach ($results as $result) {
            AssistantDiagnosticsRecorder::recordTrace(
                (string) ($result->diagnostics['analyzer'] ?? $result->metricKey),
                'self',
                $result->dateRange,
                $result->missingFields,
            );
            $sources[] = $this->traceSource($result, $identity);
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
                'supported_intent' => 'user_trace',
                'trace_metric_key' => $intent->metricKey(),
            ],
        );
    }

    public function auditMetadata(): array
    {
        return [
            'provider_key' => $this->key(),
            'supported_routes' => ['/my/profile', '/crm/quotes', '/my/leaves', '/staff/appraise', '/task-manager'],
            'exact_ref_support' => false,
            'detail_route_support' => false,
            'list_support' => true,
            'sanitizer_coverage' => 'covered',
            'source_status_metadata' => 'not-applicable',
            'permission_scope' => 'self-only',
            'smoke_sample' => 'how many quotations have I issued',
            'tests_present' => 'covered',
            'classification' => 'summary-only',
        ];
    }

    private function traceSource(AssistantUserTraceResult $result, AssistantUserTraceIdentity $identity): array
    {
        $payload = $result->toPayload() + [
            'identity' => array_filter([
                'staff_id' => $identity->staffId,
                'name_code' => $identity->nameCode,
                'full_name' => $identity->fullName,
                'department' => $identity->department,
                'position' => $identity->position,
            ], static fn ($value): bool => $value !== null && $value !== ''),
        ];
        $slug = 'user-trace:'.$result->metricKey.':'.substr(sha1(json_encode([$payload, $result->dateRange])), 0, 12);

        return $this->source(
            $slug,
            'user_trace',
            $result->title,
            $result->route,
            ['trace' => $payload],
            900,
            'User Trace',
            6000,
            [
                'supported_intent' => 'user_trace',
                'intent_tags' => ['user_trace', $result->metricKey],
                'context_quality' => $result->missingFields === [] ? 'complete' : 'partial',
                'permission_scope' => 'self',
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
                'supported_intent' => 'user_trace_denied',
                'resolved_entity_ids' => [],
                'missing_fields' => ['source'],
                'denied_reason' => $reason,
                'display_blocks' => [],
            ],
        ]);
    }
}
