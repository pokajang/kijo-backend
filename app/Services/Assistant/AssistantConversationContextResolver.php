<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AssistantConversationContextResolver
{
    private const MAX_ENTITIES = 12;

    public function __construct(private readonly AssistantText $text) {}

    public function resolve(int $threadId, string $question, string $currentRoute): array
    {
        $normalizedQuestion = $this->text->normalizeAssistantQueryTerms($question);
        $entities = $this->activeEntities($threadId);
        $hasFollowUpReference = $this->hasFollowUpReference($normalizedQuestion);
        $routeHasDetail = $this->routeHasDetail($currentRoute);
        $questionHasExactReference = $this->hasExactReference($normalizedQuestion);

        $matches = $hasFollowUpReference && ! $routeHasDetail && ! $questionHasExactReference
            ? $this->matchingEntities($entities, $normalizedQuestion)
            : [];

        $focus = $matches[0] ?? null;
        $ambiguous = count($matches) > 1 && $this->isAmbiguous($matches, $normalizedQuestion);
        $retrievalQuestion = $focus && ! $ambiguous
            ? $this->expandedQuestion($normalizedQuestion, $focus)
            : $normalizedQuestion;

        return [
            'original_question' => $question,
            'normalized_question' => $normalizedQuestion,
            'retrieval_question' => $retrievalQuestion,
            'conversation_focus' => $focus,
            'active_entities' => $entities,
            'context_confidence' => $focus && ! $ambiguous ? 'high' : ($hasFollowUpReference ? 'low' : 'none'),
            'clarification_needed' => $ambiguous,
            'clarification_question' => $ambiguous ? $this->clarificationQuestion($matches) : null,
            'clarification_options' => $ambiguous ? $this->clarificationOptions($matches) : [],
        ];
    }

    public function remember(int $threadId, string $question, array $answer, array $sources): void
    {
        if (! $this->tableReady()) {
            return;
        }

        $existing = $this->storedContext($threadId);
        $entities = is_array($existing['active_entities'] ?? null) ? $existing['active_entities'] : [];
        $answerSlugs = array_values(array_filter((array) ($answer['source_slugs'] ?? []), 'is_string'));
        if ($answerSlugs !== []) {
            $sources = array_values(array_filter(
                $sources,
                static fn (array $source): bool => in_array((string) ($source['slug'] ?? ''), $answerSlugs, true),
            ));
        }
        $messageId = (int) (DB::table('knowledge_assistant_messages')
            ->where('thread_id', $threadId)
            ->where('role', 'assistant')
            ->max('id') ?? 0);

        foreach ($sources as $source) {
            $entity = $this->entityFromSource($source, $messageId);
            if ($entity === null) {
                continue;
            }
            array_unshift($entities, $entity);
        }

        $entities = $this->dedupeEntities($entities);

        DB::table('knowledge_assistant_thread_contexts')->updateOrInsert(
            ['thread_id' => $threadId],
            [
                'thread_id' => $threadId,
                'context_json' => json_encode([
                    'active_entities' => $entities,
                    'topic_summary' => $this->topicSummary($question, $answer, $entities),
                    'latest_source_slugs' => array_slice(array_values(array_filter(array_map(
                        static fn (array $source): string => (string) ($source['slug'] ?? ''),
                        $sources,
                    ))), 0, 6),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'last_processed_message_id' => $messageId > 0 ? $messageId : null,
                'created_at' => $existing === [] ? now() : ($existing['created_at'] ?? now()),
                'updated_at' => now(),
            ],
        );
    }

    private function activeEntities(int $threadId): array
    {
        $entities = [];
        $stored = $this->storedContext($threadId);
        if (is_array($stored['active_entities'] ?? null)) {
            $entities = $stored['active_entities'];
        }

        $recent = $this->recentSourceEntities($threadId);

        return $this->dedupeEntities(array_merge($recent, $entities));
    }

    private function storedContext(int $threadId): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $row = DB::table('knowledge_assistant_thread_contexts')->where('thread_id', $threadId)->first();
        if (! $row) {
            return [];
        }

        $payload = json_decode((string) ($row->context_json ?? '{}'), true);
        if (! is_array($payload)) {
            return [];
        }
        $payload['created_at'] = $row->created_at ?? null;

        return $payload;
    }

    private function recentSourceEntities(int $threadId): array
    {
        if (! Schema::hasTable('knowledge_assistant_messages')) {
            return [];
        }

        $entities = [];
        $rows = DB::table('knowledge_assistant_messages')
            ->where('thread_id', $threadId)
            ->where('role', 'assistant')
            ->whereNotNull('sources_json')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row->sources_json ?? '[]'), true);
            $sources = is_array($metadata['sources'] ?? null)
                ? $metadata['sources']
                : (is_array($metadata) && array_is_list($metadata) ? $metadata : []);
            foreach ($sources as $source) {
                if (is_array($source)) {
                    $entity = $this->entityFromSource($source, (int) ($row->id ?? 0));
                    if ($entity !== null) {
                        $entities[] = $entity;
                    }
                }
            }
        }

        return $entities;
    }

    private function entityFromSource(array $source, int $messageId = 0): ?array
    {
        $slug = trim((string) ($source['slug'] ?? ''));
        $title = trim((string) ($source['title'] ?? ''));
        $sourceType = trim((string) ($source['source_type'] ?? $source['type'] ?? ''));
        if ($slug === '' || $title === '' || $sourceType === '') {
            return null;
        }

        $parts = explode(':', $slug);
        $codes = $this->codesFromText($title.' '.($source['excerpt'] ?? '').' '.$slug);
        $service = null;
        $entityType = null;
        $entityId = null;
        if ($sourceType === 'proposal_template' && count($parts) >= 3) {
            $service = $parts[1] ?? null;
            $entityId = $parts[2] ?? null;
        } elseif ($sourceType === 'quote_record' && count($parts) >= 3) {
            $service = $parts[1] ?? null;
            $entityId = $parts[2] ?? null;
        }

        if (str_starts_with($slug, 'proposal-template:')) {
            $entityType = 'service';
        } elseif (str_starts_with($slug, 'quote-record:') && ! str_contains($slug, ':list:') && ! str_contains($slug, ':ambiguous:')) {
            $entityType = 'quote';
        } elseif (in_array($sourceType, ['client', 'project', 'vendor', 'invoice', 'debtor', 'sales_inquiry', 'leave', 'task', 'staff'], true)) {
            $entityType = $sourceType;
        } else {
            $entityType = $sourceType;
        }

        return [
            'slug' => $slug,
            'source_type' => $sourceType,
            'entity_type' => $entityType,
            'title' => $title,
            'related_route' => $source['related_route'] ?? null,
            'service_key' => $service,
            'entity_id' => $entityId,
            'codes' => $codes,
            'keywords' => array_slice($this->text->tokens($title.' '.implode(' ', $codes)), 0, 12),
            'priority' => $this->entityPriority($sourceType, $slug),
            'last_seen_message_id' => $messageId,
            'last_seen_at' => now()->toIso8601String(),
        ];
    }

    private function matchingEntities(array $entities, string $question): array
    {
        $wantedType = $this->wantedEntityType($question);
        $ranked = [];
        foreach ($entities as $index => $entity) {
            if (! is_array($entity)) {
                continue;
            }
            $score = (int) ($entity['priority'] ?? 0) + max(0, 12 - $index);
            if ($index === 0) {
                $score += 35;
            }
            if ($wantedType !== null) {
                $score += ($entity['entity_type'] ?? '') === $wantedType ? 120 : -80;
            }
            if ($this->statusIntent($question) && ($entity['entity_type'] ?? '') === 'quote') {
                $score += 120;
            }
            if ($this->quoteCreationIntent($question) && ($entity['entity_type'] ?? '') === 'service') {
                $score += 160;
            }
            if ($this->quoteCreationIntent($question) && ($entity['entity_type'] ?? '') === 'quote') {
                $score -= 90;
            }
            foreach ($this->text->tokens($question) as $token) {
                if (in_array($token, $entity['keywords'] ?? [], true)) {
                    $score += 30;
                }
            }
            $entity['_match_score'] = $score;
            $ranked[] = ['entity' => $entity, 'score' => $score];
        }

        usort($ranked, static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_values(array_map(
            static fn (array $item): array => $item['entity'],
            array_filter($ranked, static fn (array $item): bool => ($item['score'] ?? 0) > 0),
        ));
    }

    private function expandedQuestion(string $question, array $focus): string
    {
        $contextTerms = array_values(array_filter(array_unique(array_merge(
            [(string) ($focus['title'] ?? '')],
            $focus['codes'] ?? [],
            ! empty($focus['service_key']) ? [(string) $focus['service_key']] : [],
            ! empty($focus['source_type']) ? [(string) $focus['source_type']] : [],
        ))));

        $prefix = $this->quoteCreationIntent($question)
            ? 'create quotation prepare quote pricing for '
            : '';

        return trim($question.' '.$prefix.implode(' ', $contextTerms));
    }

    private function hasFollowUpReference(string $question): bool
    {
        return (bool) preg_match('/\b(this|that|it|same|ini|itu|tadi|benda\s+ini|yang\s+tadi|the above|previous|earlier|how\s+about|what\s+about|this service|that service|service ini|this proposal|that proposal|proposal ini|this quote|that quote|quote ini|this record|that record|record ini|rekod ini|client ini|projek ini)\b/i', $question);
    }

    private function hasExactReference(string $question): bool
    {
        return (bool) preg_match('/\bQ(?=[A-Z0-9-]*\d)[A-Z0-9-]{2,}\b/i', $question)
            || (bool) preg_match('/\b[A-Z]{2,}[A-Z0-9]*(?:-[A-Z0-9]+)+\b/', $question);
    }

    private function routeHasDetail(string $currentRoute): bool
    {
        return (bool) preg_match('/(quoteId=|\/templates\/proposals\/[^\/]+\/\d+|\/\d+(?:\?|$))/i', $currentRoute);
    }

    private function wantedEntityType(string $question): ?string
    {
        if (preg_match('/\b(service|perkhidmatan|proposal|template)\b/i', $question)) {
            return 'service';
        }
        if (preg_match('/\b(handbook|policy|polisi|working\s+time|working\s+hour|office\s+hour|lunch\s+break|dress\s+code|attendance)\b/i', $question)) {
            return 'handbook';
        }
        if (preg_match('/\b(quote|quotation|sebut\s+harga|sebutharga|harga)\b/i', $question) && ! $this->quoteCreationIntent($question)) {
            return 'quote';
        }
        if (preg_match('/\b(client|pelanggan)\b/i', $question)) {
            return 'client';
        }
        if (preg_match('/\b(project|projek)\b/i', $question)) {
            return 'project';
        }
        if (preg_match('/\b(task|tugasan)\b/i', $question)) {
            return 'task';
        }
        if (preg_match('/\b(invoice|invois)\b/i', $question)) {
            return 'invoice';
        }
        if (preg_match('/\b(vendor|supplier|pembekal)\b/i', $question)) {
            return 'vendor';
        }
        if (preg_match('/\b(leave|cuti)\b/i', $question)) {
            return 'leave';
        }
        if (preg_match('/\b(staff|employee|pekerja)\b/i', $question)) {
            return 'staff';
        }

        return null;
    }

    private function quoteCreationIntent(string $question): bool
    {
        return (bool) preg_match('/\b(how\s+to\s+quote|quote\s+(this|that|the|for|ini)|prepare\s+(a\s+)?quot|create\s+(a\s+)?quot|send\s+(a\s+)?quot|price\s+(this|that|the|ini)|macam\s+mana\s+nak\s+quote|cara\s+quote|buat\s+quote|buat\s+sebut\s+harga)\b/i', $question);
    }

    private function statusIntent(string $question): bool
    {
        return (bool) preg_match('/\b(status|state|stage|progress|apa\s+status)\b/i', $question);
    }

    private function isAmbiguous(array $matches, string $question): bool
    {
        if (count($matches) < 2) {
            return false;
        }

        $wantedType = $this->wantedEntityType($question);
        $top = $matches[0];
        $second = $matches[1];
        if (($top['slug'] ?? '') === ($second['slug'] ?? '')) {
            return false;
        }

        $gap = abs((int) ($top['_match_score'] ?? 0) - (int) ($second['_match_score'] ?? 0));
        if ($wantedType !== null) {
            return ($top['entity_type'] ?? '') === ($second['entity_type'] ?? '') && $gap <= 100;
        }

        $isGenericFollowUp = (bool) preg_match('/\b(it|this|that|ini|itu|benda\s+ini|same|previous|earlier|the above)\b/i', $question);
        if (! $isGenericFollowUp) {
            return false;
        }

        return $gap <= 90;
    }

    private function clarificationQuestion(array $matches): string
    {
        $labels = array_values(array_unique(array_filter(array_map(
            static fn (array $entity): string => (string) ($entity['title'] ?? ''),
            array_slice($matches, 0, 3),
        ))));

        return 'Which previous item should I use for this follow-up: '.implode(', ', $labels).'?';
    }

    private function clarificationOptions(array $matches): array
    {
        $seen = [];
        $options = [];
        foreach ($matches as $entity) {
            if (! is_array($entity)) {
                continue;
            }
            $label = trim((string) ($entity['title'] ?? ''));
            $slug = trim((string) ($entity['slug'] ?? ''));
            if ($label === '' || $slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $options[] = [
                'label' => Str::limit($label, 80, ''),
                'source_slug' => $slug,
                'source_type' => (string) ($entity['source_type'] ?? ''),
                'related_route' => $entity['related_route'] ?? null,
                'reason' => (string) ($entity['entity_type'] ?? 'previous_item'),
            ];
            if (count($options) >= 3) {
                break;
            }
        }

        return $options;
    }

    private function dedupeEntities(array $entities): array
    {
        $seen = [];
        $deduped = [];
        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }
            $slug = trim((string) ($entity['slug'] ?? ''));
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $deduped[] = $entity;
            if (count($deduped) >= self::MAX_ENTITIES) {
                break;
            }
        }

        return $deduped;
    }

    private function entityPriority(string $sourceType, string $slug): int
    {
        if (str_starts_with($slug, 'proposal-template:')) {
            return 380;
        }
        if (str_starts_with($slug, 'quote-record:') && ! str_contains($slug, ':list:') && ! str_contains($slug, ':ambiguous:')) {
            return 360;
        }
        if (in_array($sourceType, ['project', 'client', 'vendor', 'invoice', 'debtor', 'sales_inquiry', 'leave', 'task', 'staff'], true)) {
            return 320;
        }

        return $sourceType === 'knowledge' ? 120 : 220;
    }

    private function codesFromText(string $text): array
    {
        if (! preg_match_all('/\b[A-Z]{2,}[A-Z0-9]*(?:-[A-Z0-9]+)*\b/', $text, $matches)) {
            return [];
        }

        return array_values(array_unique(array_slice(array_filter(
            $matches[0],
            static fn (string $code): bool => ! in_array($code, ['KIJO', 'CRM', 'AI'], true),
        ), 0, 8)));
    }

    private function topicSummary(string $question, array $answer, array $entities): string
    {
        $entityTitle = (string) ($entities[0]['title'] ?? '');
        $answerText = trim((string) ($answer['answer_markdown'] ?? ''));

        return Str::limit(trim($question.' '.$entityTitle.' '.$answerText), 500, '');
    }

    private function tableReady(): bool
    {
        return Schema::hasTable('knowledge_assistant_thread_contexts');
    }
}
