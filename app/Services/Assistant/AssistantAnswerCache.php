<?php

namespace App\Services\Assistant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AssistantAnswerCache
{
    public function __construct(
        private readonly AssistantText $text,
        private readonly AssistantAnswerQualityService $quality,
        private readonly AssistantFeedbackMemory $feedbackMemory,
    ) {}

    public function tablesReady(): bool
    {
        return Schema::hasTable('assistant_answer_cache')
            && Schema::hasTable('assistant_live_result_cache')
            && Schema::hasTable('assistant_query_plan_cache');
    }

    public function lookup(
        string $question,
        string $currentRoute,
        string $answerMode,
        array $sources,
        array $providerKeys,
        Request $request,
    ): ?array {
        if (! $this->tablesReady()) {
            return null;
        }

        $key = $this->cacheKey($question, $currentRoute, $answerMode, $sources, $providerKeys, $request);
        $table = $answerMode === 'static' ? 'assistant_answer_cache' : 'assistant_live_result_cache';
        $query = DB::table($table)->where('cache_key', $key);
        if ($table === 'assistant_live_result_cache') {
            $query->where('expires_at', '>', now());
        } else {
            $query->where(fn ($inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }

        $row = $query->first();
        if (! $row) {
            return null;
        }

        $answer = json_decode((string) $row->answer_json, true);
        if (! is_array($answer) || trim((string) ($answer['answer_markdown'] ?? '')) === '') {
            return null;
        }
        $signature = (string) ($row->answer_signature ?? $answer['answer_signature'] ?? $this->quality->answerSignature($answer));
        if ($this->feedbackMemory->isBlockedSignature($signature)) {
            return null;
        }

        DB::table($table)->where('id', $row->id)->update([
            'hit_count' => ((int) ($row->hit_count ?? 0)) + 1,
            'updated_at' => now(),
        ]);

        $this->rememberPlan($question, $answerMode, $sources, $providerKeys, $request);

        return $answer + ['cached' => true, 'answer_signature' => $signature];
    }

    public function lookupRecentLive(string $question, string $currentRoute, Request $request): ?array
    {
        if (! $this->tablesReady()) {
            return null;
        }

        $row = DB::table('assistant_live_result_cache')
            ->where('question_hash', sha1($this->text->normalizedQuestionKey($question)))
            ->where('scope_hash', $this->scopeHash($request))
            ->where('route_hash', $this->routeHash($currentRoute))
            ->where('expires_at', '>', now())
            ->orderByDesc('updated_at')
            ->first();

        if (! $row) {
            return null;
        }

        $answer = json_decode((string) $row->answer_json, true);
        $sources = json_decode((string) ($row->sources_json ?? '[]'), true);
        if (! is_array($answer) || ! is_array($sources) || trim((string) ($answer['answer_markdown'] ?? '')) === '') {
            return null;
        }
        $signature = (string) ($row->answer_signature ?? $answer['answer_signature'] ?? $this->quality->answerSignature($answer));
        if ($this->feedbackMemory->isBlockedSignature($signature)) {
            return null;
        }

        DB::table('assistant_live_result_cache')->where('id', $row->id)->update([
            'hit_count' => ((int) ($row->hit_count ?? 0)) + 1,
            'updated_at' => now(),
        ]);

        return [
            'answer' => $answer + ['cached' => true, 'answer_signature' => $signature],
            'sources' => $sources,
        ];
    }

    public function store(
        string $question,
        string $currentRoute,
        string $answerMode,
        array $sources,
        array $providerKeys,
        Request $request,
        array $answer,
    ): void {
        if (! $this->tablesReady()) {
            return;
        }

        $signature = (string) ($answer['answer_signature'] ?? $this->quality->answerSignature($answer));
        if ($this->feedbackMemory->isBlockedSignature($signature)) {
            return;
        }

        $answer['answer_signature'] = $signature;
        $key = $this->cacheKey($question, $currentRoute, $answerMode, $sources, $providerKeys, $request);
        $payload = [
            'cache_key' => $key,
            'question_hash' => sha1($this->text->normalizedQuestionKey($question)),
            'normalized_question' => $this->text->normalizedQuestionKey($question),
            'source_fingerprint' => $this->sourceFingerprint($sources),
            'answer_json' => json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'answer_signature' => $signature,
            'hit_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($answerMode === 'static') {
            $payload['expires_at'] = null;
            DB::table('assistant_answer_cache')->updateOrInsert(['cache_key' => $key], $payload);
        } else {
            $payload['provider_key'] = implode(',', $providerKeys);
            $payload['scope_hash'] = $this->scopeHash($request);
            $payload['route_hash'] = $this->routeHash($currentRoute);
            $payload['refreshed_at'] = now();
            $payload['expires_at'] = now()->addMinutes(max(1, (int) config('services.knowledge_assistant.live_cache_ttl_minutes', 5)));
            $payload['sources_json'] = json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            DB::table('assistant_live_result_cache')->updateOrInsert(['cache_key' => $key], $payload);
        }

        $this->rememberPlan($question, $answerMode, $sources, $providerKeys, $request);
    }

    public function forgetAnswerSignature(?string $signature): void
    {
        if (! $signature || ! $this->tablesReady()) {
            return;
        }

        DB::table('assistant_answer_cache')->where('answer_signature', $signature)->delete();
        DB::table('assistant_live_result_cache')->where('answer_signature', $signature)->delete();
    }

    private function rememberPlan(
        string $question,
        string $answerMode,
        array $sources,
        array $providerKeys,
        Request $request,
    ): void {
        if (! $this->tablesReady()) {
            return;
        }

        $key = sha1(implode('|', [
            $this->text->normalizedQuestionKey($question),
            implode(',', $providerKeys),
            $this->scopeHash($request),
        ]));

        $existing = DB::table('assistant_query_plan_cache')->where('cache_key', $key)->first();
        DB::table('assistant_query_plan_cache')->updateOrInsert(['cache_key' => $key], [
            'cache_key' => $key,
            'question_hash' => sha1($this->text->normalizedQuestionKey($question)),
            'normalized_question' => $this->text->normalizedQuestionKey($question),
            'provider_keys_json' => json_encode(array_values($providerKeys), JSON_UNESCAPED_SLASHES),
            'answer_mode' => $answerMode,
            'source_fingerprint' => $this->sourceFingerprint($sources),
            'scope_hash' => $this->scopeHash($request),
            'hit_count' => ((int) ($existing->hit_count ?? 0)) + 1,
            'last_used_at' => now(),
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);
    }

    private function cacheKey(
        string $question,
        string $currentRoute,
        string $answerMode,
        array $sources,
        array $providerKeys,
        Request $request,
    ): string {
        return sha1(implode('|', [
            $answerMode,
            $this->text->languageHint($question),
            $this->text->normalizedQuestionKey($question),
            trim($currentRoute),
            implode(',', $providerKeys),
            $this->sourceFingerprint($sources),
            $answerMode === 'static' ? 'static' : $this->scopeHash($request),
        ]));
    }

    private function sourceFingerprint(array $sources): string
    {
        return sha1(json_encode(array_map(fn (array $source): array => [
            'slug' => $source['slug'] ?? null,
            'fingerprint' => $source['fingerprint'] ?? null,
        ], $sources), JSON_UNESCAPED_SLASHES) ?: '');
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
