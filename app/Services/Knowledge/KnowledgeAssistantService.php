<?php

namespace App\Services\Knowledge;

use App\Services\Ai\OpenAiResponsesClient;
use App\Services\Ai\OpenAiJsonResult;
use App\Services\Assistant\AssistantAnswerCache;
use App\Services\Assistant\AssistantAnswerQualityService;
use App\Services\Assistant\AssistantConversationContextResolver;
use App\Services\Assistant\AssistantContextRegistry;
use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantDiagnosticsRecorder;
use App\Services\Assistant\AssistantFeedbackMemory;
use App\Services\Assistant\AssistantMetaQuestionDetector;
use App\Services\Assistant\AssistantQuestionIntent;
use App\Services\Assistant\AssistantQuestionIntentResolver;
use App\Services\Assistant\AssistantRetrievalPlanner;
use App\Services\Assistant\AssistantSourceGapService;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\Sources\KnowledgeArticleContextProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class KnowledgeAssistantService
{
    private const RETENTION_DAYS = 30;

    private const MAX_MESSAGES_PER_THREAD = 20;

    private const MAX_SOURCE_COUNT = 3;

    private const MAX_SOURCE_EXCERPT_LENGTH = 2500;

    public function __construct(
        private readonly OpenAiResponsesClient $openAi,
        private readonly AssistantContextRegistry $contextRegistry,
        private readonly AssistantAnswerCache $answerCache,
        private readonly AssistantAnswerQualityService $answerQuality,
        private readonly AssistantFeedbackMemory $feedbackMemory,
        private readonly AssistantSourceGapService $sourceGaps,
        private readonly AssistantText $assistantText,
        private readonly AssistantMetaQuestionDetector $metaQuestionDetector,
        private readonly AssistantRetrievalPlanner $retrievalPlanner,
        private readonly AssistantConversationContextResolver $conversationContext,
        private readonly AssistantQuestionIntentResolver $intentResolver,
    ) {}

    public function thread(Request $request): JsonResponse
    {
        if (! $this->chatTablesReady()) {
            return response()->json([
                'status' => 'success',
                'assistant' => $this->assistantMetadata(),
                'threads' => [],
                'thread' => null,
                'messages' => [],
            ]);
        }

        $staffId = $this->staffId($request);
        $threadId = (int) $request->query('thread_id', 0);
        $thread = $threadId > 0
            ? $this->threadForStaff($staffId, $threadId)
            : $this->latestThread($staffId);

        return response()->json([
            'status' => 'success',
            'assistant' => $this->assistantMetadata(),
            'threads' => $this->threadsForStaff($staffId),
            'thread' => $thread ? $this->formatThread($thread) : null,
            'messages' => $thread ? $this->messagesForThread((int) $thread->id) : [],
        ]);
    }

    public function createThread(Request $request): JsonResponse
    {
        $staffId = $this->staffId($request);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        if (! $this->chatTablesReady()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Knowledge assistant storage is not ready. Please run database migrations.',
            ], 503);
        }

        $thread = $this->createEmptyThread($staffId);

        return response()->json([
            'status' => 'success',
            'assistant' => $this->assistantMetadata(),
            'threads' => $this->threadsForStaff($staffId),
            'thread' => $this->formatThread($thread),
            'messages' => [],
        ]);
    }

    public function ask(Request $request): JsonResponse
    {
        $staffId = $this->staffId($request);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'current_route' => ['nullable', 'string', 'max:255'],
            'thread_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if (! $this->chatTablesReady()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Knowledge assistant storage is not ready. Please run database migrations.',
            ], 503);
        }

        $question = $this->normalizePlainText((string) $data['question']);
        $currentRoute = trim((string) ($data['current_route'] ?? ''));
        AssistantDiagnosticsRecorder::start($question, $currentRoute);
        AssistantDiagnosticsRecorder::setNormalizedQuestion($this->assistantText->normalizedQuestionKey($question));
        AssistantDiagnosticsRecorder::setLanguageHint($this->languageHint($question));
        $thread = $this->findOrCreateThread($staffId, $question, (int) ($data['thread_id'] ?? 0));
        $this->storeMessage((int) $thread->id, 'user', $question);

        $conversation = $this->conversationContext->resolve((int) $thread->id, $question, $currentRoute);
        $retrievalQuestion = (string) ($conversation['retrieval_question'] ?? $this->assistantText->normalizeAssistantQueryTerms($question));
        AssistantDiagnosticsRecorder::setRetrievalQuestion($retrievalQuestion);
        AssistantDiagnosticsRecorder::setConversationFocus($conversation['conversation_focus'] ?? null);
        $isActionRequest = $this->intentResolver->resolve($retrievalQuestion, $currentRoute)->primaryIntent === AssistantQuestionIntent::ACTION_REQUEST;

        if (! empty($conversation['clarification_needed'])) {
            $answer = [
                'answer_markdown' => (string) ($conversation['clarification_question'] ?? $this->defaultFollowUpClarification($question)),
                'confidence' => 'low',
                'source_slugs' => [],
                'suggested_queries' => [],
                'freshness_label' => null,
                'answer_mode' => 'static',
                'conversation_focus' => $conversation['conversation_focus'] ?? null,
                'clarification_options' => $conversation['clarification_options'] ?? [],
            ];
            $this->sourceGaps->record(
                $question,
                $currentRoute,
                [],
                $this->providerKeysFromClarificationOptions($conversation['clarification_options'] ?? []),
                'low',
                'static',
                'clarification_needed',
            );
            AssistantDiagnosticsRecorder::recordSourceGap('clarification_needed', $this->providerKeysFromClarificationOptions($conversation['clarification_options'] ?? []), 'low', 'static');

            $answer = $this->withReadOnlyActionBoundary($answer, $isActionRequest, $question);

            return $this->finishAssistantResponse($thread, $staffId, $question, $answer, [], null);
        }

        $directContext = $this->contextRegistry->retrieve($retrievalQuestion, $currentRoute, $request);
        if (is_array($directContext->metadata['direct_answer'] ?? null)) {
            $answer = $directContext->metadata['direct_answer'];

            return $this->finishAssistantResponse($thread, $staffId, $question, $answer, $directContext->sources, null);
        }

        $retrievalPlan = $this->retrievalPlanner->plan($retrievalQuestion, $currentRoute, $request);
        AssistantDiagnosticsRecorder::setPlanner($retrievalPlan);
        $context = $this->contextRegistry->retrieve($retrievalQuestion, $currentRoute, $request, $retrievalPlan);
        $sources = $context->sources;
        $routeCandidates = $this->routeCandidatesForSources($sources);

        if (is_array($context->metadata['direct_answer'] ?? null)) {
            $answer = $context->metadata['direct_answer'];

            return $this->finishAssistantResponse($thread, $staffId, $question, $answer, $sources, null);
        }

        if ($sources === []) {
            $plannerFailure = $this->retrievalPlanner->lastFailure();
            $plannerFailureStatus = $this->aiFailureStatus($plannerFailure);
            if ($plannerFailure !== null) {
                $this->logAiFailure($plannerFailure, 'retrieval_planning');
            }
            $plannerLimitReached = in_array($plannerFailureStatus, ['usage_limit', 'rate_limit'], true);
            $clarification = $this->noSourceClarification($question, $plannerLimitReached, $retrievalPlan->clarificationQuestion);
            $answer = [
                'answer_markdown' => $clarification,
                'confidence' => 'low',
                'source_slugs' => [],
                'suggested_queries' => $this->suggestedQueries($question),
                'freshness_label' => null,
                'answer_mode' => 'static',
                'ai_status' => $plannerLimitReached ? $plannerFailureStatus : 'ok',
                'degraded_reason' => $plannerLimitReached ? $this->aiFailureReason($plannerFailureStatus) : null,
                'ai_failure_stage' => $plannerLimitReached ? 'retrieval_planning' : null,
            ];
            $this->sourceGaps->record($question, $currentRoute, [], $context->providerKeys, 'low', 'static', 'no_source');
            AssistantDiagnosticsRecorder::recordSourceGap('no_source', $context->providerKeys, 'low', 'static');
            AssistantDiagnosticsRecorder::setAiStatus($answer['ai_status'] ?? 'ok', $answer['ai_failure_stage'] ?? null);

            $answer = $this->withReadOnlyActionBoundary($answer, $isActionRequest, $question);

            return $this->finishAssistantResponse($thread, $staffId, $question, $answer, [], null);
        }

        if ($context->contextQuality === 'insufficient') {
            $answer = [
                'answer_markdown' => $this->insufficientContextMessage($question),
                'confidence' => 'low',
                'source_slugs' => array_column($sources, 'slug'),
                'suggested_queries' => $this->suggestedQueries($question),
                'freshness_label' => $context->freshnessLabel,
                'answer_mode' => $context->answerMode,
                'context_quality' => $context->contextQuality,
                'provider_key' => $context->metadata['provider_key'] ?? implode(',', $context->providerKeys),
                'supported_intent' => $context->metadata['supported_intent'] ?? null,
                'resolved_entity_ids' => $context->metadata['resolved_entity_ids'] ?? [],
                'missing_fields' => $context->missingFields,
            ];
            $this->sourceGaps->record($question, $currentRoute, $sources, $context->providerKeys, 'low', $context->answerMode, 'insufficient_context');
            AssistantDiagnosticsRecorder::recordSourceGap('insufficient_context', $context->providerKeys, 'low', $context->answerMode);

            $answer = $this->withReadOnlyActionBoundary($answer, $isActionRequest, $question);

            return $this->finishAssistantResponse($thread, $staffId, $question, $answer, $sources, null);
        }

        if (! $this->openAi->isConfigured()) {
            $answer = $this->fallbackAnswer(
                $sources,
                'The AI assistant is not configured, but these Kijo sources look relevant.',
                $context,
                'not_configured',
                'not_configured',
                'configuration',
                $question,
            );
            $answer = $this->withReadOnlyActionBoundary($answer, $isActionRequest, $question);
            AssistantDiagnosticsRecorder::setAiStatus($answer['ai_status'] ?? 'ok', $answer['ai_failure_stage'] ?? null);

            return $this->finishAssistantResponse($thread, $staffId, $question, $answer, $sources, null);
        }

        $cachedAnswer = $this->answerCache->lookup(
            $retrievalQuestion,
            $currentRoute,
            $context->answerMode,
            $sources,
            $context->providerKeys,
            $request,
        );
        if ($cachedAnswer !== null) {
            $cachedAnswer = $this->withReadOnlyActionBoundary($cachedAnswer, $isActionRequest, $question);

            return $this->finishAssistantResponse($thread, $staffId, $question, $cachedAnswer, $sources, null);
        }

        $result = $this->openAi->jsonSchemaResponse(
            $this->messages($question, $currentRoute, $sources, (int) $thread->id, $context, $routeCandidates, $conversation),
            $this->responseSchema($sources, $context, $routeCandidates),
            'kijo_assistant_answer',
            (string) config('services.knowledge_assistant.model', 'gpt-5-nano'),
            (int) config('services.knowledge_assistant.timeout_ms', 30000),
        );

        $answer = $result->ok && $result->json !== null
            ? $this->answerQuality->validate(
                $result->json,
                $sources,
                $context,
                $routeCandidates,
                fn (array $fallbackSources, string $prefix, ?AssistantContextResult $fallbackContext): array => $this->fallbackAnswer($fallbackSources, $prefix, $fallbackContext, question: $question),
            )
            : $this->fallbackAnswer(
                $sources,
                $this->aiFailureFallbackPrefix($result, $question),
                $context,
                $this->aiFailureStatus($result),
                $this->aiFailureReason($this->aiFailureStatus($result)),
                $result->ok ? null : 'answer_generation',
                $question,
            );

        if (! $result->ok) {
            $this->logAiFailure($result, 'answer_generation');
        }

        if ($this->feedbackMemory->isBlockedSignature($answer['answer_signature'] ?? $this->answerQuality->answerSignature($answer))) {
            $answer = $this->fallbackAnswer($sources, 'I found related Kijo sources, but a previous matching response was marked unhelpful. Please check these sources or ask with more detail.', $context, question: $question);
        }

        $answer = $this->withReadOnlyActionBoundary($answer, $isActionRequest, $question);

        if ($result->ok) {
            $this->answerCache->store(
                $retrievalQuestion,
                $currentRoute,
                $context->answerMode,
                $sources,
                $context->providerKeys,
                $request,
                $answer,
            );
        }

        if (($answer['confidence'] ?? 'low') === 'low') {
            $this->sourceGaps->record(
                $question,
                $currentRoute,
                $sources,
                $context->providerKeys,
                'low',
                (string) ($answer['answer_mode'] ?? $context->answerMode),
                'low_confidence',
            );
            AssistantDiagnosticsRecorder::recordSourceGap('low_confidence', $context->providerKeys, 'low', (string) ($answer['answer_mode'] ?? $context->answerMode));
        }
        AssistantDiagnosticsRecorder::setAiStatus($answer['ai_status'] ?? 'ok', $answer['ai_failure_stage'] ?? null);

        return $this->finishAssistantResponse($thread, $staffId, $question, $answer, $sources, $result);
    }

    public function feedback(Request $request, int $messageId): JsonResponse
    {
        $staffId = $this->staffId($request);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        if (! $this->chatTablesReady() || ! Schema::hasTable('assistant_response_feedback')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Assistant feedback storage is not ready. Please run database migrations.',
            ], 503);
        }

        $data = $request->validate([
            'rating' => ['required', Rule::in(['helpful', 'bad'])],
            'reasons' => ['nullable', 'array', 'max:6'],
            'reasons.*' => ['string', Rule::in(['Wrong information', 'Wrong source', 'Outdated', 'Missing data', 'Unclear', 'Other'])],
            'note' => ['nullable', 'string', 'max:1200'],
            'current_route' => ['nullable', 'string', 'max:255'],
        ]);

        $message = DB::table('knowledge_assistant_messages as m')
            ->join('knowledge_assistant_threads as t', 't.id', '=', 'm.thread_id')
            ->where('m.id', $messageId)
            ->where('m.role', 'assistant')
            ->where('t.staff_id', $staffId)
            ->select([
                'm.id',
                'm.thread_id',
                'm.content',
                'm.sources_json',
                'm.confidence',
                'm.created_at',
                't.title as thread_title',
            ])
            ->first();

        if (! $message) {
            return response()->json(['status' => 'error', 'message' => 'Assistant message not found.'], 404);
        }

        $metadata = $this->messageMetadata((string) ($message->sources_json ?? '[]'));
        $sources = $metadata['sources'];
        $question = $this->previousUserQuestion((int) $message->thread_id, (int) $message->id);
        $answerSignature = $this->answerSignatureForStoredMessage($message, $metadata);
        $rating = (string) $data['rating'];
        $reasons = $rating === 'bad'
            ? array_values(array_filter((array) ($data['reasons'] ?? []), fn ($reason): bool => is_string($reason) && trim($reason) !== ''))
            : [];
        $note = $rating === 'bad' && isset($data['note']) ? trim((string) $data['note']) : null;
        $currentRoute = trim((string) ($data['current_route'] ?? ''));

        $feedbackId = DB::table('assistant_response_feedback')->insertGetId([
            'message_id' => (int) $message->id,
            'thread_id' => (int) $message->thread_id,
            'staff_id' => $staffId,
            'rating' => $rating,
            'reasons_json' => json_encode($reasons, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'note' => $note,
            'question' => $question,
            'answer_excerpt' => Str::limit((string) $message->content, 1800, ''),
            'sources_json' => json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'confidence' => $message->confidence,
            'answer_mode' => $metadata['answer_mode'],
            'current_route' => $currentRoute,
            'answer_signature' => $answerSignature,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->feedbackMemory->recordProviderFeedback($question, $currentRoute, $request, $sources, $rating);

        if ($rating === 'bad') {
            $this->answerCache->forgetAnswerSignature($answerSignature);
            $this->mirrorBadFeedback($staffId, $message, $question, $reasons, $note, $currentRoute);
            if (array_intersect($reasons, ['Wrong information', 'Wrong source', 'Missing data'])) {
                $this->sourceGaps->record(
                    $question,
                    $currentRoute,
                    $sources,
                    array_values(array_unique(array_map(
                        fn (array $source): string => (string) ($source['source_type'] ?? $source['type'] ?? ''),
                        $sources,
                    ))),
                    (string) ($message->confidence ?? 'low'),
                    (string) ($metadata['answer_mode'] ?? 'static'),
                    'bad_feedback',
                );
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Feedback saved.',
            'feedback_id' => $feedbackId,
        ]);
    }

    public function clearThread(Request $request): JsonResponse
    {
        $staffId = $this->staffId($request);
        if (! $this->chatTablesReady()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Knowledge assistant chat cleared.',
                'assistant' => $this->assistantMetadata(),
                'threads' => [],
                'thread' => null,
                'messages' => [],
            ]);
        }

        $threadId = (int) ($request->route('threadId') ?? $request->input('thread_id', 0));
        if ($staffId > 0) {
            $query = DB::table('knowledge_assistant_threads')->where('staff_id', $staffId);
            if ($threadId > 0) {
                $query->where('id', $threadId);
            }
            $query->delete();
        }

        $nextThread = $this->latestThread($staffId);

        return response()->json([
            'status' => 'success',
            'message' => 'Knowledge assistant chat cleared.',
            'assistant' => $this->assistantMetadata(),
            'threads' => $this->threadsForStaff($staffId),
            'thread' => $nextThread ? $this->formatThread($nextThread) : null,
            'messages' => $nextThread ? $this->messagesForThread((int) $nextThread->id) : [],
        ]);
    }

    public function pruneExpired(): int
    {
        if (! $this->chatTablesReady()) {
            return 0;
        }

        $deletedCaches = 0;
        if (Schema::hasTable('assistant_live_result_cache')) {
            $deletedCaches += DB::table('assistant_live_result_cache')
                ->where('expires_at', '<=', now())
                ->delete();
        }
        if (Schema::hasTable('assistant_answer_cache')) {
            $deletedCaches += DB::table('assistant_answer_cache')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->delete();
        }
        if (Schema::hasTable('assistant_query_plan_cache')) {
            $deletedCaches += DB::table('assistant_query_plan_cache')
                ->whereNotNull('last_used_at')
                ->where('last_used_at', '<=', now()->subDays(90))
                ->delete();
        }

        $ids = DB::table('knowledge_assistant_threads')
            ->where('expires_at', '<=', now())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return $deletedCaches;
        }

        return $deletedCaches + DB::table('knowledge_assistant_threads')->whereIn('id', $ids)->delete();
    }

    public function rankArticles(string $question, string $currentRoute = '', int $limit = self::MAX_SOURCE_COUNT): array
    {
        return app(KnowledgeArticleContextProvider::class)->rankArticles($question, $currentRoute, $limit);
    }

    public function plainTextFromHtml(string $html): string
    {
        return $this->assistantText->plainTextFromHtml($html);
    }

    public function excerpt(string $text, int $limit = self::MAX_SOURCE_EXCERPT_LENGTH): string
    {
        return $this->assistantText->excerpt($text, $limit);
    }

    private function messages(
        string $question,
        string $currentRoute,
        array $sources,
        int $threadId,
        AssistantContextResult $context,
        array $routeCandidates = [],
        array $conversation = [],
    ): array {
        return [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are the Learn Kijo assistant for an internal business app.',
                    'Answer only from the provided Kijo sources, live metric context, and live module record context.',
                    'If the sources do not answer the question, say the exact app source was not found.',
                    'Do not invent Kijo modules, buttons, routes, policies, metrics, records, or workflows.',
                    'Never write raw URLs or raw /routes in answer_markdown.',
                    'If a useful in-app route is provided in route_candidates, link it only with this exact token format: [[kijo-route:route_id|Label]].',
                    'Only use route_ids from route_candidates. If no route candidate fits, do not include an inline link.',
                    'Never use high confidence unless the answer is directly supported by cited source_slugs.',
                    'If source support is weak, indirect, ambiguous, or incomplete, set confidence to low and say what could not be verified.',
                    'Do not claim you performed actions. This assistant is read-only.',
                    'For how-to questions, return concise step-by-step action instructions.',
                    'For live dashboard, metric, project, client, vendor, invoice, debtor, registration, quote, inquiry, leave, task, staff, legal compliance, proposal template, JD14, feedback, catalog, purchase order, meeting, procedure, appraisal, or WhatsNew answers, include the provided freshness timestamp in the answer.',
                    'If live module sources are ambiguous, clearly ask the user to specify the exact record and list the relevant matches.',
                    'Answer in the same language as the user question. If language_hint is bahasa_malaysia, answer in natural Malaysian workplace Bahasa Malaysia.',
                    'For Bahasa Malaysia answers, keep official app terms, source titles, record codes, statuses, legal/technical labels, and route labels unchanged when translation could make them unclear.',
                    'You may translate explanation text into the user language, but keep all facts grounded in the provided sources and do not invent translated facts.',
                    'Return only JSON matching the provided schema.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'question' => $question,
                    'language_hint' => $this->languageHint($question),
                    'current_route' => $currentRoute,
                    'answer_mode' => $context->answerMode,
                    'freshness_label' => $context->freshnessLabel,
                    'conversation_context' => [
                        'retrieval_question' => $conversation['retrieval_question'] ?? $question,
                        'conversation_focus' => $conversation['conversation_focus'] ?? null,
                        'context_confidence' => $conversation['context_confidence'] ?? 'none',
                    ],
                    'recent_messages' => $this->recentMessagesForPrompt($threadId),
                    'route_candidates' => $routeCandidates,
                    'sources' => array_map(fn (array $source): array => [
                        'slug' => $source['slug'],
                        'source_type' => $source['source_type'] ?? 'knowledge',
                        'title' => $source['title'],
                        'summary' => $source['summary'],
                        'related_route' => $source['related_route'],
                        'freshness_label' => $source['freshness_label'] ?? null,
                        'provider_key' => $source['provider_key'] ?? null,
                        'supported_intent' => $source['supported_intent'] ?? null,
                        'context_quality' => $source['context_quality'] ?? null,
                        'missing_fields' => $source['missing_fields'] ?? [],
                        'source_status' => $source['source_status'] ?? null,
                        'source_is_deleted' => $source['source_is_deleted'] ?? null,
                        'source_freshness_label' => $source['source_freshness_label'] ?? null,
                        'excerpt' => $source['excerpt'],
                    ], $sources),
                ], JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    private function responseSchema(array $sources, AssistantContextResult $context, array $routeCandidates = []): array
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['answer_markdown', 'confidence', 'source_slugs', 'suggested_queries', 'freshness_label', 'answer_mode', 'route_refs'],
            'properties' => [
                'answer_markdown' => ['type' => 'string', 'maxLength' => 2500],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                'source_slugs' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => array_column($sources, 'slug')],
                    'maxItems' => self::MAX_SOURCE_COUNT,
                ],
                'suggested_queries' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'maxLength' => 80],
                    'maxItems' => 3,
                ],
                'freshness_label' => [
                    'type' => ['string', 'null'],
                    'maxLength' => 120,
                ],
                'answer_mode' => [
                    'type' => 'string',
                    'enum' => ['static', 'live', 'mixed'],
                ],
            ],
        ];

        $schema['properties']['route_refs'] = $routeCandidates === []
            ? [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'maxItems' => 0,
            ]
            : [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'label', 'related_route', 'source_slug'],
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'enum' => array_column($routeCandidates, 'id'),
                        ],
                        'label' => ['type' => 'string', 'maxLength' => 80],
                        'related_route' => [
                            'type' => 'string',
                            'enum' => array_column($routeCandidates, 'related_route'),
                        ],
                        'source_slug' => [
                            'type' => 'string',
                            'enum' => array_column($routeCandidates, 'source_slug'),
                        ],
                    ],
                ],
                'maxItems' => self::MAX_SOURCE_COUNT,
            ];

        return $schema;
    }

    private function noSourceClarification(string $question, bool $plannerLimitReached, ?string $plannerClarification): string
    {
        if ($this->isBahasaMalaysiaQuestion($question)) {
            if ($plannerLimitReached) {
                return 'Penjanaan jawapan AI tidak tersedia sementara kerana had penggunaan atau bajet kredit AI telah dicapai. Saya juga tidak dapat jumpa sumber Kijo yang diluluskan untuk soalan ini. Cuba tanya dengan nama module, rujukan rekod, topik polisi, client, project, atau vendor.';
            }

            return 'Saya belum dapat jumpa sumber Kijo yang diluluskan untuk soalan itu. Module, rekod, topik polisi, atau rujukan tepat mana yang perlu saya semak?';
        }

        if ($plannerLimitReached) {
            return 'AI answer generation is temporarily unavailable because the AI usage limit or credit budget has been reached. I also could not find an approved Kijo source for this question. Try asking with a module name, record reference, policy topic, client, project, or vendor name.';
        }

        return $plannerClarification
            ?: 'I could not find an approved Kijo source for that yet. Which module, record, policy topic, or exact reference should I check?';
    }

    private function insufficientContextMessage(string $question): string
    {
        if ($this->isBahasaMalaysiaQuestion($question)) {
            return 'Saya jumpa sumber Kijo yang mungkin berkaitan, tetapi sumber itu tidak mempunyai medan selamat yang cukup untuk jawab dengan yakin. Cuba tanya dengan nama module, rujukan rekod, nama client/project/vendor, atau julat tarikh yang lebih khusus.';
        }

        return 'I found a possible Kijo source, but it does not contain enough safe fields to answer this reliably. Try asking with a more specific module name, record reference, client/project/vendor name, or date range.';
    }

    private function defaultFollowUpClarification(string $question): string
    {
        return $this->isBahasaMalaysiaQuestion($question)
            ? 'Item sebelum ini yang mana saya perlu guna untuk follow-up ini?'
            : 'Which previous item should I use for this follow-up?';
    }

    private function localizedFallbackPrefix(string $prefix, ?string $question): string
    {
        if (! $this->isBahasaMalaysiaQuestion($question)) {
            return $prefix;
        }

        return match ($prefix) {
            'I found possible Kijo sources, but they do not directly verify an answer to this question. Try asking with a module name, record name, client/project/vendor name, dashboard metric, policy topic, or action.'
                => 'Saya jumpa sumber Kijo yang mungkin berkaitan, tetapi sumber itu tidak mengesahkan jawapan untuk soalan ini secara terus. Cuba tanya dengan nama module, nama rekod, client/project/vendor, metrik dashboard, topik polisi, atau tindakan.',
            'I found related Kijo sources, but could not verify an inline app link in the AI response.'
                => 'Saya jumpa sumber Kijo yang berkaitan, tetapi tidak dapat sahkan link app dalam jawapan AI.',
            'I found related Kijo sources, but could not verify live-data freshness.'
                => 'Saya jumpa sumber Kijo yang berkaitan, tetapi tidak dapat sahkan freshness data live.',
            'I found related Kijo sources, but the AI response claimed an action that this read-only assistant cannot perform.'
                => 'Saya jumpa sumber Kijo yang berkaitan, tetapi jawapan AI menyatakan tindakan telah dibuat walaupun assistant ini read-only.',
            'I found related Kijo sources, but could not verify a route or link in the AI response.'
                => 'Saya jumpa sumber Kijo yang berkaitan, tetapi tidak dapat sahkan route atau link dalam jawapan AI.',
            'The AI assistant is not configured, but these Kijo sources look relevant.'
                => 'AI assistant belum dikonfigurasi, tetapi sumber Kijo ini nampak berkaitan.',
            'AI answer generation is temporarily unavailable because the AI usage limit or credit budget has been reached. I found these approved Kijo sources that may help.'
                => 'Penjanaan jawapan AI tidak tersedia sementara kerana had penggunaan atau bajet kredit AI telah dicapai. Saya jumpa sumber Kijo yang diluluskan ini dan mungkin membantu.',
            'AI answer generation is temporarily unavailable. I found these approved Kijo sources that may help.'
                => 'Penjanaan jawapan AI tidak tersedia sementara. Saya jumpa sumber Kijo yang diluluskan ini dan mungkin membantu.',
            'I found possible Kijo sources, but could not generate a reliable AI answer right now.'
                => 'Saya jumpa sumber Kijo yang mungkin berkaitan, tetapi tidak dapat jana jawapan AI yang boleh dipercayai sekarang.',
            'I found related Kijo sources, but a previous matching response was marked unhelpful. Please check these sources or ask with more detail.'
                => 'Saya jumpa sumber Kijo yang berkaitan, tetapi jawapan sepadan sebelum ini ditanda tidak membantu. Sila semak sumber ini atau tanya dengan lebih terperinci.',
            'I found related Kijo sources.'
                => 'Saya jumpa sumber Kijo yang berkaitan.',
            default => $prefix,
        };
    }

    private function fallbackAnswer(
        array $sources,
        string $prefix = 'I found related Kijo sources.',
        ?AssistantContextResult $context = null,
        string $aiStatus = 'source_fallback',
        ?string $degradedReason = 'source_fallback',
        ?string $aiFailureStage = null,
        ?string $question = null,
    ): array {
        $sourceTitles = array_map(fn (array $source): string => '- '.$source['title'], $sources);
        $contextMetadata = $context?->metadata ?? [];
        $prefix = $this->localizedFallbackPrefix($prefix, $question);

        return [
            'answer_markdown' => trim($prefix."\n\n".implode("\n", $sourceTitles)),
            'confidence' => 'low',
            'source_slugs' => array_column($sources, 'slug'),
            'suggested_queries' => [],
            'freshness_label' => $context?->freshnessLabel,
            'answer_mode' => $context?->answerMode ?? 'static',
            'route_refs' => [],
            'context_quality' => $context?->contextQuality ?? 'partial',
            'provider_key' => $contextMetadata['provider_key'] ?? null,
            'supported_intent' => $contextMetadata['supported_intent'] ?? null,
            'resolved_entity_ids' => $contextMetadata['resolved_entity_ids'] ?? [],
            'missing_fields' => $context?->missingFields ?? [],
            'ai_status' => $aiStatus,
            'degraded_reason' => $degradedReason,
            'ai_failure_stage' => $aiFailureStage,
        ];
    }

    private function withReadOnlyActionBoundary(array $answer, bool $isActionRequest, ?string $question = null): array
    {
        if (! $isActionRequest) {
            return $answer;
        }

        $notice = $this->isBahasaMalaysiaQuestion($question)
            ? 'Saya tidak boleh melakukan tindakan dari chat ini. Saya hanya boleh beri panduan menggunakan sumber Kijo yang diluluskan.'
            : 'I cannot perform actions from this chat. I can only guide you using approved Kijo sources.';
        $content = trim((string) ($answer['answer_markdown'] ?? ''));
        if ($content === '') {
            $answer['answer_markdown'] = $notice;
        } elseif (! preg_match('/cannot perform actions from this chat|tidak boleh melakukan tindakan dari chat ini/i', $content)) {
            $answer['answer_markdown'] = $notice."\n\n".$content;
        }

        $answer['read_only_notice'] = true;

        return $answer;
    }

    private function aiFailureFallbackPrefix(OpenAiJsonResult $result, ?string $question = null): string
    {
        $status = $this->aiFailureStatus($result);

        $prefix = match ($status) {
            'usage_limit', 'rate_limit' => 'AI answer generation is temporarily unavailable because the AI usage limit or credit budget has been reached. I found these approved Kijo sources that may help.',
            'not_configured' => 'The AI assistant is not configured, but these Kijo sources look relevant.',
            'temporary_unavailable' => 'AI answer generation is temporarily unavailable. I found these approved Kijo sources that may help.',
            default => 'I found possible Kijo sources, but could not generate a reliable AI answer right now.',
        };

        return $this->localizedFallbackPrefix($prefix, $question);
    }

    private function aiFailureStatus(?OpenAiJsonResult $result): string
    {
        if ($result === null || $result->ok) {
            return 'ok';
        }

        $error = Str::lower((string) ($result->error ?? ''));
        $status = (int) ($result->status ?? 0);

        if (
            str_contains($error, 'insufficient_quota')
            || str_contains($error, 'quota')
            || str_contains($error, 'credit')
            || str_contains($error, 'billing')
            || str_contains($error, 'usage limit')
            || str_contains($error, 'hard limit')
        ) {
            return 'usage_limit';
        }

        if ($status === 429 || str_contains($error, 'rate limit') || str_contains($error, 'rate_limit') || str_contains($error, 'too many requests')) {
            return 'rate_limit';
        }

        if (str_contains($error, 'api key is not configured') || str_contains($error, 'model is not configured')) {
            return 'not_configured';
        }

        if (
            in_array($status, [408, 500, 502, 503, 504], true)
            || str_contains($error, 'timed out')
            || str_contains($error, 'timeout')
            || str_contains($error, 'connection')
            || str_contains($error, 'temporarily unavailable')
        ) {
            return 'temporary_unavailable';
        }

        return 'generation_failed';
    }

    private function aiFailureReason(string $aiStatus): ?string
    {
        return $aiStatus === 'ok' ? null : $aiStatus;
    }

    private function logAiFailure(OpenAiJsonResult $result, string $stage): void
    {
        Log::warning('Knowledge assistant OpenAI request failed.', [
            'stage' => $stage,
            'ai_status' => $this->aiFailureStatus($result),
            'http_status' => $result->status,
            'error' => $result->error,
        ]);
    }

    private function routeCandidatesForSources(array $sources): array
    {
        $seen = [];
        $candidates = [];

        foreach ($sources as $source) {
            $slug = trim((string) ($source['slug'] ?? ''));
            $route = trim((string) ($source['related_route'] ?? ''));
            if ($slug === '' || ! $this->isSafeInternalRoute($route)) {
                continue;
            }

            $key = $slug.'|'.$route;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $candidates[] = [
                'id' => 'route_'.substr(sha1($key), 0, 12),
                'label' => Str::limit(trim((string) ($source['title'] ?? $route)), 80, ''),
                'related_route' => $route,
                'source_slug' => $slug,
            ];

            if (count($candidates) >= self::MAX_SOURCE_COUNT) {
                break;
            }
        }

        return $candidates;
    }

    private function isSafeInternalRoute(string $route): bool
    {
        return $route !== ''
            && preg_match('/^\/[A-Za-z0-9_\-\/?=&%.#]*$/', $route) === 1
            && ! str_starts_with($route, '//')
            && ! str_starts_with(Str::lower($route), '/knowledge');
    }

    private function findOrCreateThread(int $staffId, string $question, int $threadId = 0): object
    {
        $existing = $threadId > 0
            ? $this->threadForStaff($staffId, $threadId)
            : $this->latestThread($staffId);
        $now = now();
        $expiresAt = $now->copy()->addDays(self::RETENTION_DAYS);

        if ($existing) {
            if ($this->threadExpired($existing->expires_at)) {
                DB::table('knowledge_assistant_messages')->where('thread_id', $existing->id)->delete();
            }

            DB::table('knowledge_assistant_threads')->where('id', $existing->id)->update([
                'title' => $this->threadTitleForQuestion($existing->title, $question),
                'expires_at' => $expiresAt,
                'last_message_at' => $now,
                'updated_at' => $now,
            ]);

            return DB::table('knowledge_assistant_threads')->where('id', $existing->id)->first();
        }

        if ($threadId > 0) {
            abort(404, 'Knowledge assistant chat not found.');
        }

        $id = DB::table('knowledge_assistant_threads')->insertGetId([
            'staff_id' => $staffId,
            'title' => $this->initialThreadTitleForQuestion($question),
            'expires_at' => $expiresAt,
            'last_message_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('knowledge_assistant_threads')->where('id', $id)->first();
    }

    private function createEmptyThread(int $staffId): object
    {
        $now = now();
        $id = DB::table('knowledge_assistant_threads')->insertGetId([
            'staff_id' => $staffId,
            'title' => 'New chat',
            'expires_at' => $now->copy()->addDays(self::RETENTION_DAYS),
            'last_message_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('knowledge_assistant_threads')->where('id', $id)->first();
    }

    private function latestThread(int $staffId): ?object
    {
        if ($staffId <= 0) {
            return null;
        }

        return DB::table('knowledge_assistant_threads')
            ->where('staff_id', $staffId)
            ->where('expires_at', '>', now())
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();
    }

    private function threadForStaff(int $staffId, int $threadId): ?object
    {
        if ($staffId <= 0 || $threadId <= 0) {
            return null;
        }

        return DB::table('knowledge_assistant_threads')
            ->where('id', $threadId)
            ->where('staff_id', $staffId)
            ->where('expires_at', '>', now())
            ->first();
    }

    private function threadsForStaff(int $staffId): array
    {
        if ($staffId <= 0 || ! $this->chatTablesReady()) {
            return [];
        }

        return DB::table('knowledge_assistant_threads as t')
            ->leftJoin('knowledge_assistant_messages as m', 'm.thread_id', '=', 't.id')
            ->where('t.staff_id', $staffId)
            ->where('t.expires_at', '>', now())
            ->groupBy('t.id', 't.title', 't.expires_at', 't.last_message_at')
            ->orderByDesc('t.last_message_at')
            ->orderByDesc('t.id')
            ->select([
                't.id',
                't.title',
                't.expires_at',
                't.last_message_at',
                DB::raw('COUNT(m.id) as message_count'),
            ])
            ->limit(50)
            ->get()
            ->map(fn ($thread): array => $this->formatThread($thread))
            ->all();
    }

    private function threadTitleForQuestion(?string $title, string $question): string
    {
        $current = trim((string) $title);
        if ($current !== '' && $current !== 'New chat') {
            return $current;
        }

        return $this->initialThreadTitleForQuestion($question);
    }

    private function initialThreadTitleForQuestion(string $question): string
    {
        if ($this->isAssistantMetaQuestion($question)) {
            return 'Learn Kijo AI help';
        }

        return Str::limit($question, 80, '');
    }

    private function isAssistantMetaThreadTitle(string $title): bool
    {
        $normalized = $this->metaQuestionDetector->normalized($title);

        return $normalized === 'learn kijo ai help' || $this->isAssistantMetaQuestion($title);
    }

    private function isAssistantMetaQuestion(string $question): bool
    {
        return $this->metaQuestionDetector->isMetaQuestion($question);
    }

    private function storeMessage(int $threadId, string $role, string $content): void
    {
        DB::table('knowledge_assistant_messages')->insert([
            'thread_id' => $threadId,
            'role' => $role,
            'content' => $content,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function finishAssistantResponse(
        object $thread,
        int $staffId,
        string $question,
        array $answer,
        array $sources,
        mixed $result,
    ): JsonResponse {
        $threadId = (int) $thread->id;
        $answer = $this->withClarificationOptions($answer, $sources);
        $messageId = $this->storeAssistantMessage($threadId, $answer, $sources, $result);
        AssistantDiagnosticsRecorder::finishForMessage($messageId, $threadId);
        $this->conversationContext->remember($threadId, $question, $answer, $sources);
        $this->trimThread($threadId);
        $thread = $this->threadForStaff($staffId, $threadId) ?? $thread;

        return response()->json($this->responsePayload($thread, $answer, $sources));
    }

    private function withClarificationOptions(array $answer, array $sources): array
    {
        if (! empty($answer['clarification_options']) && is_array($answer['clarification_options'])) {
            $answer['clarification_options'] = array_slice($answer['clarification_options'], 0, 3);

            return $answer;
        }

        $isLowConfidence = ($answer['confidence'] ?? 'low') === 'low';
        if (! $isLowConfidence) {
            return $answer;
        }

        $options = [];
        foreach ($sources as $source) {
            $tags = is_array($source['intent_tags'] ?? null) ? $source['intent_tags'] : [];
            $isClarification = ($source['supported_intent'] ?? null) === 'clarification_needed'
                || in_array('clarification_needed', $tags, true);
            if (! $isClarification) {
                continue;
            }

            $options[] = [
                'label' => Str::limit((string) ($source['title'] ?? 'Source'), 80, ''),
                'source_slug' => (string) ($source['slug'] ?? ''),
                'source_type' => (string) ($source['source_type'] ?? $source['type'] ?? ''),
                'related_route' => $source['related_route'] ?? null,
                'reason' => 'ambiguous_source',
            ];
            if (count($options) >= 3) {
                break;
            }
        }

        if ($options !== []) {
            $answer['clarification_options'] = $options;
        }

        return $answer;
    }

    private function providerKeysFromClarificationOptions(array $options): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $option): string => is_array($option) ? (string) ($option['source_type'] ?? '') : '',
            $options,
        ))));
    }

    private function storeAssistantMessage(int $threadId, array $answer, array $sources, mixed $result): int
    {
        $answerSignature = (string) ($answer['answer_signature'] ?? $this->answerQuality->answerSignature($answer));

        return (int) DB::table('knowledge_assistant_messages')->insertGetId([
            'thread_id' => $threadId,
            'role' => 'assistant',
            'content' => $answer['answer_markdown'],
            'sources_json' => json_encode([
                'sources' => $this->sourcesForSlugs($sources, $answer['source_slugs'] ?? []),
                'suggested_queries' => $answer['suggested_queries'] ?? [],
                'route_refs' => $answer['route_refs'] ?? [],
                'freshness_label' => $answer['freshness_label'] ?? null,
                'answer_mode' => $answer['answer_mode'] ?? 'static',
                'cached' => (bool) ($answer['cached'] ?? false),
                'answer_signature' => $answerSignature,
                'context_quality' => $answer['context_quality'] ?? null,
                'provider_key' => $answer['provider_key'] ?? null,
                'supported_intent' => $answer['supported_intent'] ?? null,
                'resolved_entity_ids' => $answer['resolved_entity_ids'] ?? [],
                'missing_fields' => $answer['missing_fields'] ?? [],
                'ai_status' => $answer['ai_status'] ?? 'ok',
                'degraded_reason' => $answer['degraded_reason'] ?? null,
                'ai_failure_stage' => $answer['ai_failure_stage'] ?? null,
                'read_only_notice' => $answer['read_only_notice'] ?? false,
                'clarification_options' => $answer['clarification_options'] ?? [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'confidence' => $answer['confidence'] ?? 'low',
            'input_tokens' => $result?->inputTokens,
            'output_tokens' => $result?->outputTokens,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function trimThread(int $threadId): void
    {
        $idsToDelete = DB::table('knowledge_assistant_messages')
            ->where('thread_id', $threadId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip(self::MAX_MESSAGES_PER_THREAD)
            ->take(1000)
            ->pluck('id');

        if ($idsToDelete->isNotEmpty()) {
            DB::table('knowledge_assistant_messages')->whereIn('id', $idsToDelete)->delete();
        }

        DB::table('knowledge_assistant_threads')->where('id', $threadId)->update([
            'expires_at' => now()->addDays(self::RETENTION_DAYS),
            'last_message_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function messagesForThread(int $threadId): array
    {
        return DB::table('knowledge_assistant_messages')
            ->where('thread_id', $threadId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => $this->formatMessage($row))
            ->all();
    }

    private function recentMessagesForPrompt(int $threadId): array
    {
        return DB::table('knowledge_assistant_messages')
            ->where('thread_id', $threadId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->reverse()
            ->map(fn ($row): array => [
                'role' => (string) $row->role,
                'content' => Str::limit((string) $row->content, 500, ''),
            ])
            ->values()
            ->all();
    }

    private function messageMetadata(string $rawMetadata): array
    {
        $metadata = json_decode($rawMetadata, true);
        $sources = [];
        $suggestedQueries = [];
        $freshnessLabel = null;
        $answerMode = null;
        $cached = false;
        $answerSignature = null;
        $contextQuality = null;
        $providerKey = null;
        $supportedIntent = null;
        $resolvedEntityIds = [];
        $missingFields = [];
        $routeRefs = [];
        $aiStatus = 'ok';
        $degradedReason = null;
        $aiFailureStage = null;
        $readOnlyNotice = false;
        $clarificationOptions = [];

        if (is_array($metadata) && array_is_list($metadata)) {
            $sources = $metadata;
        } elseif (is_array($metadata)) {
            $sources = is_array($metadata['sources'] ?? null) ? $metadata['sources'] : [];
            $suggestedQueries = is_array($metadata['suggested_queries'] ?? null) ? $metadata['suggested_queries'] : [];
            $routeRefs = is_array($metadata['route_refs'] ?? null) ? $metadata['route_refs'] : [];
            $freshnessLabel = is_string($metadata['freshness_label'] ?? null) ? $metadata['freshness_label'] : null;
            $answerMode = is_string($metadata['answer_mode'] ?? null) ? $metadata['answer_mode'] : null;
            $cached = (bool) ($metadata['cached'] ?? false);
            $answerSignature = is_string($metadata['answer_signature'] ?? null) ? $metadata['answer_signature'] : null;
            $contextQuality = is_string($metadata['context_quality'] ?? null) ? $metadata['context_quality'] : null;
            $providerKey = is_string($metadata['provider_key'] ?? null) ? $metadata['provider_key'] : null;
            $supportedIntent = is_string($metadata['supported_intent'] ?? null) ? $metadata['supported_intent'] : null;
            $resolvedEntityIds = is_array($metadata['resolved_entity_ids'] ?? null) ? $metadata['resolved_entity_ids'] : [];
            $missingFields = is_array($metadata['missing_fields'] ?? null) ? $metadata['missing_fields'] : [];
            $aiStatus = is_string($metadata['ai_status'] ?? null) ? $metadata['ai_status'] : 'ok';
            $degradedReason = is_string($metadata['degraded_reason'] ?? null) ? $metadata['degraded_reason'] : null;
            $aiFailureStage = is_string($metadata['ai_failure_stage'] ?? null) ? $metadata['ai_failure_stage'] : null;
            $readOnlyNotice = (bool) ($metadata['read_only_notice'] ?? false);
            $clarificationOptions = is_array($metadata['clarification_options'] ?? null) ? $metadata['clarification_options'] : [];
        }

        return [
            'sources' => $sources,
            'suggested_queries' => $suggestedQueries,
            'route_refs' => $routeRefs,
            'freshness_label' => $freshnessLabel,
            'answer_mode' => $answerMode,
            'cached' => $cached,
            'answer_signature' => $answerSignature,
            'context_quality' => $contextQuality,
            'provider_key' => $providerKey,
            'supported_intent' => $supportedIntent,
            'resolved_entity_ids' => $resolvedEntityIds,
            'missing_fields' => $missingFields,
            'ai_status' => $aiStatus,
            'degraded_reason' => $degradedReason,
            'ai_failure_stage' => $aiFailureStage,
            'read_only_notice' => $readOnlyNotice,
            'clarification_options' => $clarificationOptions,
        ];
    }

    private function previousUserQuestion(int $threadId, int $messageId): string
    {
        return (string) (DB::table('knowledge_assistant_messages')
            ->where('thread_id', $threadId)
            ->where('id', '<', $messageId)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->value('content') ?? '');
    }

    private function answerSignatureForStoredMessage(object $message, array $metadata): string
    {
        if (is_string($metadata['answer_signature'] ?? null) && $metadata['answer_signature'] !== '') {
            return $metadata['answer_signature'];
        }

        return $this->answerQuality->answerSignature([
            'answer_markdown' => (string) $message->content,
            'confidence' => $message->confidence,
            'source_slugs' => array_values(array_filter(array_map(
                fn (array $source): string => (string) ($source['slug'] ?? ''),
                $metadata['sources'] ?? [],
            ))),
            'answer_mode' => $metadata['answer_mode'] ?? null,
        ]);
    }

    private function formatMessage(object $row): array
    {
        $metadata = $this->messageMetadata((string) ($row->sources_json ?? '[]'));

        return [
            'id' => (int) $row->id,
            'role' => (string) $row->role,
            'content' => (string) $row->content,
            'sources' => $metadata['sources'],
            'suggested_queries' => $metadata['suggested_queries'],
            'route_refs' => $metadata['route_refs'],
            'confidence' => $row->confidence,
            'freshness_label' => $metadata['freshness_label'],
            'answer_mode' => $metadata['answer_mode'],
            'cached' => $metadata['cached'],
            'answer_signature' => $metadata['answer_signature'],
            'context_quality' => $metadata['context_quality'],
            'provider_key' => $metadata['provider_key'],
            'supported_intent' => $metadata['supported_intent'],
            'resolved_entity_ids' => $metadata['resolved_entity_ids'],
            'missing_fields' => $metadata['missing_fields'],
            'ai_status' => $metadata['ai_status'],
            'degraded_reason' => $metadata['degraded_reason'],
            'ai_failure_stage' => $metadata['ai_failure_stage'],
            'read_only_notice' => $metadata['read_only_notice'],
            'clarification_options' => $metadata['clarification_options'],
            'created_at' => $row->created_at,
        ];
    }

    private function responsePayload(object $thread, array $answer, array $sources): array
    {
        return [
            'status' => 'success',
            'assistant' => $this->assistantMetadata(),
            'threads' => $this->threadsForStaff((int) $thread->staff_id),
            'thread' => $this->formatThread($thread),
            'answer' => [
                'content' => $answer['answer_markdown'],
                'confidence' => $answer['confidence'],
                'sources' => $this->sourcesForSlugs($sources, $answer['source_slugs'] ?? []),
                'suggested_queries' => $answer['suggested_queries'] ?? [],
                'route_refs' => $answer['route_refs'] ?? [],
                'freshness_label' => $answer['freshness_label'] ?? null,
                'answer_mode' => $answer['answer_mode'] ?? 'static',
                'cached' => (bool) ($answer['cached'] ?? false),
                'answer_signature' => $answer['answer_signature'] ?? $this->answerQuality->answerSignature($answer),
                'context_quality' => $answer['context_quality'] ?? null,
                'provider_key' => $answer['provider_key'] ?? null,
                'supported_intent' => $answer['supported_intent'] ?? null,
                'resolved_entity_ids' => $answer['resolved_entity_ids'] ?? [],
                'missing_fields' => $answer['missing_fields'] ?? [],
                'ai_status' => $answer['ai_status'] ?? 'ok',
                'degraded_reason' => $answer['degraded_reason'] ?? null,
                'ai_failure_stage' => $answer['ai_failure_stage'] ?? null,
                'read_only_notice' => $answer['read_only_notice'] ?? false,
                'clarification_options' => $answer['clarification_options'] ?? [],
            ],
            'messages' => $this->messagesForThread((int) $thread->id),
        ];
    }

    private function formatThread(object $thread): array
    {
        return [
            'id' => (int) $thread->id,
            'title' => $this->displayThreadTitle($thread),
            'expires_at' => $thread->expires_at,
            'last_message_at' => $thread->last_message_at,
            'message_count' => (int) ($thread->message_count ?? 0),
        ];
    }

    private function displayThreadTitle(object $thread): string
    {
        $title = trim((string) ($thread->title ?? ''));
        if ($title === '') {
            return 'New chat';
        }

        if (! $this->isAssistantMetaThreadTitle($title)) {
            return $title;
        }

        return 'Learn Kijo AI help';
    }

    private function sourcesForSlugs(array $sources, array $slugs): array
    {
        $allowed = array_flip($slugs);

        return array_values(array_map(fn (array $source): array => [
            'title' => $source['title'],
            'slug' => $source['slug'],
            'type' => $source['source_type'] ?? $source['type'] ?? 'knowledge',
            'source_type' => $source['source_type'] ?? $source['type'] ?? 'knowledge',
            'related_route' => $source['related_route'],
            'summary' => $source['summary'],
            'freshness_label' => $source['freshness_label'] ?? null,
            'provider_key' => $source['provider_key'] ?? null,
            'supported_intent' => $source['supported_intent'] ?? null,
            'context_quality' => $source['context_quality'] ?? null,
            'resolved_entity_ids' => $source['resolved_entity_ids'] ?? [],
            'missing_fields' => $source['missing_fields'] ?? [],
            'source_status' => $source['source_status'] ?? null,
            'source_is_deleted' => $source['source_is_deleted'] ?? null,
            'source_freshness_label' => $source['source_freshness_label'] ?? null,
        ], array_filter($sources, fn (array $source): bool => isset($allowed[$source['slug']]))));
    }

    private function mirrorBadFeedback(
        int $staffId,
        object $message,
        string $question,
        array $reasons,
        mixed $note,
        string $currentRoute,
    ): void {
        if (! Schema::hasTable('system_feedbacks')) {
            return;
        }

        $feedback = Str::limit(implode("\n", array_filter([
            'AI Assistant bad response feedback',
            $currentRoute !== '' ? "Route: {$currentRoute}" : null,
            $question !== '' ? 'Question: '.Str::limit($question, 900, '') : null,
            'Reasons: '.($reasons ? implode(', ', $reasons) : 'Not specified'),
            is_string($note) && trim($note) !== '' ? 'User note: '.Str::limit(trim($note), 1200, '') : null,
            'Answer: '.Str::limit((string) $message->content, 1800, ''),
        ])), 5000, '');

        $payload = [
            'feedback' => $feedback,
            'reported_by' => $staffId,
        ];
        if (Schema::hasColumn('system_feedbacks', 'date_reported')) {
            $payload['date_reported'] = now();
        }
        if (Schema::hasColumn('system_feedbacks', 'status')) {
            $payload['status'] = 'Pending';
        }
        if (Schema::hasColumn('system_feedbacks', 'created_at')) {
            $payload['created_at'] = now();
        }
        if (Schema::hasColumn('system_feedbacks', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::table('system_feedbacks')->insert($payload);
    }

    private function suggestedQueries(string $question): array
    {
        $tokens = array_slice($this->assistantText->tokens($question), 0, 3);

        return $tokens ? [implode(' ', $tokens)] : [];
    }

    private function staffId(Request $request): int
    {
        return (int) $request->session()->get('staff_id', 0);
    }

    private function normalizePlainText(string $text): string
    {
        return $this->assistantText->normalizePlainText($text);
    }

    private function languageHint(string $question): string
    {
        return $this->assistantText->languageHint($question);
    }

    private function isBahasaMalaysiaQuestion(?string $question): bool
    {
        return is_string($question) && $this->languageHint($question) === 'bahasa_malaysia';
    }

    private function threadExpired(mixed $expiresAt): bool
    {
        if ($expiresAt === null) {
            return true;
        }

        try {
            return now()->greaterThanOrEqualTo(Carbon::parse($expiresAt));
        } catch (Throwable) {
            return true;
        }
    }

    private function chatTablesReady(): bool
    {
        return Schema::hasTable('knowledge_assistant_threads')
            && Schema::hasTable('knowledge_assistant_messages');
    }

    private function assistantMetadata(): array
    {
        return [
            'beta' => true,
            'model' => (string) config('services.knowledge_assistant.model', 'gpt-5-nano'),
        ];
    }
}
