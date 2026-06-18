<?php

namespace App\Services\Assistant;

class AssistantSourceIntentRanker
{
    private const SCORES = [
        'exact_route_match' => 1000,
        'exact_reference_match' => 850,
        'service_detail_match' => 650,
        'quote_record_match' => 550,
        'policy_source_match' => 450,
        'quote_intent_tag_match' => 350,
        'list_target_type_match' => 260,
        'target_type_match' => 120,
        'title_or_code_match' => 80,
        'body_match' => 25,
        'complete_context' => 80,
        'permission_fit' => 50,
        'knowledge_under_exact_detail_penalty' => -500,
        'list_under_exact_detail_penalty' => -250,
        'ambiguity_penalty' => -250,
        'incomplete_context_penalty' => -180,
        'missing_field_penalty' => -20,
        'stale_status_penalty' => -250,
        'draft_status_penalty' => -150,
        'deleted_status_penalty' => -1200,
        'intent_conflict_penalty' => -900,
        'quote_creation_negotiation_penalty' => -900,
    ];

    public function rank(array $sources, AssistantQuestionIntent $intent, string $question, string $currentRoute): array
    {
        $hasExactLiveDetail = $this->hasExactLiveDetail($sources, $intent, $question, $currentRoute);

        return array_values(array_filter(array_map(
            fn (array $source): array => $this->rankSource($source, $intent, $question, $currentRoute, $hasExactLiveDetail),
            $sources,
        ), static fn (array $source): bool => ($source['score'] ?? 0) > 0));
    }

