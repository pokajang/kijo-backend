<?php

namespace App\Services\Assistant;

use App\Services\Ai\OpenAiResponsesClient;
use App\Services\Ai\OpenAiJsonResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AssistantRetrievalPlanner
{
    private ?OpenAiJsonResult $lastFailure = null;

    public function __construct(
        private readonly OpenAiResponsesClient $openAi,
        private readonly AssistantText $text,
    ) {}

    public function plan(string $question, string $currentRoute, Request $request): AssistantRetrievalPlan
    {
        $this->lastFailure = null;

        $cached = $this->lookupCached($question, $currentRoute, $request);
        if ($cached) {
            return $cached;
        }

        $heuristic = $this->heuristicPlan($question, $currentRoute);
        if (! $this->openAi->isConfigured() || ! $this->plannerAiEnabled()) {
            $this->storeCached($question, $currentRoute, $request, $heuristic);

            return $heuristic;
        }

        $result = $this->openAi->jsonSchemaResponse(
            $this->messages($question, $currentRoute),
            $this->schema(),
            'kijo_assistant_retrieval_plan',
            (string) config('services.knowledge_assistant.planner_model', config('services.knowledge_assistant.model', 'gpt-5-nano')),
            (int) config('services.knowledge_assistant.planner_timeout_ms', 12000),
        );

        if (! $result->ok || ! is_array($result->json)) {
            $this->lastFailure = $result;
            $this->storeCached($question, $currentRoute, $request, $heuristic);

            return $heuristic;
        }

        $plan = AssistantRetrievalPlan::fromArray($result->json);
        if ($plan->isEmpty()) {
            $plan = $heuristic;
        } elseif (! $heuristic->isEmpty()) {
            $plan = AssistantRetrievalPlan::fromArray([
                'domains' => array_values(array_unique(array_merge($plan->domains, $heuristic->domains))),
                'search_terms' => array_values(array_unique(array_merge($plan->searchTerms, $heuristic->searchTerms))),
                'record_refs' => array_values(array_unique(array_merge($plan->recordRefs, $heuristic->recordRefs))),
                'intent' => $plan->intent,
                'confidence' => $plan->confidence,
                'clarification_question' => $plan->clarificationQuestion,
            ]);
        }

        $this->storeCached($question, $currentRoute, $request, $plan);

        return $plan;
    }

    public function lastFailure(): ?OpenAiJsonResult
    {
        return $this->lastFailure;
    }

    private function heuristicPlan(string $question, string $currentRoute): AssistantRetrievalPlan
    {
        $domains = [];
        $terms = [];
        $refs = [];
        $intent = 'unknown';
        $normalized = strtolower($question);
        $route = strtolower(trim($currentRoute));

        if (str_starts_with($route, '/handbook') || preg_match('/\b(working time|working hours|office hours|lunch break|dress code|handbook|policy|attendance|employee|staff|hr)\b/i', $question)) {
            $domains[] = 'handbook';
            $intent = 'policy_question';
        }
        foreach (['working time', 'working hours', 'office hours', 'lunch break', 'dress code'] as $term) {
            if (str_contains($normalized, $term)) {
                $terms[] = $term;
            }
        }

        if (str_contains($route, '/templates/proposals') || preg_match('/\b(proposal|template|service|training|course|assessment|monitoring|inspection|manpower|supply)\b/i', $question)) {
            $domains[] = 'proposal_template';
        }

        if (str_contains($route, '/crm/quotes') || preg_match('/\b(quote|quotes|quotation|quotations|pricing|award|follow[- ]?up)\b/i', $question)) {
            $domains[] = 'quote_record';
            $intent = $intent === 'unknown' ? 'record_detail' : $intent;
        }

        if (preg_match_all('/\b[A-Z]{2,}[A-Z0-9]*(?:-[A-Z0-9]+)*\b/', $question, $matches)) {
            $refs = array_values(array_unique($matches[0]));
        }
        if ($this->hasServiceOrRecordIntent($question)) {
            $ignored = [
                'apply' => true,
                'create' => true,
                'detail' => true,
                'explain' => true,
                'find' => true,
                'help' => true,
                'how' => true,
                'kijo' => true,
                'make' => true,
                'open' => true,
                'show' => true,
                'status' => true,
                'tell' => true,
                'what' => true,
                'where' => true,
            ];
            foreach ($this->text->tokens($question) as $token) {
                if (
                    strlen($token) >= 2
                    && strlen($token) <= 20
                    && ! isset($ignored[$token])
                    && ! in_array($token, ['service', 'quote', 'quotation', 'proposal', 'template'], true)
                ) {
                    $refs[] = strtoupper($token);
                }
            }
        }

        return AssistantRetrievalPlan::fromArray([
            'domains' => $domains,
            'search_terms' => array_values(array_unique(array_merge($terms, $this->significantPhrases($question)))),
            'record_refs' => $refs,
            'intent' => $intent,
            'confidence' => $domains === [] ? 'low' : 'medium',
            'clarification_question' => null,
        ]);
    }

    private function messages(string $question, string $currentRoute): array
    {
        return [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You plan retrieval for the Learn Kijo assistant.',
                    'Return only approved source domains and search terms. Do not answer the user.',
                    'Never request raw SQL, credentials, files, file paths, sessions, cookies, tokens, or unrestricted table access.',
                    'Choose domains only from the provided allowlist.',
                    'Prefer handbook for company policy, working time, office hours, attendance, HR, and employee rules.',
                    'Prefer proposal_template for service/proposal/template/course/scope questions.',
                    'Prefer quote_record for quotation references, pricing, quote status, or /crm/quotes routes.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'question' => $question,
                    'current_route' => $currentRoute,
                    'allowed_domains' => AssistantRetrievalPlan::allowedDomains(),
                ], JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['domains', 'search_terms', 'record_refs', 'intent', 'confidence', 'clarification_question'],
            'properties' => [
                'domains' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => AssistantRetrievalPlan::allowedDomains()],
                    'maxItems' => 8,
                ],
                'search_terms' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'maxLength' => 80],
                    'maxItems' => 10,
                ],
                'record_refs' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'maxLength' => 80],
                    'maxItems' => 8,
                ],
                'intent' => [
                    'type' => 'string',
                    'enum' => ['policy_question', 'record_detail', 'how_to', 'metric_question', 'clarification_needed', 'unknown'],
                ],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                'clarification_question' => ['type' => ['string', 'null'], 'maxLength' => 180],
            ],
        ];
    }

    private function lookupCached(string $question, string $currentRoute, Request $request): ?AssistantRetrievalPlan
    {
        if (! Schema::hasTable('assistant_query_plan_cache')) {
            return null;
        }

        $row = DB::table('assistant_query_plan_cache')
            ->where('cache_key', $this->cacheKey($question, $currentRoute, $request))
            ->where('answer_mode', 'retrieval_plan')
            ->first();
        if (! $row) {
            return null;
        }

        $payload = json_decode((string) $row->provider_keys_json, true);
        if (! is_array($payload)) {
            return null;
        }

        try {
            DB::table('assistant_query_plan_cache')->where('id', $row->id)->update([
                'hit_count' => ((int) ($row->hit_count ?? 0)) + 1,
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Cache hit accounting should never block an assistant answer.
        }

        return AssistantRetrievalPlan::fromArray($payload);
    }

    private function storeCached(string $question, string $currentRoute, Request $request, AssistantRetrievalPlan $plan): void
    {
        if (! Schema::hasTable('assistant_query_plan_cache')) {
            return;
        }

        $payload = $plan->toArray();
        try {
            DB::table('assistant_query_plan_cache')->updateOrInsert(
                ['cache_key' => $this->cacheKey($question, $currentRoute, $request)],
                [
                    'cache_key' => $this->cacheKey($question, $currentRoute, $request),
                    'question_hash' => sha1($this->text->normalizedQuestionKey($question)),
                    'normalized_question' => $this->text->normalizedQuestionKey($question),
                    'provider_keys_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'answer_mode' => 'retrieval_plan',
                    'scope_hash' => $this->scopeHash($request),
                    'source_fingerprint' => sha1(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: ''),
                    'hit_count' => 0,
                    'last_used_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        } catch (Throwable) {
            // Planner caching is opportunistic.
        }
    }

    private function cacheKey(string $question, string $currentRoute, Request $request): string
    {
        return sha1(implode('|', [
            'retrieval_plan',
            $this->text->languageHint($question),
            $this->text->normalizedQuestionKey($question),
            trim($currentRoute),
            $this->scopeHash($request),
        ]));
    }

    private function scopeHash(Request $request): string
    {
        return sha1(json_encode([
            'staff_id' => (int) $request->session()->get('staff_id', 0),
            'roles' => $request->session()->get('roles', []),
            'name_code' => $request->session()->get('name_code', ''),
        ], JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function significantPhrases(string $question): array
    {
        $tokens = array_values(array_filter(
            $this->text->tokens($question),
            static fn (string $token): bool => ! in_array($token, ['explain', 'service', 'what', 'tell', 'about', 'kijo', 'amiosh'], true),
        ));

        return $tokens === [] ? [] : [implode(' ', array_slice($tokens, 0, 5))];
    }

    private function hasServiceOrRecordIntent(string $question): bool
    {
        return (bool) preg_match('/\b(explain|service|quote|quotation|proposal|template|record|ref|reference|code)\b/i', $question);
    }

    private function plannerAiEnabled(): bool
    {
        return (bool) config('services.knowledge_assistant.planner_enabled', ! app()->environment('testing'));
    }
}
