<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantDiagnosticsRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class AdminAssistantGovernanceController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }

        $feedback = $this->tableRows('assistant_response_feedback');
        $assistantMessages = $this->assistantMessages();
        $staticCache = $this->tableRows('assistant_answer_cache');
        $liveCache = $this->tableRows('assistant_live_result_cache');
        $providerMemory = $this->tableRows('assistant_provider_feedback_memory');
        $sourceGaps = $this->tableRows('assistant_source_gaps');
        $badSignatures = $this->blockedSignatures();

        $helpful = $feedback->where('rating', 'helpful')->count();
        $bad = $feedback->where('rating', 'bad')->count();
        $lowConfidence = $assistantMessages->where('confidence', 'low')->count();
        $noSource = $assistantMessages->filter(fn ($row): bool => $this->messageSources($row)->isEmpty())->count();
        $aiStatusCounts = $this->aiStatusCounts($assistantMessages);
        $totalMessages = max(1, $assistantMessages->count());
        $staticHits = $staticCache->sum(fn ($row): int => (int) ($row->hit_count ?? 0));
        $liveHits = $liveCache->sum(fn ($row): int => (int) ($row->hit_count ?? 0));

        return response()->json([
            'status' => 'success',
            'summary' => [
                'feedback_total' => $feedback->count(),
                'helpful_count' => $helpful,
                'bad_count' => $bad,
                'helpful_rate' => $feedback->count() > 0 ? round($helpful / $feedback->count() * 100, 1) : 0,
                'bad_rate' => $feedback->count() > 0 ? round($bad / $feedback->count() * 100, 1) : 0,
                'assistant_message_count' => $assistantMessages->count(),
                'low_confidence_count' => $lowConfidence,
                'low_confidence_rate' => round($lowConfidence / $totalMessages * 100, 1),
                'no_source_count' => $noSource,
                'no_source_rate' => round($noSource / $totalMessages * 100, 1),
                'static_cache_rows' => $staticCache->count(),
                'live_cache_rows' => $liveCache->count(),
                'static_cache_hits' => $staticHits,
                'live_cache_hits' => $liveHits,
                'blocked_signature_count' => count($badSignatures),
                'provider_positive_total' => $providerMemory->sum(fn ($row): int => (int) ($row->positive_count ?? 0)),
                'provider_negative_total' => $providerMemory->sum(fn ($row): int => (int) ($row->negative_count ?? 0)),
                'source_gap_open_count' => $sourceGaps->where('status', 'open')->count(),
                'input_tokens' => $assistantMessages->sum(fn ($row): int => (int) ($row->input_tokens ?? 0)),
                'output_tokens' => $assistantMessages->sum(fn ($row): int => (int) ($row->output_tokens ?? 0)),
                'ai_status_counts' => $aiStatusCounts,
                'usage_limit_count' => $aiStatusCounts['usage_limit'] ?? 0,
                'rate_limit_count' => $aiStatusCounts['rate_limit'] ?? 0,
                'not_configured_count' => $aiStatusCounts['not_configured'] ?? 0,
                'temporary_unavailable_count' => $aiStatusCounts['temporary_unavailable'] ?? 0,
                'generation_failed_count' => $aiStatusCounts['generation_failed'] ?? 0,
                'source_fallback_count' => $aiStatusCounts['source_fallback'] ?? 0,
                'ai_unavailable_count' => $this->aiUnavailableCount($aiStatusCounts),
            ],
            'token_trend' => $this->tokenTrend($assistantMessages),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    public function feedback(Request $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }

        $blocked = array_flip($this->blockedSignatures());
        $rows = $this->tableRows('assistant_response_feedback')
            ->sortByDesc('created_at')
            ->take(200)
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'message_id' => (int) $row->message_id,
                'thread_id' => (int) $row->thread_id,
                'staff_id' => (int) $row->staff_id,
                'rating' => $row->rating,
                'reasons' => $this->decodeJson($row->reasons_json ?? '[]'),
                'note' => $row->note,
                'question' => $row->question,
                'answer_excerpt' => $row->answer_excerpt,
                'sources' => $this->decodeJson($row->sources_json ?? '[]'),
                'confidence' => $row->confidence,
                'answer_mode' => $row->answer_mode,
                'current_route' => $row->current_route,
                'answer_signature' => $row->answer_signature,
                'blocked' => isset($blocked[(string) $row->answer_signature]),
                'system_feedback_id' => null,
                'created_at' => $row->created_at,
            ])
            ->values();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function messageDiagnostics(Request $request, int $messageId): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }
        if (! Schema::hasTable('assistant_request_diagnostics')) {
            return response()->json(['status' => 'error', 'message' => 'Assistant diagnostics storage is not ready.'], 503);
        }

        $row = DB::table('assistant_request_diagnostics')
            ->where('message_id', $messageId)
            ->first();
        if (! $row) {
            return response()->json(['status' => 'error', 'message' => 'Assistant diagnostics not found.'], 404);
        }

        $payload = AssistantDiagnosticsRecorder::redactedPayload($this->decodeJson($row->diagnostics_json ?? '{}'));
        $question = AssistantDiagnosticsRecorder::redactedPayload(['question' => $row->question])['question'] ?? null;

        return response()->json([
            'status' => 'success',
            'data' => [
                'message_id' => (int) $row->message_id,
                'thread_id' => $row->thread_id ? (int) $row->thread_id : null,
                'question_hash' => $row->question_hash,
                'question' => $question,
                'current_route' => $row->current_route,
                'diagnostics' => $payload,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ],
        ]);
    }

    public function cache(Request $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }

        $blocked = array_flip($this->blockedSignatures());
        $static = $this->cacheRows('assistant_answer_cache', 'static', $blocked);
        $live = $this->cacheRows('assistant_live_result_cache', 'live', $blocked);

        return response()->json([
            'status' => 'success',
            'data' => $static->merge($live)->sortByDesc('updated_at')->values()->take(200)->values(),
        ]);
    }

    public function providerMemory(Request $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }

        $rows = $this->tableRows('assistant_provider_feedback_memory')
            ->sortByDesc('last_feedback_at')
            ->take(200)
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'normalized_question' => $row->normalized_question,
                'provider_key' => $row->provider_key,
                'source_type' => $row->source_type,
                'source_slug' => $row->source_slug,
                'route_hash' => $row->route_hash,
                'scope_hash' => $row->scope_hash,
                'positive_count' => (int) $row->positive_count,
                'negative_count' => (int) $row->negative_count,
                'last_feedback_at' => $row->last_feedback_at,
            ])
            ->values();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function sourceGaps(Request $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }

        $rows = $this->tableRows('assistant_source_gaps')
            ->sortByDesc('last_seen_at')
            ->take(200)
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'normalized_intent' => $row->normalized_intent,
                'sample_question' => $row->sample_question,
                'current_route' => $row->current_route,
                'source_types' => $this->decodeJson($row->source_types_json ?? '[]'),
                'provider_keys' => $this->decodeJson($row->provider_keys_json ?? '[]'),
                'confidence' => $row->confidence,
                'answer_mode' => $row->answer_mode,
                'occurrence_count' => (int) $row->occurrence_count,
                'last_seen_at' => $row->last_seen_at,
                'status' => $row->status,
                'priority' => $row->priority ?? $this->gapPriority((int) $row->occurrence_count),
                'notes' => $row->notes,
                'actions' => $this->sourceGapActions((int) $row->id),
            ])
            ->values();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function updateSourceGapStatus(Request $request, int $id): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }
        if (! Schema::hasTable('assistant_source_gaps')) {
            return response()->json(['status' => 'error', 'message' => 'Assistant source gap storage is not ready.'], 503);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'planned', 'resolved', 'ignored'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! DB::table('assistant_source_gaps')->where('id', $id)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Source gap not found.'], 404);
        }

        $payload = [
            'status' => $data['status'],
            'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('assistant_source_gaps', 'priority')) {
            $payload['priority'] = $data['priority'] ?? DB::table('assistant_source_gaps')->where('id', $id)->value('priority') ?? 'low';
        }

        DB::table('assistant_source_gaps')->where('id', $id)->update($payload);

        return response()->json(['status' => 'success', 'message' => 'Source gap updated.']);
    }

    public function analyticsOverview(Request $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }

        $filters = $this->analyticsFilters($request);
        $feedback = $this->filteredFeedback($filters);
        $messages = $this->filteredAssistantMessages($filters);
        $staticCache = $this->tableRows('assistant_answer_cache');
        $liveCache = $this->tableRows('assistant_live_result_cache');
        $providerMemory = $this->filteredProviderMemory($filters);
        $sourceGaps = $this->filteredSourceGaps($filters);
        $totalFeedback = max(1, $feedback->count());
        $totalMessages = max(1, $messages->count());
        $helpful = $feedback->where('rating', 'helpful')->count();
        $bad = $feedback->where('rating', 'bad')->count();
        $lowConfidence = $messages->where('confidence', 'low')->count();
        $noSource = $messages->filter(fn ($row): bool => $this->messageSources($row)->isEmpty())->count();
        $validationFallback = $messages->filter(function ($row): bool {
            $content = strtolower((string) ($row->content ?? ''));

            return str_contains($content, 'could not verify')
                || str_contains($content, 'not enough safe fields')
                || str_contains($content, 'could not generate a reliable');
        })->count();
        $inputTokens = $messages->sum(fn ($row): int => (int) ($row->input_tokens ?? 0));
        $outputTokens = $messages->sum(fn ($row): int => (int) ($row->output_tokens ?? 0));
        $aiStatusCounts = $this->aiStatusCounts($messages);

        return response()->json([
            'status' => 'success',
            'filters' => $filters,
            'summary' => [
                'feedback_total' => $feedback->count(),
                'helpful_count' => $helpful,
                'bad_count' => $bad,
                'helpful_rate' => round($helpful / $totalFeedback * 100, 1),
                'bad_rate' => round($bad / $totalFeedback * 100, 1),
                'assistant_message_count' => $messages->count(),
                'low_confidence_count' => $lowConfidence,
                'low_confidence_rate' => round($lowConfidence / $totalMessages * 100, 1),
                'no_source_count' => $noSource,
                'no_source_rate' => round($noSource / $totalMessages * 100, 1),
                'validation_fallback_count' => $validationFallback,
                'validation_fallback_rate' => round($validationFallback / $totalMessages * 100, 1),
                'static_cache_rows' => $staticCache->count(),
                'live_cache_rows' => $liveCache->count(),
                'static_cache_hits' => $staticCache->sum(fn ($row): int => (int) ($row->hit_count ?? 0)),
                'live_cache_hits' => $liveCache->sum(fn ($row): int => (int) ($row->hit_count ?? 0)),
                'blocked_signature_count' => count($this->blockedSignatures()),
                'provider_positive_total' => $providerMemory->sum(fn ($row): int => (int) ($row->positive_count ?? 0)),
                'provider_negative_total' => $providerMemory->sum(fn ($row): int => (int) ($row->negative_count ?? 0)),
                'source_gap_open_count' => $sourceGaps->where('status', 'open')->count(),
                'source_gap_high_count' => $sourceGaps->where('priority', 'high')->count(),
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'estimated_cost' => $this->estimatedCost($inputTokens, $outputTokens),
                'ai_status_counts' => $aiStatusCounts,
                'usage_limit_count' => $aiStatusCounts['usage_limit'] ?? 0,
                'rate_limit_count' => $aiStatusCounts['rate_limit'] ?? 0,
                'not_configured_count' => $aiStatusCounts['not_configured'] ?? 0,
                'temporary_unavailable_count' => $aiStatusCounts['temporary_unavailable'] ?? 0,
                'generation_failed_count' => $aiStatusCounts['generation_failed'] ?? 0,
                'source_fallback_count' => $aiStatusCounts['source_fallback'] ?? 0,
                'ai_unavailable_count' => $this->aiUnavailableCount($aiStatusCounts),
            ],
            'token_trend' => $this->tokenTrend($messages),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    public function analyticsProviders(Request $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }

        $filters = $this->analyticsFilters($request);
        $feedback = $this->filteredFeedback($filters);
        $providerMemory = $this->filteredProviderMemory($filters);
        $sourceGaps = $this->filteredSourceGaps($filters);
        $providerKeys = collect()
            ->merge($providerMemory->pluck('provider_key'))
            ->merge($feedback->flatMap(fn ($row): array => $this->feedbackProviders($row)))
            ->merge($sourceGaps->flatMap(fn ($row): array => $this->decodeJson($row->provider_keys_json ?? '[]')))
            ->filter()
            ->unique()
            ->values();

        $providers = $providerKeys
            ->map(function (string $provider) use ($providerMemory, $feedback, $sourceGaps): array {
                $rows = $providerMemory->where('provider_key', $provider);
                $providerFeedback = $feedback->filter(fn ($row): bool => $this->rowHasProvider($row, $provider));
                $totalFeedback = max(1, $providerFeedback->count());

                return [
                    'provider_key' => $provider,
                    'helpful_count' => $providerFeedback->where('rating', 'helpful')->count(),
                    'bad_count' => $providerFeedback->where('rating', 'bad')->count(),
                    'helpful_rate' => round($providerFeedback->where('rating', 'helpful')->count() / $totalFeedback * 100, 1),
                    'bad_rate' => round($providerFeedback->where('rating', 'bad')->count() / $totalFeedback * 100, 1),
                    'positive_memory' => $rows->sum(fn ($row): int => (int) ($row->positive_count ?? 0)),
                    'negative_memory' => $rows->sum(fn ($row): int => (int) ($row->negative_count ?? 0)),
                    'source_gap_count' => $sourceGaps->filter(fn ($row): bool => in_array($provider, $this->decodeJson($row->provider_keys_json ?? '[]'), true))->count(),
                    'examples' => $providerFeedback->take(3)->map(fn ($row): array => [
                        'question' => $row->question,
                        'rating' => $row->rating,
                        'confidence' => $row->confidence,
                    ])->values()->all(),
                ];
            })
            ->values();

        return response()->json(['status' => 'success', 'data' => $providers]);
    }

    public function analyticsTrends(Request $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }

        $filters = $this->analyticsFilters($request);
        $messages = $this->filteredAssistantMessages($filters);
        $feedback = $this->filteredFeedback($filters);

        return response()->json([
            'status' => 'success',
            'data' => [
                'tokens' => $this->tokenTrend($messages),
                'feedback' => $feedback
                    ->groupBy(fn ($row): string => Carbon::parse($row->created_at ?? now())->format('Y-m-d'))
                    ->map(fn ($rows, string $date): array => [
                        'date' => $date,
                        'helpful' => $rows->where('rating', 'helpful')->count(),
                        'bad' => $rows->where('rating', 'bad')->count(),
                    ])
                    ->sortBy('date')
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function analyticsSourceGaps(Request $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }

        $filters = $this->analyticsFilters($request);

        return response()->json([
            'status' => 'success',
            'data' => $this->filteredSourceGaps($filters)->map(fn ($row): array => [
                'id' => (int) $row->id,
                'normalized_intent' => $row->normalized_intent,
                'sample_question' => $row->sample_question,
                'current_route' => $row->current_route,
                'provider_keys' => $this->decodeJson($row->provider_keys_json ?? '[]'),
                'source_types' => $this->decodeJson($row->source_types_json ?? '[]'),
                'confidence' => $row->confidence,
                'answer_mode' => $row->answer_mode,
                'occurrence_count' => (int) $row->occurrence_count,
                'status' => $row->status,
                'priority' => $row->priority ?? $this->gapPriority((int) $row->occurrence_count),
                'notes' => $row->notes,
                'actions' => $this->sourceGapActions((int) $row->id),
                'last_seen_at' => $row->last_seen_at,
            ])->values(),
        ]);
    }

    public function analyticsExport(Request $request)
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }

        $filters = $this->analyticsFilters($request);
        $rows = $this->filteredFeedback($filters);
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['id', 'rating', 'question', 'confidence', 'answer_mode', 'current_route', 'created_at']);
        foreach ($rows as $row) {
            fputcsv($csv, [
                $row->id,
                $row->rating,
                $row->question,
                $row->confidence,
                $row->answer_mode,
                $row->current_route,
                $row->created_at,
            ]);
        }
        rewind($csv);
        $content = stream_get_contents($csv) ?: '';
        fclose($csv);

        return response($content, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="assistant-feedback-export.csv"',
        ]);
    }

    public function promoteSourceGapProviderBacklog(Request $request, int $id): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }

        $gap = $this->sourceGapOrFail($id);
        if ($gap instanceof JsonResponse) {
            return $gap;
        }
        if (! Schema::hasTable('assistant_source_gap_actions')) {
            return response()->json(['status' => 'error', 'message' => 'Assistant source gap action storage is not ready.'], 503);
        }

        $data = $request->validate([
            'target_provider_key' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $providerKeys = $this->decodeJson($gap->provider_keys_json ?? '[]');
        $targetProvider = trim((string) ($data['target_provider_key'] ?? '')) ?: (string) ($providerKeys[0] ?? 'unassigned');

        $actionId = DB::table('assistant_source_gap_actions')->insertGetId([
            'source_gap_id' => $id,
            'action_type' => 'provider_backlog',
            'status' => 'open',
            'target_provider_key' => $targetProvider,
            'title' => $data['title'] ?? 'Improve provider coverage: '.$gap->normalized_intent,
            'notes' => $data['notes'] ?? $gap->notes,
            'created_by_staff_id' => (int) $request->session()->get('staff_id', 0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->markGapStatus($id, 'planned');

        return response()->json(['status' => 'success', 'action_id' => $actionId, 'message' => 'Provider backlog action created.']);
    }

    public function createSourceGapKnowledgeDraft(Request $request, int $id): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }

        $gap = $this->sourceGapOrFail($id);
        if ($gap instanceof JsonResponse) {
            return $gap;
        }
        if (! Schema::hasTable('assistant_source_gap_actions') || ! Schema::hasTable('knowledge_articles')) {
            return response()->json(['status' => 'error', 'message' => 'Knowledge/source gap storage is not ready.'], 503);
        }

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $title = trim((string) ($data['title'] ?? '')) ?: Str::headline((string) $gap->normalized_intent);
        $slug = $this->uniqueKnowledgeSlug($title);
        $body = $this->knowledgeDraftBody($gap, $data['notes'] ?? null);
        $staffId = (int) $request->session()->get('staff_id', 0);
        $nameCode = (string) $request->session()->get('name_code', 'AI-GOV');

        $articleId = DB::table('knowledge_articles')->insertGetId([
            'title' => $title,
            'slug' => $slug,
            'summary' => 'Draft created from repeated Learn Kijo AI source gap.',
            'body_html' => $body,
            'category' => 'AI Source Gaps',
            'tags' => json_encode(['ai-assistant', 'source-gap'], JSON_UNESCAPED_SLASHES),
            'related_route' => $gap->current_route,
            'contributor_note' => 'Generated as unpublished draft from AI Assistant Governance. Review before publishing.',
            'status' => 'draft',
            'published_at' => null,
            'created_by_staff_id' => $staffId ?: null,
            'created_by_name_code' => $nameCode,
            'updated_by_staff_id' => $staffId ?: null,
            'updated_by_name_code' => $nameCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actionId = DB::table('assistant_source_gap_actions')->insertGetId([
            'source_gap_id' => $id,
            'action_type' => 'knowledge_draft',
            'status' => 'open',
            'knowledge_article_id' => $articleId,
            'title' => $title,
            'notes' => $data['notes'] ?? $gap->notes,
            'created_by_staff_id' => $staffId ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->markGapStatus($id, 'planned');

        return response()->json([
            'status' => 'success',
            'action_id' => $actionId,
            'knowledge_article_id' => $articleId,
            'slug' => $slug,
            'message' => 'Knowledge draft created. Review and publish manually.',
        ]);
    }

    public function ignoreSourceGap(Request $request, int $id): JsonResponse
    {
        return $this->sourceGapActionStatus($request, $id, 'ignored', 'ignored');
    }

    public function resolveSourceGap(Request $request, int $id): JsonResponse
    {
        return $this->sourceGapActionStatus($request, $id, 'resolved', 'resolved');
    }

    public function unblockSignature(Request $request, string $signature): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }
        if (! Schema::hasTable('assistant_response_feedback')) {
            return response()->json(['status' => 'error', 'message' => 'Assistant feedback storage is not ready.'], 503);
        }

        DB::table('assistant_response_feedback')
            ->where('answer_signature', $signature)
            ->where('rating', 'bad')
            ->update(['answer_signature' => null, 'updated_at' => now()]);

        return response()->json(['status' => 'success', 'message' => 'Answer signature unblocked.']);
    }

    private function authorizeSystemAdmin(Request $request): ?JsonResponse
    {
        try {
            $userId = (int) $request->session()->get('user_id', 0);
            $staffId = (int) $request->session()->get('staff_id', 0);
            if ($userId <= 0 || $staffId <= 0) {
                throw new HttpException(403, 'Unauthorized. Please log in to continue.');
            }

            $user = Schema::hasTable('system_users')
                ? DB::table('system_users')->select(['staff_id', 'role', 'is_active'])->where('id', $userId)->first()
                : null;
            if (! $user || (int) $user->staff_id !== $staffId || ! (bool) $user->is_active) {
                throw new HttpException(403, 'Unauthorized. Please log in to continue.');
            }

            $roles = $this->decodeRoles($user->role ?? null);
            $isAdmin = in_array('system admin', array_map(
                static fn (mixed $role): string => strtolower(trim((string) $role)),
                $roles,
            ), true);
            if (! $isAdmin) {
                throw new HttpException(403, 'Unauthorized: System Admin only.');
            }
        } catch (HttpException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], $exception->getStatusCode());
        }

        return null;
    }

    private function decodeRoles(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return array_map('trim', explode(',', $raw));
    }

    private function tableRows(string $table)
    {
        if (! Schema::hasTable($table)) {
            return collect();
        }

        return DB::table($table)->get();
    }

    private function analyticsFilters(Request $request): array
    {
        return [
            'date_from' => $this->safeDate($request->query('date_from'), true),
            'date_to' => $this->safeDate($request->query('date_to'), false),
            'provider' => trim((string) $request->query('provider', '')),
            'confidence' => trim((string) $request->query('confidence', '')),
            'answer_mode' => trim((string) $request->query('answer_mode', '')),
            'rating' => trim((string) $request->query('rating', '')),
        ];
    }

    private function safeDate(mixed $value, bool $startOfDay): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);

            return $startOfDay ? $date->startOfDay() : $date->endOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function filteredFeedback(array $filters)
    {
        return $this->tableRows('assistant_response_feedback')
            ->filter(fn ($row): bool => $this->withinDate($row->created_at ?? null, $filters)
                && ($filters['rating'] === '' || (string) $row->rating === $filters['rating'])
                && ($filters['confidence'] === '' || (string) $row->confidence === $filters['confidence'])
                && ($filters['answer_mode'] === '' || (string) $row->answer_mode === $filters['answer_mode'])
                && ($filters['provider'] === '' || $this->rowHasProvider($row, $filters['provider'])));
    }

    private function filteredAssistantMessages(array $filters)
    {
        return $this->assistantMessages()
            ->filter(fn ($row): bool => $this->withinDate($row->created_at ?? null, $filters)
                && ($filters['confidence'] === '' || (string) $row->confidence === $filters['confidence'])
                && ($filters['answer_mode'] === '' || (string) ($this->messageMetadata($row)['answer_mode'] ?? '') === $filters['answer_mode'])
                && ($filters['provider'] === '' || in_array($filters['provider'], $this->messageProviders($row), true)));
    }

    private function filteredProviderMemory(array $filters)
    {
        return $this->tableRows('assistant_provider_feedback_memory')
            ->filter(fn ($row): bool => ($filters['provider'] === '' || (string) $row->provider_key === $filters['provider']));
    }

    private function filteredSourceGaps(array $filters)
    {
        return $this->tableRows('assistant_source_gaps')
            ->filter(fn ($row): bool => $this->withinDate($row->last_seen_at ?? $row->created_at ?? null, $filters)
                && ($filters['confidence'] === '' || (string) $row->confidence === $filters['confidence'])
                && ($filters['answer_mode'] === '' || (string) $row->answer_mode === $filters['answer_mode'])
                && ($filters['provider'] === '' || in_array($filters['provider'], $this->decodeJson($row->provider_keys_json ?? '[]'), true)));
    }

    private function withinDate(mixed $value, array $filters): bool
    {
        if (! $value) {
            return true;
        }

        $date = Carbon::parse($value);
        if ($filters['date_from'] && $date->lt($filters['date_from'])) {
            return false;
        }
        if ($filters['date_to'] && $date->gt($filters['date_to'])) {
            return false;
        }

        return true;
    }

    private function assistantMessages()
    {
        if (! Schema::hasTable('knowledge_assistant_messages')) {
            return collect();
        }

        return DB::table('knowledge_assistant_messages')
            ->where('role', 'assistant')
            ->get();
    }

    private function messageSources(object $row)
    {
        $metadata = $this->decodeJson($row->sources_json ?? '[]');
        if (array_is_list($metadata)) {
            return collect($metadata);
        }

        return collect(is_array($metadata['sources'] ?? null) ? $metadata['sources'] : []);
    }

    private function messageMetadata(object $row): array
    {
        $metadata = $this->decodeJson($row->sources_json ?? '[]');

        return array_is_list($metadata) ? ['sources' => $metadata] : $metadata;
    }

    private function aiStatusCounts($messages): array
    {
        $allowed = [
            'ok',
            'source_fallback',
            'usage_limit',
            'rate_limit',
            'not_configured',
            'temporary_unavailable',
            'generation_failed',
        ];
        $counts = array_fill_keys($allowed, 0);

        foreach ($messages as $row) {
            $metadata = $this->messageMetadata($row);
            $status = strtolower(trim((string) ($metadata['ai_status'] ?? 'ok'))) ?: 'ok';
            if (! in_array($status, $allowed, true)) {
                $status = 'generation_failed';
            }
            $counts[$status]++;
        }

        return $counts;
    }

    private function aiUnavailableCount(array $counts): int
    {
        return (int) ($counts['usage_limit'] ?? 0)
            + (int) ($counts['rate_limit'] ?? 0)
            + (int) ($counts['not_configured'] ?? 0)
            + (int) ($counts['temporary_unavailable'] ?? 0)
            + (int) ($counts['generation_failed'] ?? 0);
    }

    private function messageProviders(object $row): array
    {
        return $this->messageSources($row)
            ->map(fn (array $source): string => (string) ($source['provider_key'] ?? $source['source_type'] ?? $source['type'] ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function rowHasProvider(object $row, string $provider): bool
    {
        if ($provider === '') {
            return true;
        }

        return in_array($provider, $this->feedbackProviders($row), true);
    }

    private function feedbackProviders(object $row): array
    {
        $sources = $this->decodeJson($row->sources_json ?? '[]');

        return collect($sources)
            ->filter(fn ($source): bool => is_array($source))
            ->map(fn (array $source): string => (string) ($source['provider_key'] ?? $source['source_type'] ?? $source['type'] ?? ''))
            ->filter()
            ->flatMap(fn (string $provider): array => array_values(array_filter(array_map('trim', explode(',', $provider)))))
            ->unique()
            ->values()
            ->all();
    }

    private function sourceGapActions(int $gapId): array
    {
        if (! Schema::hasTable('assistant_source_gap_actions')) {
            return [];
        }

        return DB::table('assistant_source_gap_actions')
            ->where('source_gap_id', $gapId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'action_type' => $row->action_type,
                'status' => $row->status,
                'target_provider_key' => $row->target_provider_key,
                'knowledge_article_id' => $row->knowledge_article_id ? (int) $row->knowledge_article_id : null,
                'title' => $row->title,
                'notes' => $row->notes,
                'created_at' => $row->created_at,
            ])
            ->all();
    }

    private function sourceGapOrFail(int $id): mixed
    {
        if (! Schema::hasTable('assistant_source_gaps')) {
            return response()->json(['status' => 'error', 'message' => 'Assistant source gap storage is not ready.'], 503);
        }

        $gap = DB::table('assistant_source_gaps')->where('id', $id)->first();
        if (! $gap) {
            return response()->json(['status' => 'error', 'message' => 'Source gap not found.'], 404);
        }

        return $gap;
    }

    private function markGapStatus(int $id, string $status): void
    {
        DB::table('assistant_source_gaps')->where('id', $id)->update([
            'status' => $status,
            'updated_at' => now(),
        ]);
    }

    private function sourceGapActionStatus(Request $request, int $id, string $gapStatus, string $actionType): JsonResponse
    {
        if ($response = $this->authorizeSystemAdmin($request)) {
            return $response;
        }
        $gap = $this->sourceGapOrFail($id);
        if ($gap instanceof JsonResponse) {
            return $gap;
        }
        $this->markGapStatus($id, $gapStatus);
        if (Schema::hasTable('assistant_source_gap_actions')) {
            DB::table('assistant_source_gap_actions')->insert([
                'source_gap_id' => $id,
                'action_type' => $actionType,
                'status' => $actionType === 'resolved' ? 'done' : 'cancelled',
                'title' => Str::headline((string) $gap->normalized_intent),
                'notes' => $gap->notes,
                'created_by_staff_id' => (int) $request->session()->get('staff_id', 0),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['status' => 'success', 'message' => 'Source gap updated.']);
    }

    private function uniqueKnowledgeSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'ai-source-gap';
        $slug = $base;
        $suffix = 2;
        while (DB::table('knowledge_articles')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function knowledgeDraftBody(object $gap, ?string $notes): string
    {
        $providers = implode(', ', $this->decodeJson($gap->provider_keys_json ?? '[]')) ?: 'Unassigned';
        $sources = implode(', ', $this->decodeJson($gap->source_types_json ?? '[]')) ?: 'None';

        return '<h2>AI Source Gap Draft</h2>'
            .'<p>This unpublished draft was created from repeated Learn Kijo AI source gaps. Review and replace this outline with verified instructions before publishing.</p>'
            .'<h3>Sample question</h3><p>'.e((string) $gap->sample_question).'</p>'
            .'<h3>Normalized intent</h3><p>'.e((string) $gap->normalized_intent).'</p>'
            .'<h3>Route/module scope</h3><p>'.e((string) ($gap->current_route ?? 'Not captured')).'</p>'
            .'<h3>Failed source context</h3><ul><li>Providers: '.e($providers).'</li><li>Source types: '.e($sources).'</li><li>Confidence: '.e((string) $gap->confidence).'</li><li>Occurrences: '.e((string) $gap->occurrence_count).'</li></ul>'
            .'<h3>Suggested outline</h3><ol><li>Confirm the correct module or workflow.</li><li>Add verified step-by-step guidance.</li><li>List related records, permissions, and limitations.</li><li>Add troubleshooting notes.</li></ol>'
            .($notes ? '<h3>Admin notes</h3><p>'.e($notes).'</p>' : '');
    }

    private function gapPriority(int $occurrenceCount): string
    {
        if ($occurrenceCount >= 10) {
            return 'high';
        }
        if ($occurrenceCount >= 3) {
            return 'medium';
        }

        return 'low';
    }

    private function estimatedCost(int $inputTokens, int $outputTokens): array
    {
        $inputRate = config('services.knowledge_assistant.cost.input_per_million');
        $outputRate = config('services.knowledge_assistant.cost.output_per_million');
        if (! is_numeric($inputRate) || ! is_numeric($outputRate)) {
            return ['amount' => null, 'currency' => 'USD', 'known' => false];
        }

        return [
            'amount' => round(($inputTokens / 1_000_000 * (float) $inputRate) + ($outputTokens / 1_000_000 * (float) $outputRate), 6),
            'currency' => 'USD',
            'known' => true,
        ];
    }

    private function blockedSignatures(): array
    {
        if (! Schema::hasTable('assistant_response_feedback')) {
            return [];
        }

        return DB::table('assistant_response_feedback')
            ->where('rating', 'bad')
            ->whereNotNull('answer_signature')
            ->pluck('answer_signature')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function cacheRows(string $table, string $mode, array $blocked)
    {
        return $this->tableRows($table)->map(function ($row) use ($mode, $blocked): array {
            $answer = $this->decodeJson($row->answer_json ?? '{}');

            return [
                'id' => (int) $row->id,
                'mode' => $mode,
                'normalized_question' => $row->normalized_question ?? null,
                'answer_excerpt' => mb_substr((string) ($answer['answer_markdown'] ?? ''), 0, 300),
                'answer_mode' => $answer['answer_mode'] ?? $mode,
                'confidence' => $answer['confidence'] ?? null,
                'hit_count' => (int) ($row->hit_count ?? 0),
                'provider_key' => $row->provider_key ?? null,
                'answer_signature' => $row->answer_signature ?? null,
                'blocked' => isset($blocked[(string) ($row->answer_signature ?? '')]),
                'refreshed_at' => $row->refreshed_at ?? null,
                'expires_at' => $row->expires_at ?? null,
                'updated_at' => $row->updated_at ?? null,
            ];
        });
    }

    private function tokenTrend($assistantMessages): array
    {
        return $assistantMessages
            ->groupBy(fn ($row): string => Carbon::parse($row->created_at ?? now())->format('Y-m-d'))
            ->map(fn ($rows, string $date): array => [
                'date' => $date,
                'input_tokens' => $rows->sum(fn ($row): int => (int) ($row->input_tokens ?? 0)),
                'output_tokens' => $rows->sum(fn ($row): int => (int) ($row->output_tokens ?? 0)),
            ])
            ->sortBy('date')
            ->values()
            ->take(-14)
            ->values()
            ->all();
    }

    private function decodeJson(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
