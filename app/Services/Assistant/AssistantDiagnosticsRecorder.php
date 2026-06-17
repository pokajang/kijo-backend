<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AssistantDiagnosticsRecorder
{
    private static ?array $current = null;

    public static function start(string $question, string $currentRoute): void
    {
        self::$current = [
            'question' => self::redactScalar($question),
            'question_hash' => sha1($question),
            'current_route' => self::redactScalar($currentRoute),
            'normalized_question' => null,
            'retrieval_question' => null,
            'conversation_focus' => null,
            'planner' => [],
            'providers' => [],
            'score_stages' => [],
            'suppressed_sources' => [],
            'selected_source_fingerprints' => [],
            'denied_retrievals' => [],
            'source_gap' => null,
            'ai_status' => 'ok',
            'created_at' => now()->toDateTimeString(),
        ];
    }

    public static function setNormalizedQuestion(string $question): void
    {
        self::set('normalized_question', $question);
    }

    public static function setRetrievalQuestion(string $question): void
    {
        self::set('retrieval_question', $question);
    }

    public static function setConversationFocus(mixed $focus): void
    {
        self::set('conversation_focus', self::redact($focus));
    }

    public static function setPlanner(?AssistantRetrievalPlan $plan): void
    {
        if (! self::$current || $plan === null) {
            return;
        }

        self::$current['planner'] = self::redact([
            'domains' => $plan->domains,
            'search_terms' => $plan->searchTerms,
            'record_refs' => $plan->recordRefs,
            'intent' => $plan->intent,
            'confidence' => $plan->confidence,
            'clarification_question' => $plan->clarificationQuestion,
        ]);
    }

    public static function recordProviderSkip(string $providerKey, string $reason): void
    {
        self::providerEvent($providerKey, [
            'status' => 'skipped',
            'reason' => $reason,
            'source_count' => 0,
        ]);
    }

    public static function recordProviderRun(string $providerKey, bool $planned, AssistantContextResult $result): void
    {
        self::providerEvent($providerKey, [
            'status' => 'ran',
            'planned' => $planned,
            'source_count' => count($result->sources),
            'answer_mode' => $result->answerMode,
            'context_quality' => $result->contextQuality,
            'missing_fields' => $result->missingFields,
            'provider_keys' => $result->providerKeys,
            'direct_answer' => isset($result->metadata['direct_answer']),
        ]);
    }

    public static function recordScoreStage(string $stage, array $sources): void
    {
        if (! self::$current) {
            return;
        }

        self::$current['score_stages'][$stage] = array_map(
            static fn (array $source): array => self::sourceSnapshot($source),
            array_slice($sources, 0, 12),
        );
    }

    public static function recordSelectedSources(array $sources): void
    {
        if (! self::$current) {
            return;
        }

        self::$current['selected_source_fingerprints'] = array_values(array_filter(array_map(
            static fn (array $source): string => (string) ($source['fingerprint'] ?? sha1((string) ($source['slug'] ?? $source['title'] ?? ''))),
            $sources,
        )));
    }

    public static function recordSuppressedSources(array $suppressed): void
    {
        if (! self::$current || $suppressed === []) {
            return;
        }

        foreach ($suppressed as $source) {
            if (! is_array($source)) {
                continue;
            }
            self::$current['suppressed_sources'][] = self::sourceSnapshot($source);
        }
    }

    public static function recordDenied(string $providerKey, string $recordType, string $reason): void
    {
        if (! self::$current) {
            return;
        }

        self::$current['denied_retrievals'][] = self::redact([
            'provider_key' => $providerKey,
            'record_type' => $recordType,
            'reason' => $reason,
        ]);
    }

    public static function recordSourceGap(string $reason, array $providerKeys, string $confidence, string $answerMode): void
    {
        if (! self::$current) {
            return;
        }

        self::$current['source_gap'] = self::redact([
            'reason' => $reason,
            'provider_keys' => $providerKeys,
            'confidence' => $confidence,
            'answer_mode' => $answerMode,
        ]);
    }

    public static function setAiStatus(?string $status, ?string $stage = null): void
    {
        if (! self::$current) {
            return;
        }

        self::$current['ai_status'] = $status ?: 'ok';
        if ($stage) {
            self::$current['ai_failure_stage'] = $stage;
        }
    }

    public static function finishForMessage(int $messageId, int $threadId): void
    {
        if (! self::$current || $messageId <= 0 || ! Schema::hasTable('assistant_request_diagnostics')) {
            self::$current = null;

            return;
        }

        $payload = self::redact(self::$current);
        try {
            DB::table('assistant_request_diagnostics')->updateOrInsert(
                ['message_id' => $messageId],
                [
                    'message_id' => $messageId,
                    'thread_id' => $threadId,
                    'question_hash' => (string) ($payload['question_hash'] ?? sha1((string) ($payload['question'] ?? ''))),
                    'question' => Str::limit((string) ($payload['question'] ?? ''), 1200, ''),
                    'current_route' => Str::limit((string) ($payload['current_route'] ?? ''), 255, ''),
                    'diagnostics_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        } catch (Throwable) {
            // Diagnostics must never block the assistant response.
        } finally {
            self::$current = null;
        }
    }

    public static function redactedPayload(array $payload): array
    {
        return self::redact($payload);
    }

    private static function set(string $key, mixed $value): void
    {
        if (! self::$current) {
            return;
        }

        self::$current[$key] = self::redact($value);
    }

    private static function providerEvent(string $providerKey, array $event): void
    {
        if (! self::$current) {
            return;
        }

        self::$current['providers'][] = self::redact(['provider_key' => $providerKey] + $event);
    }

    private static function sourceSnapshot(array $source): array
    {
        return self::redact([
            'title' => $source['title'] ?? null,
            'slug' => $source['slug'] ?? null,
            'source_type' => $source['source_type'] ?? $source['type'] ?? null,
            'provider_key' => $source['provider_key'] ?? null,
            'related_route' => $source['related_route'] ?? null,
            'score' => $source['score'] ?? null,
            'score_explanations' => $source['score_explanations'] ?? [],
            'match_reason' => $source['match_reason'] ?? null,
            'supported_intent' => $source['supported_intent'] ?? null,
            'source_status' => $source['source_status'] ?? null,
            'source_is_deleted' => $source['source_is_deleted'] ?? null,
            'fingerprint' => $source['fingerprint'] ?? null,
            'suppression_reason' => $source['suppression_reason'] ?? null,
        ]);
    }

    private static function redact(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 5) {
            return null;
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $normalized = strtolower((string) $key);
                if (self::redactKey($normalized)) {
                    continue;
                }
                $clean[$key] = self::redact($item, $depth + 1);
            }

            return $clean;
        }
        if (is_object($value)) {
            return self::redact(json_decode(json_encode($value) ?: '{}', true) ?: [], $depth + 1);
        }
        if (is_string($value)) {
            return self::redactScalar($value);
        }

        return $value;
    }

    private static function redactKey(string $key): bool
    {
        $compact = preg_replace('/[^a-z0-9]+/', '', $key) ?: $key;

        return str_contains($compact, 'password')
            || str_contains($compact, 'token')
            || str_contains($compact, 'secret')
            || str_contains($compact, 'credential')
            || str_contains($compact, 'apikey')
            || str_contains($compact, 'authorization')
            || str_contains($compact, 'cookie')
            || str_contains($compact, 'session')
            || str_ends_with($compact, 'path')
            || (str_ends_with($compact, 'url') && preg_match('/(file|storage|attachment|certificate|proof|receipt|download)/', $compact));
    }

    private static function redactScalar(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $sensitive = preg_match('/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=_-]{12,}/i', $text)
            || preg_match('/\b(?:sk|pk|rk|sess)_[A-Za-z0-9_-]{12,}\b/i', $text)
            || preg_match('/\b(?:password|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret|jwt|csrf)\s*[:=]\s*\S{4,}/i', $text)
            || preg_match('/\b(?:[A-Za-z]:\\\\|\\\\\\\\|\/var\/www\/|\/home\/[^\/\s]+\/|storage\/app\/|app\/private\/|private\/(?:attachments|receipts|certificates|proofs|files)\/)/i', $text)
            || preg_match('/https?:\/\/\S+\?(?:\S*(&|&amp;)?(?:signature|expires|X-Amz-Signature|X-Amz-Credential)=\S+)/i', $text)
            || preg_match('/^data:(?:application|image)\/[A-Za-z0-9.+-]+;base64,[A-Za-z0-9+\/=\r\n]{80,}$/i', $text)
            || (strlen($text) > 300 && preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $text));

        return $sensitive ? '[redacted]' : Str::limit($text, 1200, '');
    }
}
