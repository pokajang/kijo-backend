<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AssistantSourceGapService
{
    public function __construct(private readonly AssistantIntentNormalizer $normalizer) {}

    public function record(
        string $question,
        string $currentRoute,
        array $sources,
        array $providerKeys,
        string $confidence,
        string $answerMode,
        string $reason = 'low_confidence',
    ): void {
        if (! Schema::hasTable('assistant_source_gaps')) {
            return;
        }

        $intent = $this->normalizer->normalize($question);
        $normalizedIntent = (string) ($intent['normalized_intent'] ?? '');
        if ($normalizedIntent === '') {
            return;
        }
        $routeScope = $this->routeScope($currentRoute);

        $sourceTypes = array_values(array_unique(array_filter(array_map(
            static fn (array $source): string => (string) ($source['source_type'] ?? $source['type'] ?? ''),
            $sources,
        ))));
        $providerKeys = array_values(array_unique(array_filter($providerKeys)));
        $gapKey = sha1(implode('|', [
            $normalizedIntent,
            $routeScope,
            implode(',', $providerKeys),
            implode(',', $sourceTypes),
            $reason,
        ]));

        $existing = DB::table('assistant_source_gaps')->where('gap_key', $gapKey)->first();
        $occurrenceCount = ((int) ($existing->occurrence_count ?? 0)) + 1;
        $payload = [
            'gap_key' => $gapKey,
            'normalized_intent' => $normalizedIntent,
            'sample_question' => $existing->sample_question ?? $question,
            'current_route' => $routeScope !== '' ? $routeScope : null,
            'source_types_json' => json_encode($sourceTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'provider_keys_json' => json_encode($providerKeys, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'confidence' => $confidence,
            'answer_mode' => $answerMode,
            'occurrence_count' => $occurrenceCount,
            'last_seen_at' => now(),
            'status' => $existing->status ?? 'open',
            'notes' => $existing->notes ?? null,
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('assistant_source_gaps', 'priority')) {
            $payload['priority'] = $this->priority($occurrenceCount, $reason, $existing->priority ?? null);
        }

        DB::table('assistant_source_gaps')->updateOrInsert(['gap_key' => $gapKey], $payload);
    }

    private function routeScope(string $route): string
    {
        $route = trim($route);
        if ($route === '') {
            return '';
        }

        $path = parse_url($route, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            $path = $route;
        }

        return preg_replace('~/\d+(?=/|$)~', '/{id}', trim($path)) ?: trim($path);
    }

    private function priority(int $occurrenceCount, string $reason, ?string $existingPriority): string
    {
        if ($existingPriority === 'high') {
            return 'high';
        }
        if ($occurrenceCount >= 10 || $reason === 'bad_feedback') {
            return 'high';
        }
        if ($existingPriority === 'medium' || $occurrenceCount >= 3) {
            return 'medium';
        }

        return 'low';
    }
}
