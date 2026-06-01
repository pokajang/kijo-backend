<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

class AssistantContextQualityService
{
    private const REQUIRED_BY_TYPE = [
        'project' => ['title', 'related_route'],
        'client' => ['title', 'related_route'],
        'vendor' => ['title', 'related_route'],
        'invoice' => ['title', 'related_route'],
        'debtor' => ['title', 'related_route'],
        'quote_record' => ['title', 'related_route'],
        'sales_inquiry' => ['title', 'related_route'],
        'leave' => ['title', 'related_route'],
        'task' => ['title', 'related_route'],
    ];

    public function annotateSource(array $source, string $providerKey, ?string $supportedIntent = null): array
    {
        $sourceType = (string) ($source['source_type'] ?? $source['type'] ?? $providerKey);
        $missing = $this->missingFields($source, $sourceType);
        $isAmbiguous = $this->isAmbiguous($source);
        $hasExcerpt = trim((string) ($source['excerpt'] ?? '')) !== '';
        $quality = $hasExcerpt ? ($isAmbiguous || $missing !== [] ? 'partial' : 'complete') : 'insufficient';
        $entityIds = $this->resolvedEntityIds($source);

        return $source + [
            'provider_key' => $providerKey,
            'supported_intent' => $supportedIntent ?: $this->inferIntent($source),
            'resolved_entity_ids' => $entityIds,
            'ambiguity_count' => $isAmbiguous ? max(1, count($entityIds)) : 0,
            'context_quality' => $quality,
            'missing_fields' => $missing,
        ];
    }

    public function summarize(array $sources): array
    {
        if ($sources === []) {
            return [
                'context_quality' => 'insufficient',
                'missing_fields' => ['source'],
                'metadata' => [
                    'provider_key' => null,
                    'supported_intent' => null,
                    'resolved_entity_ids' => [],
                    'ambiguity_count' => 0,
                ],
            ];
        }

        $qualities = array_map(
            static fn (array $source): string => (string) ($source['context_quality'] ?? 'complete'),
            $sources,
        );
        $quality = in_array('insufficient', $qualities, true)
            ? 'insufficient'
            : (in_array('partial', $qualities, true) ? 'partial' : 'complete');

        return [
            'context_quality' => $quality,
            'missing_fields' => array_values(array_unique(array_merge(...array_map(
                static fn (array $source): array => array_values((array) ($source['missing_fields'] ?? [])),
                $sources,
            )))),
            'metadata' => [
                'provider_key' => implode(',', array_values(array_unique(array_filter(array_map(
                    static fn (array $source): string => (string) ($source['provider_key'] ?? ''),
                    $sources,
                ))))),
                'supported_intent' => implode(',', array_values(array_unique(array_filter(array_map(
                    static fn (array $source): string => (string) ($source['supported_intent'] ?? ''),
                    $sources,
                ))))),
                'resolved_entity_ids' => array_values(array_unique(array_merge(...array_map(
                    static fn (array $source): array => array_values((array) ($source['resolved_entity_ids'] ?? [])),
                    $sources,
                )))),
                'ambiguity_count' => array_sum(array_map(
                    static fn (array $source): int => (int) ($source['ambiguity_count'] ?? 0),
                    $sources,
                )),
            ],
        ];
    }

    private function missingFields(array $source, string $sourceType): array
    {
        $required = self::REQUIRED_BY_TYPE[$sourceType] ?? ['title'];

        return array_values(array_filter($required, static function (string $field) use ($source): bool {
            return trim((string) ($source[$field] ?? '')) === '';
        }));
    }

    private function isAmbiguous(array $source): bool
    {
        $title = Str::lower((string) ($source['title'] ?? ''));

        return ($source['source_type'] ?? '') === 'live_entity' || str_starts_with($title, 'ambiguous');
    }

    private function resolvedEntityIds(array $source): array
    {
        $slug = (string) ($source['slug'] ?? '');
        preg_match_all('/:(\d+)(?::|$)/', $slug, $matches);

        return array_values(array_unique(array_map('intval', $matches[1] ?? [])));
    }

    private function inferIntent(array $source): string
    {
        $slug = (string) ($source['slug'] ?? '');
        if (str_contains($slug, ':list:')) {
            return 'list_summary';
        }
        if (str_contains($slug, ':ambiguous:') || ($source['source_type'] ?? '') === 'live_entity') {
            return 'ambiguity_clarification';
        }

        return 'record_lookup';
    }
}
