<?php

namespace App\Services\Assistant;

class AssistantSourceIntentRanker
{
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
        $tags = $this->tags($source);
        $sourceType = (string) ($source['source_type'] ?? $source['type'] ?? '');

        if ($this->isCurrentRouteSource($source, $currentRoute)) {
            $score += 1000;
            $source['match_reason'] = 'current_detail_route';
        }

        if ($intent->hasExactRef && $this->sourceMatchesQuestionReference($source, $question)) {
            $score += 850;
            $source['match_reason'] = $source['match_reason'] ?? 'exact_record_reference';
        }

        if ($intent->is(AssistantQuestionIntent::SERVICE_EXPLANATION) && $sourceType === 'proposal_template' && ! $this->isListOrAmbiguous($source)) {
            $score += 650;
        }

        if (
            ($intent->is(AssistantQuestionIntent::RECORD_STATUS) || $intent->hasExactRef)
            && $sourceType === 'quote_record'
            && ! $this->isListOrAmbiguous($source)
        ) {
            $score += 550;
        }

        if ($intent->is(AssistantQuestionIntent::POLICY_QUESTION) && $sourceType === 'handbook') {
            $score += 450;
        }

        if ($intent->is(AssistantQuestionIntent::QUOTE_CREATION) && in_array('quote_creation', $tags, true)) {
            $score += 350;
        }

        if ($intent->is(AssistantQuestionIntent::QUOTE_NEGOTIATION) && in_array('quote_negotiation', $tags, true)) {
            $score += 350;
        }

        if (
            $intent->is(AssistantQuestionIntent::QUOTE_CREATION)
            && in_array('quote_negotiation', $tags, true)
            && ! in_array('quote_negotiation', $intent->positiveTerms, true)
        ) {
            $score -= 900;
        }

        if ($hasExactLiveDetail && $sourceType === 'knowledge' && ! in_array($intent->primaryIntent, $tags, true)) {
            $score -= 500;
        }

        if ($hasExactLiveDetail && $this->isListOrAmbiguous($source)) {
            $score -= 400;
        }

        foreach ((array) ($source['intent_conflicts'] ?? []) as $conflict) {
            if ($conflict === $intent->primaryIntent) {
                $score -= 900;
            }
        }

        if ($intent->targetTypes !== [] && in_array($sourceType, $intent->targetTypes, true)) {
            $score += 120;
        }

        $source['score'] = $score;

        return $source;
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
}
