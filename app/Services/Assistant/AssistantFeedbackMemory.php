<?php

namespace App\Services\Assistant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AssistantFeedbackMemory
{
    public function __construct(private readonly AssistantText $text) {}

    public function tablesReady(): bool
    {
        return Schema::hasTable('assistant_response_feedback')
            && Schema::hasTable('assistant_provider_feedback_memory');
    }

    public function isBlockedSignature(?string $signature): bool
    {
        if (! $signature || ! Schema::hasTable('assistant_response_feedback')) {
            return false;
        }

        return DB::table('assistant_response_feedback')
            ->where('answer_signature', $signature)
            ->where('rating', 'bad')
            ->exists();
    }

    public function applySourceScores(string $question, string $currentRoute, Request $request, array $sources): array
    {
        if (! $this->tablesReady() || $sources === []) {
            return $sources;
        }

        return array_map(function (array $source) use ($question, $currentRoute, $request): array {
            $memory = $this->memoryForSource($question, $currentRoute, $request, $source);
            if (! $memory) {
                return $source;
            }

            $positiveBoost = min(25, ((int) ($memory->positive_count ?? 0)) * 5);
            $negativePenalty = min(35, ((int) ($memory->negative_count ?? 0)) * 8);
            $baseScore = is_numeric($source['score'] ?? null) ? (float) $source['score'] : 0.0;
            $source['score'] = max(1, $baseScore + $positiveBoost - $negativePenalty);

            return $source;
        }, $sources);
    }

    public function recordProviderFeedback(
        string $question,
        string $currentRoute,
        Request $request,
        array $sources,
        string $rating,
    ): void {
        if (! $this->tablesReady() || $sources === []) {
            return;
        }
        if (! in_array($rating, ['helpful', 'bad'], true)) {
            return;
        }

        foreach ($sources as $source) {
            $key = $this->memoryKey($question, $currentRoute, $request, $source);
            $existing = DB::table('assistant_provider_feedback_memory')->where('memory_key', $key)->first();
            $positive = (int) ($existing->positive_count ?? 0);
            $negative = (int) ($existing->negative_count ?? 0);

            if ($rating === 'helpful') {
                $positive++;
            } elseif ($rating === 'bad') {
                $negative++;
            }

            DB::table('assistant_provider_feedback_memory')->updateOrInsert(['memory_key' => $key], [
                'memory_key' => $key,
                'question_hash' => sha1($this->normalizedQuestion($question)),
                'normalized_question' => $this->normalizedQuestion($question),
                'provider_key' => $this->providerKey($source),
                'source_type' => $this->sourceType($source),
                'source_slug' => $this->sourceSlug($source),
                'route_hash' => $this->routeHash($currentRoute),
                'scope_hash' => $this->scopeHash($request),
                'positive_count' => $positive,
                'negative_count' => $negative,
                'last_feedback_at' => now(),
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function memoryForSource(string $question, string $currentRoute, Request $request, array $source): ?object
    {
        return DB::table('assistant_provider_feedback_memory')
            ->where('memory_key', $this->memoryKey($question, $currentRoute, $request, $source))
            ->first();
    }

    private function memoryKey(string $question, string $currentRoute, Request $request, array $source): string
    {
        return sha1(implode('|', [
            $this->normalizedQuestion($question),
            $this->providerKey($source),
            $this->sourceType($source),
            $this->sourceSlug($source),
            $this->routeHash($currentRoute),
            $this->scopeHash($request),
        ]));
    }

    private function normalizedQuestion(string $question): string
    {
        return $this->text->normalizedQuestionKey($question);
    }

    private function providerKey(array $source): string
    {
        return $this->sourceType($source);
    }

    private function sourceType(array $source): string
    {
        $type = trim((string) ($source['source_type'] ?? $source['type'] ?? 'unknown'));

        return $type !== '' ? substr($type, 0, 80) : 'unknown';
    }

    private function sourceSlug(array $source): string
    {
        $slug = trim((string) ($source['slug'] ?? $source['ref'] ?? $source['title'] ?? ''));
        if ($slug === '') {
            $slug = sha1(json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        }

        return substr($slug, 0, 255);
    }

    private function scopeHash(Request $request): string
    {
        return sha1(json_encode([
            'staff_id' => (int) $request->session()->get('staff_id', 0),
            'roles' => $request->session()->get('roles', []),
            'name_code' => $request->session()->get('name_code', ''),
        ], JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function routeHash(string $currentRoute): string
    {
        return sha1(trim($currentRoute));
    }
}