    private function rankSource(array $source, AssistantQuestionIntent $intent, string $question, string $currentRoute, bool $hasExactLiveDetail): array
    {
        $score = (int) ($source['score'] ?? 0);
        $source['score_explanations'] = array_values(array_filter((array) ($source['score_explanations'] ?? []), 'is_string'));
        $tags = $this->tags($source);
        $sourceType = (string) ($source['source_type'] ?? $source['type'] ?? '');

        if ($this->isCurrentRouteSource($source, $currentRoute)) {
            $score = $this->add($source, $score, 'exact_route_match');
            $source['match_reason'] = 'current_detail_route';
        }

        if ($intent->hasExactRef && $this->sourceMatchesQuestionReference($source, $question)) {
            $score = $this->add($source, $score, 'exact_reference_match');
            $source['match_reason'] = $source['match_reason'] ?? 'exact_record_reference';
        }

        if ($intent->is(AssistantQuestionIntent::SERVICE_EXPLANATION) && $sourceType === 'proposal_template' && ! $this->isListOrAmbiguous($source)) {
            $score = $this->add($source, $score, 'service_detail_match');
        }

        if (
            ($intent->is(AssistantQuestionIntent::RECORD_STATUS) || $intent->hasExactRef)
            && $sourceType === 'quote_record'
            && ! $this->isListOrAmbiguous($source)
        ) {
            $score = $this->add($source, $score, 'quote_record_match');
        }

        if ($intent->is(AssistantQuestionIntent::POLICY_QUESTION) && $sourceType === 'handbook') {
            $score = $this->add($source, $score, 'policy_source_match');
        }

        if ($intent->is(AssistantQuestionIntent::QUOTE_CREATION) && in_array('quote_creation', $tags, true)) {
            $score = $this->add($source, $score, 'quote_intent_tag_match');
        }

        if ($intent->is(AssistantQuestionIntent::QUOTE_NEGOTIATION) && in_array('quote_negotiation', $tags, true)) {
            $score = $this->add($source, $score, 'quote_intent_tag_match');
        }

        if (
            $intent->is(AssistantQuestionIntent::QUOTE_CREATION)
            && in_array('quote_negotiation', $tags, true)
            && ! in_array('quote_negotiation', $intent->positiveTerms, true)
        ) {
            $score = $this->add($source, $score, 'quote_creation_negotiation_penalty');
        }

        if ($hasExactLiveDetail && $sourceType === 'knowledge' && ! in_array($intent->primaryIntent, $tags, true)) {
            $score = $this->add($source, $score, 'knowledge_under_exact_detail_penalty');
        }

        if ($hasExactLiveDetail && $this->isListOrAmbiguous($source)) {
            $score = $this->add($source, $score, 'list_under_exact_detail_penalty');
        }

        foreach ((array) ($source['intent_conflicts'] ?? []) as $conflict) {
            if ($conflict === $intent->primaryIntent) {
                $score = $this->add($source, $score, 'intent_conflict_penalty');
            }
        }

        if ($intent->targetTypes !== [] && in_array($sourceType, $intent->targetTypes, true)) {
            $score = $this->add($source, $score, 'target_type_match');
        }

        if (
            $intent->is(AssistantQuestionIntent::LIST_SEARCH)
            && $intent->targetTypes !== []
            && in_array($sourceType, $intent->targetTypes, true)
            && $this->isListOrAmbiguous($source)
        ) {
            $score = $this->add($source, $score, 'list_target_type_match');
        }

        if ($this->titleOrCodeMatches($source, $intent, $question)) {
            $score = $this->add($source, $score, 'title_or_code_match');
        }

        if ($this->bodyMatches($source, $intent, $question)) {
            $score = $this->add($source, $score, 'body_match');
        }

        if (($source['context_quality'] ?? null) === 'complete') {
            $score = $this->add($source, $score, 'complete_context');
        } elseif (($source['context_quality'] ?? null) === 'insufficient') {
            $score = $this->add($source, $score, 'incomplete_context_penalty');
        }

        $missingFieldCount = count((array) ($source['missing_fields'] ?? []));
        if ($missingFieldCount > 0) {
            $penalty = self::SCORES['missing_field_penalty'] * min(5, $missingFieldCount);
            $score += $penalty;
            $source['score_explanations'][] = "missing_fields {$penalty}";
        }

        if (! empty($source['permission_scope']) || ! empty($source['access_scope'])) {
            $score = $this->add($source, $score, 'permission_fit');
        }

        if ($this->isListOrAmbiguous($source)) {
            $score = $this->add($source, $score, 'ambiguity_penalty');
        }

        if (! $this->questionAsksForInactiveSource($question)) {
            if (($source['source_is_deleted'] ?? false) === true) {
                $score = $this->add($source, $score, 'deleted_status_penalty');
            } else {
                $status = strtolower(trim((string) ($source['source_status'] ?? $source['source_freshness_label'] ?? '')));
                if (in_array($status, ['archived', 'archive', 'stale', 'obsolete', 'inactive', 'disabled', 'deleted'], true)) {
                    $score = $this->add($source, $score, 'stale_status_penalty');
                } elseif ($status === 'draft') {
                    $score = $this->add($source, $score, 'draft_status_penalty');
                }
            }
        }

        $source['score'] = $score;

        return $source;
    }

    private function add(array &$source, int|float $score, string $rule): int|float
    {
        $delta = self::SCORES[$rule] ?? 0;
        $source['score_explanations'][] = "{$rule} ".($delta >= 0 ? "+{$delta}" : (string) $delta);

        return $score + $delta;
    }

    private function hasExactLiveDetail(array $sources, AssistantQuestionIntent $intent, string $question, string $currentRoute): bool
    {
        foreach ($sources as $source) {
            if (! $this->isLiveDetail($source)) {
                continue;
            }
            if ($this->isCurrentRouteSource($source, $currentRoute)) {
                return true;
            }
            if ($intent->hasExactRef && $this->sourceMatchesQuestionReference($source, $question)) {
                return true;
            }
            if ($intent->is(AssistantQuestionIntent::SERVICE_EXPLANATION) && ($source['source_type'] ?? '') === 'proposal_template' && ! $this->isListOrAmbiguous($source)) {
                return true;
            }
        }

        return false;
    }

    private function isCurrentRouteSource(array $source, string $currentRoute): bool
    {
        $route = trim((string) ($source['related_route'] ?? ''));
        $currentRoute = trim($currentRoute);

        return $route !== '' && $currentRoute !== '' && $route === $currentRoute;
    }

