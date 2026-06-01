<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

class AssistantAnswerQualityService
{
    public function __construct(private readonly AssistantText $text) {}

    public function validate(
        array $payload,
        array $sources,
        AssistantContextResult $context,
        array $routeCandidates,
        callable $fallback,
    ): array
    {
        $sourceSlugs = array_flip(array_column($sources, 'slug'));
        $slugs = array_values(array_filter(
            (array) ($payload['source_slugs'] ?? []),
            fn ($slug): bool => is_string($slug) && isset($sourceSlugs[$slug]),
        ));
        $answerMarkdown = $this->text->normalizeAssistantContent((string) ($payload['answer_markdown'] ?? ''));
        $routeRefs = $this->routeRefsForAnswer($answerMarkdown, $routeCandidates, $slugs);

        if ($answerMarkdown === '' || $slugs === []) {
            return $fallback($sources, 'I found possible Kijo sources, but they do not directly verify an answer to this question. Try asking with a module name, record name, client/project/vendor name, dashboard metric, policy topic, or action.', $context);
        }

        if ($routeRefs === null) {
            return $fallback($sources, 'I found related Kijo sources, but could not verify an inline app link in the AI response.', $context);
        }

        $answerMode = in_array(($payload['answer_mode'] ?? ''), ['static', 'live', 'mixed'], true)
            ? (string) $payload['answer_mode']
            : $context->answerMode;
        $freshnessLabel = is_string($payload['freshness_label'] ?? null) && trim((string) $payload['freshness_label']) !== ''
            ? trim((string) $payload['freshness_label'])
            : $context->freshnessLabel;

        if (in_array($answerMode, ['live', 'mixed'], true) && ! $freshnessLabel) {
            return $fallback($sources, 'I found related Kijo sources, but could not verify live-data freshness.', $context);
        }

        if ($this->claimsPerformedWriteAction($answerMarkdown)) {
            return $fallback($sources, 'I found related Kijo sources, but the AI response claimed an action that this read-only assistant cannot perform.', $context);
        }

        if ($this->containsUnsupportedInlineRoute($answerMarkdown)) {
            return $fallback($sources, 'I found related Kijo sources, but could not verify a route or link in the AI response.', $context);
        }

        $confidence = in_array(($payload['confidence'] ?? ''), ['high', 'medium', 'low'], true)
            ? (string) $payload['confidence']
            : 'low';

        if ($this->usesAmbiguousSource($sources, $slugs)) {
            $confidence = 'low';
        }

        $answer = [
            'answer_markdown' => Str::limit($answerMarkdown, 2500, ''),
            'confidence' => $confidence,
            'source_slugs' => $slugs,
            'route_refs' => $routeRefs,
            'suggested_queries' => array_values(array_map(
                fn (string $query): string => Str::limit(trim($query), 80, ''),
                array_slice(array_filter(
                    (array) ($payload['suggested_queries'] ?? []),
                    fn ($query): bool => is_string($query) && trim($query) !== '',
                ), 0, 3),
            )),
            'freshness_label' => $freshnessLabel,
            'answer_mode' => $answerMode,
            'context_quality' => $context->contextQuality,
            'provider_key' => $context->metadata['provider_key'] ?? null,
            'supported_intent' => $context->metadata['supported_intent'] ?? null,
            'resolved_entity_ids' => $context->metadata['resolved_entity_ids'] ?? [],
            'missing_fields' => $context->missingFields,
        ];
        $answer['answer_signature'] = $this->answerSignature($answer);

        return $answer;
    }

    public function answerSignature(array $answer): string
    {
        $sourceSlugs = array_values((array) ($answer['source_slugs'] ?? []));
        sort($sourceSlugs);

        return sha1(json_encode([
            'answer' => $this->text->normalizePlainText((string) ($answer['answer_markdown'] ?? $answer['content'] ?? '')),
            'confidence' => $answer['confidence'] ?? null,
            'source_slugs' => $sourceSlugs,
            'answer_mode' => $answer['answer_mode'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function claimsPerformedWriteAction(string $answer): bool
    {
        return (bool) preg_match(
            '/\b(?:i|we|the assistant)\s+(?:have\s+|has\s+|already\s+)?(?:created|updated|deleted|submitted|approved|rejected|sent|saved|cancelled|canceled)\b/i',
            $answer,
        );
    }

    private function routeRefsForAnswer(string $answer, array $routeCandidates, array $sourceSlugs): ?array
    {
        $candidateById = [];
        foreach ($routeCandidates as $candidate) {
            $id = (string) ($candidate['id'] ?? '');
            if ($id !== '') {
                $candidateById[$id] = $candidate;
            }
        }

        preg_match_all('/\[\[kijo-route:([A-Za-z0-9_-]+)\|([^\]\r\n]{1,120})\]\]/', $answer, $matches, PREG_SET_ORDER);
        $tokenCount = substr_count($answer, '[[kijo-route:');
        if ($tokenCount !== count($matches)) {
            return null;
        }

        $allowedSlugs = array_flip($sourceSlugs);
        $seen = [];
        $refs = [];

        foreach ($matches as $match) {
            $id = (string) $match[1];
            $label = trim((string) $match[2]);
            $candidate = $candidateById[$id] ?? null;
            if (! $candidate || $label === '') {
                return null;
            }

            $sourceSlug = (string) ($candidate['source_slug'] ?? '');
            if ($sourceSlug !== '' && ! isset($allowedSlugs[$sourceSlug])) {
                return null;
            }

            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $refs[] = [
                'id' => $id,
                'label' => Str::limit($label, 80, ''),
                'related_route' => (string) ($candidate['related_route'] ?? ''),
                'source_slug' => $sourceSlug,
            ];
        }

        return $refs;
    }

    private function containsUnsupportedInlineRoute(string $answer): bool
    {
        if (preg_match('/\b(?:https?:\/\/|ftp:\/\/|mailto:|javascript:|www\.)\S*/i', $answer)) {
            return true;
        }

        preg_match_all(
            '#(?<![A-Za-z0-9])/[A-Za-z][A-Za-z0-9_-]*(?:/[A-Za-z0-9._~!$&\'()*+,;=:@%-]+)*(?:\?[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]+)?(?:\#[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]+)?#',
            $answer,
            $matches,
        );

        return ($matches[0] ?? []) !== [];
    }

    private function usesAmbiguousSource(array $sources, array $slugs): bool
    {
        $allowed = array_flip($slugs);

        foreach ($sources as $source) {
            $slug = (string) ($source['slug'] ?? '');
            if ($slug === '' || ! isset($allowed[$slug])) {
                continue;
            }

            if (($source['source_type'] ?? '') === 'live_entity') {
                return true;
            }

            if (str_starts_with(Str::lower((string) ($source['title'] ?? '')), 'ambiguous')) {
                return true;
            }
        }

        return false;
    }
}
