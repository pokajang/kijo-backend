<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantRecordRouteParser;
use App\Services\Assistant\AssistantRetrievalPlan;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\ProposalTemplateDetailContextBuilder;
use App\Services\Assistant\ProposalTemplateMatcher;
use App\Services\Assistant\PlannedAssistantContextProvider;
use Illuminate\Http\Request;

class ProposalTemplateContextProvider extends ModuleContextProvider implements PlannedAssistantContextProvider
{
    public function __construct(
        AssistantText $text,
        private readonly AssistantRecordRouteParser $routes,
        private readonly ProposalTemplateDetailContextBuilder $details,
        private readonly ProposalTemplateMatcher $matcher,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'proposal_template';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return $this->routes->proposalRoute($currentRoute) !== null
            || str_contains(strtolower($currentRoute), '/templates/proposals')
            || $this->hasToken($question, ['proposal', 'proposals', 'template', 'training', 'manpower', 'special', 'ih', 'hygiene'])
            || $this->hasServiceLookupIntent($question);
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        return $this->retrieveForQuestion($question, $currentRoute);
    }

    public function supportsPlan(AssistantRetrievalPlan $plan, string $question, string $currentRoute, Request $request): bool
    {
        return $plan->hasDomain($this->key());
    }

    public function retrievePlanned(AssistantRetrievalPlan $plan, string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        return $this->retrieveForQuestion($plan->expandedQuestion($question), $currentRoute);
    }

    private function retrieveForQuestion(string $question, string $currentRoute): AssistantContextResult
    {
        $routeMatch = $this->routes->proposalRoute($currentRoute);
        if ($routeMatch) {
            return $this->resultFromDetail($routeMatch['type'], $routeMatch['id']);
        }

        $rows = $this->details->candidateRows();
        if ($rows === []) {
            return AssistantContextResult::empty($this->key());
        }

        $resolved = $this->matcher->resolve($question, $rows);

        if ($resolved['status'] === 'ambiguous') {
            return $this->resultFromSource($this->ambiguousSource($resolved['matches']));
        }

        if ($resolved['status'] === 'resolved') {
            $row = (array) $resolved['row'];
            return $this->resultFromDetail((string) $row['_assistant_type'], (int) $row['_assistant_id'], 720);
        }

        $ranked = $this->matcher->rankedMatches($question, $rows);
        $matches = array_column(array_slice($ranked, 0, 8), 'row');
        if (
            ! $this->hasProposalListIntent($question)
            && ! str_contains(strtolower($currentRoute), '/templates/proposals')
        ) {
            return AssistantContextResult::empty($this->key());
        }

        return $this->resultFromSource($this->listSource($matches ?: array_slice($rows, 0, 8)));
    }

    private function resultFromDetail(string $type, int $id, int $score = 430): AssistantContextResult
    {
        $detail = $this->details->detail($type, $id);
        if (! $detail) {
            return AssistantContextResult::empty($this->key());
        }
        $isDeleted = array_key_exists('isDeleted', $detail) ? (bool) $detail['isDeleted'] : null;
        $status = (string) ($detail['status'] ?? '');

        return $this->resultFromSource($this->source(
            "proposal-template:{$type}:{$id}",
            'proposal_template',
            $this->details->titleFor($type, $detail, $id),
            $this->details->routeFor($type, $id),
            ['proposal_template' => $detail],
            $score,
            'Proposal Templates',
            6000,
            [
                'supported_intent' => 'service_explanation',
                'intent_tags' => ['service_explanation', 'quote_creation_support', 'proposal_template', 'record_detail'],
                'source_status' => $status !== '' ? $status : null,
                'source_is_deleted' => $isDeleted,
                'source_freshness_label' => $isDeleted ? 'Deleted template' : ($status !== '' ? ucfirst($status) : null),
            ],
        ));
    }

    private function listSource(array $rows): ?array
    {
        $matches = array_values(array_map(fn (array $row): array => [
            'id' => $row['_assistant_id'] ?? null,
            'type' => $row['_assistant_type'] ?? null,
            'title' => $row['_assistant_title'] ?? null,
            'code' => $row['_assistant_code'] ?? null,
            'status' => $row['_assistant_status'] ?? null,
            'is_deleted' => $row['_assistant_is_deleted'] ?? null,
            'proposal_language' => $row['_assistant_language'] ?? null,
            'related_route' => isset($row['_assistant_type'], $row['_assistant_id'])
                ? $this->details->routeFor((string) $row['_assistant_type'], (int) $row['_assistant_id'])
                : null,
        ], $rows));

        return $this->source(
            'proposal-template:list:'.substr(sha1(json_encode($matches)), 0, 12),
            'proposal_template',
            'Proposal template matches',
            '/templates/proposals',
            [
                'note' => 'Multiple proposal templates may be relevant. Ask with the exact title, code, or open a proposal detail page for full content.',
                'matches' => $matches,
            ],
            320,
            'Proposal Templates',
            2500,
            [
                'supported_intent' => 'list_search',
                'intent_tags' => ['list_search', 'proposal_template'],
                'source_status' => 'multiple',
            ],
        );
    }

    private function ambiguousSource(array $rows): ?array
    {
        return $this->source(
            'proposal-template:ambiguous:'.substr(sha1(json_encode($rows)), 0, 12),
            'live_entity',
            'Ambiguous proposal template matches',
            '/templates/proposals',
            [
                'note' => 'The question matched multiple proposal templates. Ask again with the exact title, code, or ID.',
                'matches' => array_slice(array_map(fn (array $row): array => [
                    'id' => $row['_assistant_id'] ?? null,
                    'type' => $row['_assistant_type'] ?? null,
                    'title' => $row['_assistant_title'] ?? null,
                    'code' => $row['_assistant_code'] ?? null,
                    'status' => $row['_assistant_status'] ?? null,
                    'is_deleted' => $row['_assistant_is_deleted'] ?? null,
                    'proposal_language' => $row['_assistant_language'] ?? null,
                    'related_route' => isset($row['_assistant_type'], $row['_assistant_id'])
                        ? $this->details->routeFor((string) $row['_assistant_type'], (int) $row['_assistant_id'])
                        : null,
                ], $rows), 0, 5),
            ],
            360,
            'Proposal Templates',
            2500,
            [
                'supported_intent' => 'clarification_needed',
                'intent_tags' => ['clarification_needed', 'proposal_template'],
                'source_status' => 'ambiguous',
            ],
        );
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

    private function hasServiceLookupIntent(string $question): bool
    {
        if (preg_match('/\b(explain|tell\s+me\s+about|describe|scope|service|training|course|assessment|monitoring|inspection|supply|manpower)\b/i', $question)) {
            return true;
        }

        return (bool) preg_match('/\b[a-z0-9]{2,}(?:-[a-z0-9]{2,}){1,}\b/i', $question);
    }

    private function hasProposalListIntent(string $question): bool
    {
        return (bool) preg_match('/\b(all|available|list|show|find|search|which|active|inactive|open|current|recent)\b/i', $question)
            && $this->hasToken($question, ['proposal', 'proposals', 'template', 'templates', 'service', 'services']);
    }
}