    private function sourceMatchesQuestionReference(array $source, string $question): bool
    {
        $refs = $this->references($question);
        if ($refs === []) {
            return false;
        }

        $haystack = strtoupper(implode(' ', [
            (string) ($source['title'] ?? ''),
            (string) ($source['slug'] ?? ''),
            (string) ($source['excerpt'] ?? ''),
        ]));

        foreach ($refs as $ref) {
            if (str_contains($haystack, strtoupper($ref))) {
                return true;
            }
        }

        return false;
    }

    private function references(string $question): array
    {
        preg_match_all('/\b(?:Q(?=[A-Z0-9-]*\d)[A-Z0-9-]{2,}|[A-Z]{2,}[A-Z0-9]*(?:-[A-Z0-9]+)+)\b/i', $question, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function tags(array $source): array
    {
        return array_values(array_unique(array_filter(array_merge(
            (array) ($source['intent_tags'] ?? []),
            [(string) ($source['supported_intent'] ?? '')],
        ), 'is_string')));
    }

    private function isListOrAmbiguous(array $source): bool
    {
        $slug = (string) ($source['slug'] ?? '');
        $type = (string) ($source['source_type'] ?? '');

        return str_contains($slug, ':list:')
            || str_contains($slug, ':ambiguous:')
            || $type === 'live_entity'
            || in_array('list_search', $this->tags($source), true)
            || in_array('clarification_needed', $this->tags($source), true);
    }

    private function isLiveDetail(array $source): bool
    {
        $type = (string) ($source['source_type'] ?? '');
        if ($this->isListOrAmbiguous($source)) {
            return false;
        }

        return in_array($type, [
            'project',
            'client',
            'vendor',
            'invoice',
            'debtor',
            'vendor_registration',
            'quote_record',
            'sales_inquiry',
            'leave',
            'salary',
            'task',
            'staff',
            'legal_compliance',
            'proposal_template',
            'jd14',
            'system_feedback',
            'catalog',
            'purchase_order',
            'meeting',
            'procedure',
            'appraisal',
            'whats_new',
        ], true);
    }

    private function titleOrCodeMatches(array $source, AssistantQuestionIntent $intent, string $question): bool
    {
        $terms = $this->significantTerms($intent, $question);
        if ($terms === []) {
            return false;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) ($source['title'] ?? ''),
            (string) ($source['slug'] ?? ''),
            (string) ($source['related_route'] ?? ''),
        ])));

        foreach ($terms as $term) {
            if (strlen($term) >= 3 && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function bodyMatches(array $source, AssistantQuestionIntent $intent, string $question): bool
    {
        $terms = $this->significantTerms($intent, $question);
        if ($terms === []) {
            return false;
        }

        $haystack = strtolower((string) ($source['excerpt'] ?? '').' '.(string) ($source['summary'] ?? ''));
        foreach ($terms as $term) {
            if (strlen($term) >= 4 && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function significantTerms(AssistantQuestionIntent $intent, string $question): array
    {
        $terms = array_merge($intent->positiveTerms, $this->references($question));
        if ($terms === []) {
            preg_match_all('/[a-z0-9]{3,}/i', strtolower($question), $matches);
            $terms = $matches[0] ?? [];
        }

        $ignored = array_flip([
            'what', 'which', 'show', 'this', 'that', 'from', 'with', 'status', 'create',
            'quote', 'quotation', 'service', 'record', 'detail', 'kijo', 'how', 'the',
        ]);

        return array_values(array_unique(array_filter(
            array_map(static fn (string $term): string => strtolower(trim($term)), $terms),
            static fn (string $term): bool => $term !== '' && ! isset($ignored[$term]),
        )));
    }

    private function questionAsksForInactiveSource(string $question): bool
    {
        return (bool) preg_match('/\b(deleted|archived|archive|stale|obsolete|inactive|old|historical|draft)\b/i', $question);
    }
}
