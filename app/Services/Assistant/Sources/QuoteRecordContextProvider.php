<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\AssistantRecordRouteParser;
use App\Services\Assistant\AssistantRetrievalPlan;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\ModuleEntityResolver;
use App\Services\Assistant\PlannedAssistantContextProvider;
use App\Services\Assistant\QuoteRecordDetailContextBuilder;
use App\Services\Quotes\Records\QuoteRecordListingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class QuoteRecordContextProvider extends ModuleContextProvider implements PlannedAssistantContextProvider
{
    private const SERVICES = ['equipment', 'manpower', 'ih', 'training', 'special'];

    public function __construct(
        AssistantText $text,
        private readonly QuoteRecordListingService $quotes,
        private readonly ModuleEntityResolver $resolver,
        private readonly AssistantContextSanitizer $sanitizer,
        private readonly AssistantRecordRouteParser $routes,
        private readonly QuoteRecordDetailContextBuilder $details,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'quote_record';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return (
            str_contains(strtolower($currentRoute), '/crm/quotes')
            || $this->hasToken($question, [
                'quote', 'quotes', 'quotation', 'quotations', 'followup', 'follow',
                'award', 'awarded', 'failed', 'pricing',
            ])
        ) && collect(self::SERVICES)->contains(fn (string $service): bool => Schema::hasTable('quotes_'.$service));
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        return $this->retrieveForQuestion($question, $currentRoute, $request);
    }

    public function supportsPlan(AssistantRetrievalPlan $plan, string $question, string $currentRoute, Request $request): bool
    {
        return $plan->hasDomain($this->key())
            && collect(self::SERVICES)->contains(fn (string $service): bool => Schema::hasTable('quotes_'.$service));
    }

    public function retrievePlanned(AssistantRetrievalPlan $plan, string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        return $this->retrieveForQuestion($plan->expandedQuestion($question), $currentRoute, $request);
    }

    public function auditMetadata(): array
    {
        return [
            'provider_key' => $this->key(),
            'supported_routes' => ['/crm/quotes', '/quote-records/*'],
            'exact_ref_support' => true,
            'detail_route_support' => true,
            'list_support' => true,
            'sanitizer_coverage' => 'covered',
            'source_status_metadata' => 'covered',
            'permission_scope' => 'session controller/listing service',
            'smoke_sample' => 'explain quote QTR26-0001AZ',
            'tests_present' => 'partial',
            'classification' => 'detail-ready',
        ];
    }

    private function retrieveForQuestion(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $routeMatch = $this->routes->quoteRoute($currentRoute);
        if ($routeMatch) {
            return $this->resultFromDetail($routeMatch['service'], $routeMatch['id']);
        }

        if ($this->isQuoteCreationIntent($question) && ! $this->hasExactQuoteReference($question)) {
            return AssistantContextResult::empty($this->key());
        }

        $rows = $this->quoteRows($question, $request);
        if ($rows === []) {
            return AssistantContextResult::empty($this->key());
        }

        $resolved = $this->resolver->resolve(
            $question,
            $currentRoute,
            $rows,
            'id',
            'quote_ref_no',
            ['quote_ref_no', 'client_name', 'status', 'service_type', 'remarks'],
        );

        if ($resolved['status'] === 'ambiguous') {
            return $this->resultFromSource($this->ambiguousSource($resolved['matches']));
        }

        if ($resolved['status'] === 'resolved') {
            $row = (array) $resolved['row'];
            return $this->resultFromDetail((string) ($row['service_key'] ?? ''), (int) ($row['id'] ?? 0));
        }

        $ranked = $this->resolver->rankedMatches($question, $rows, 'quote_ref_no', [
            'quote_ref_no', 'client_name', 'status', 'service_type', 'remarks',
        ]);
        $matches = array_column(array_slice($ranked, 0, 8), 'row');
        $filtered = $this->filterByIntent($matches ?: $rows, $question);
        if ($filtered === [] && ! $this->hasListIntent($question) && ! str_contains(strtolower($currentRoute), '/crm/quotes')) {
            return AssistantContextResult::empty($this->key());
        }

        return $this->resultFromSource($this->quoteListSource($filtered ?: array_slice($rows, 0, 8)));
    }

    private function quoteRows(string $question, Request $request): array
    {
        $wantedServices = $this->wantedServices($question);
        $rows = [];

        foreach ($wantedServices as $service) {
            if (! Schema::hasTable('quotes_'.$service)) {
                continue;
            }

            $payload = $this->responseData(fn () => $this->quotes->listQuoteRecords(
                $this->clonedRequest($request, "/assistant/quote-records/{$service}"),
                $service,
            ));
            foreach (($payload['data'] ?? []) as $row) {
                $quote = (array) $row;
                $quote['service_key'] = $service;
                $quote['service_type'] = $this->serviceLabel($service);
                $quote['quote_ref_no'] = $quote['quote_ref_no'] ?? $quote['quotation_ref_no'] ?? $quote['ref_no'] ?? "{$this->serviceLabel($service)} #".($quote['id'] ?? '');
                $rows[] = $quote;
            }
        }

        return $rows;
    }

    private function wantedServices(string $question): array
    {
        $services = [];
        foreach (self::SERVICES as $service) {
            if ($this->hasToken($question, [$service]) || ($service === 'ih' && $this->hasToken($question, ['hygiene', 'industrial']))) {
                $services[] = $service;
            }
        }

        return $services ?: self::SERVICES;
    }

    private function filterByIntent(array $rows, string $question): array
    {
        if ($this->hasToken($question, ['open'])) {
            return array_values(array_filter($rows, fn (array $row): bool => strtolower((string) ($row['status'] ?? '')) === 'open'));
        }
        if ($this->hasToken($question, ['awarded', 'award'])) {
            return array_values(array_filter($rows, fn (array $row): bool => strtolower((string) ($row['status'] ?? '')) === 'awarded'));
        }
        if ($this->hasToken($question, ['failed', 'fail'])) {
            return array_values(array_filter($rows, fn (array $row): bool => strtolower((string) ($row['status'] ?? '')) === 'failed'));
        }

        return $rows;
    }

    private function quoteSource(array $quote): ?array
    {
        $id = (int) ($quote['id'] ?? 0);
        $service = (string) ($quote['service_key'] ?? '');
        if ($id <= 0 || $service === '') {
            return null;
        }

        $detail = $this->details->detail($service, $id);
        if (! $detail) {
            return null;
        }
        $status = trim((string) ($detail['quote']['status'] ?? ''));

        return $this->source(
            "quote-record:{$service}:{$id}",
            'quote_record',
            $this->details->titleFor($service, $detail, $id),
            $this->details->routeFor($service, $id),
            ['quote_detail' => $detail],
            440,
            'Quote Records',
            6000,
            [
                'supported_intent' => 'record_detail',
                'intent_tags' => ['record_detail', 'record_status', 'quote_record'],
                'source_status' => $status !== '' ? $status : null,
                'source_freshness_label' => $status !== '' ? ucfirst($status) : null,
            ],
        );
    }

    private function resultFromDetail(string $service, int $id): AssistantContextResult
    {
        if ($service === '' || $id <= 0) {
            return AssistantContextResult::empty($this->key());
        }

        return $this->resultFromSource($this->quoteSource([
            'id' => $id,
            'service_key' => $service,
        ]));
    }

    private function quoteListSource(array $quotes): ?array
    {
        $rows = $this->sanitizer->rows($quotes, [
            'id',
            'quote_ref_no',
            'service_type',
            'client_name',
            'status',
            'quote_value',
            'grand_total',
            'created_at',
        ], 8);

        return $this->source(
            'quote-record:list:'.substr(sha1(json_encode($rows)), 0, 12),
            'quote_record',
            'Quote record matches',
            '/crm/quotes',
            [
                'note' => 'Multiple quote records may be relevant. Ask with the quote reference, client name, or service type for details.',
                'quotes' => $rows,
            ],
            320,
            'Quote Records',
            2500,
            [
                'supported_intent' => 'list_search',
                'intent_tags' => ['list_search', 'quote_record'],
                'source_status' => 'multiple',
            ],
        );
    }

    private function ambiguousSource(array $matches): ?array
    {
        $rows = $this->sanitizer->rows($matches, [
            'id',
            'quote_ref_no',
            'service_type',
            'client_name',
            'status',
        ], 5);

        return $this->source(
            'quote-record:ambiguous:'.substr(sha1(json_encode($rows)), 0, 12),
            'live_entity',
            'Ambiguous quote record matches',
            '/crm/quotes',
            [
                'note' => 'The question matched multiple quote records. Ask again with the exact quote reference or service type.',
                'matches' => $rows,
            ],
            360,
            'Quote Records',
            2500,
            [
                'supported_intent' => 'clarification_needed',
                'intent_tags' => ['clarification_needed', 'quote_record'],
                'source_status' => 'ambiguous',
            ],
        );
    }

    private function serviceLabel(string $service): string
    {
        return match ($service) {
            'equipment' => 'Equipment Supply',
            'manpower' => 'Manpower Supply',
            'ih' => 'Industrial Hygiene',
            'special' => 'Special Service',
            'training' => 'Training',
            default => ucfirst($service),
        };
    }

    private function isQuoteCreationIntent(string $question): bool
    {
        return (bool) preg_match('/\b(how\s+to\s+quote|quote\s+(this|that|the|for)|prepare\s+(a\s+)?quot|create\s+(a\s+)?quot|send\s+(a\s+)?quot|price\s+(this|that|the))\b/i', $question);
    }

    private function hasExactQuoteReference(string $question): bool
    {
        return (bool) preg_match('/\bQ[A-Z0-9-]{2,}\b/i', $question);
    }

    private function resultFromSource(?array $source): AssistantContextResult
    {
        return new AssistantContextResult(
            $source ? [$source] : [],
            $source ? 'live' : 'static',
            $source ? $this->freshnessLabel() : null,
            [$this->key()],
        );
    }
}
