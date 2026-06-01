<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextProvider;
use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KnowledgeArticleContextProvider implements AssistantContextProvider
{
    private const MAX_SOURCE_EXCERPT_LENGTH = 2500;

    public function __construct(private readonly AssistantText $text) {}

    public function key(): string
    {
        return 'knowledge';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return Schema::hasTable('knowledge_articles');
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        return new AssistantContextResult(
            $this->rankArticles($question, $currentRoute),
            'static',
            null,
            [$this->key()],
        );
    }

    public function rankArticles(string $question, string $currentRoute = '', int $limit = 3): array
    {
        if (! Schema::hasTable('knowledge_articles')) {
            return [];
        }

        $queryTokens = $this->text->tokens($question);
        $currentRoute = trim($currentRoute);
        if ($queryTokens === [] && $currentRoute === '') {
            return [];
        }

        return DB::table('knowledge_articles')
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->select(['id', 'title', 'slug', 'summary', 'body_html', 'category', 'tags', 'related_route', 'updated_at'])
            ->limit(200)
            ->get()
            ->map(fn ($article) => $this->articleCandidate($article, $queryTokens, $currentRoute))
            ->filter(fn (array $candidate): bool => $candidate['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    private function articleCandidate(object $article, array $queryTokens, string $currentRoute): array
    {
        $tags = json_decode((string) ($article->tags ?? '[]'), true);
        $tagsText = is_array($tags) ? implode(' ', array_filter($tags, 'is_string')) : '';
        $bodyText = $this->text->plainTextFromHtml((string) ($article->body_html ?? ''));
        $articleText = trim(implode(' ', [
            (string) ($article->title ?? ''),
            $tagsText,
            (string) ($article->summary ?? ''),
            $bodyText,
        ]));
        if ($this->isQuoteCreationIntent($queryTokens) && ! $this->isNegotiationIntent($queryTokens) && $this->isNegotiationArticle($articleText)) {
            return $this->formatCandidate($article, $bodyText, 0);
        }
        $routeMatches = $currentRoute !== '' && (string) ($article->related_route ?? '') === $currentRoute;
        $weightedFields = [
            ['text' => $article->title ?? '', 'weight' => 260],
            ['text' => $tagsText, 'weight' => 220],
            ['text' => $article->related_route ?? '', 'weight' => 200],
            ['text' => $article->category ?? '', 'weight' => 150],
            ['text' => $article->summary ?? '', 'weight' => 130],
            ['text' => $bodyText, 'weight' => 70],
        ];

        $score = $routeMatches ? 500 : 0;
        $matchedTokens = 0;
        $specificMatches = 0;
        foreach ($queryTokens as $token) {
            $best = 0;
            foreach ($weightedFields as $field) {
                $fieldTokens = $this->text->tokens((string) $field['text']);
                if (in_array($token, $fieldTokens, true)) {
                    $best = max($best, $field['weight']);
                } elseif (strlen($token) >= 4 && collect($fieldTokens)->contains(fn (string $fieldToken): bool => str_starts_with($fieldToken, $token))) {
                    $best = max($best, (int) floor($field['weight'] * 0.75));
                }
            }
            if ($best === 0) {
                continue;
            }
            $matchedTokens += 1;
            if (! $this->text->isActionToken($token)) {
                $specificMatches += 1;
            }
            $score += $best;
        }

        if (! $routeMatches && ($matchedTokens === 0 || $specificMatches === 0)) {
            return $this->formatCandidate($article, $bodyText, 0);
        }

        return $this->formatCandidate($article, $bodyText, $score);
    }

    private function isQuoteCreationIntent(array $queryTokens): bool
    {
        return in_array('quote', $queryTokens, true)
            || in_array('quotation', $queryTokens, true)
            || in_array('pricing', $queryTokens, true)
            || in_array('price', $queryTokens, true);
    }

    private function isNegotiationIntent(array $queryTokens): bool
    {
        return count(array_intersect($queryTokens, [
            'negotiate',
            'negotiation',
            'negotiations',
            'discount',
            'approval',
            'approved',
            'requested',
        ])) > 0;
    }

    private function isNegotiationArticle(string $articleText): bool
    {
        $tokens = $this->text->tokens($articleText);

        return in_array('negotiation', $tokens, true) || in_array('negotiations', $tokens, true);
    }

    private function formatCandidate(object $article, string $bodyText, int $score): array
    {
        $metadata = $this->intentMetadata($article, $bodyText);

        return array_merge([
            'id' => (int) $article->id,
            'type' => 'knowledge',
            'source_type' => 'knowledge',
            'title' => (string) $article->title,
            'slug' => (string) $article->slug,
            'summary' => (string) ($article->summary ?? ''),
            'category' => (string) ($article->category ?? ''),
            'related_route' => $article->related_route,
            'excerpt' => $this->text->excerpt($bodyText, self::MAX_SOURCE_EXCERPT_LENGTH),
            'fingerprint' => sha1(implode('|', [
                'knowledge',
                (string) $article->id,
                (string) ($article->updated_at ?? ''),
            ])),
            'score' => $score,
        ], $metadata);
    }

    private function intentMetadata(object $article, string $bodyText): array
    {
        $tags = json_decode((string) ($article->tags ?? '[]'), true);
        $tagsText = is_array($tags) ? implode(' ', array_filter($tags, 'is_string')) : '';
        $text = strtolower(trim(implode(' ', [
            (string) ($article->title ?? ''),
            (string) ($article->summary ?? ''),
            (string) ($article->category ?? ''),
            (string) ($article->related_route ?? ''),
            $tagsText,
            $bodyText,
        ])));
        $intentTags = ['how_to'];
        $conflicts = [];

        if (preg_match('/\b(negotiate|negotiation|negotiations|discount|requested final total|approval)\b/i', $text)) {
            $intentTags[] = 'quote_negotiation';
            $conflicts[] = 'quote_creation';
        }

        if (preg_match('/\b(create|prepare|send|quote|quotation|sebut harga|sebutharga)\b/i', $text) && ! in_array('quote_negotiation', $intentTags, true)) {
            $intentTags[] = 'quote_creation';
        }

        if (preg_match('/\b(handbook|policy|working time|working hour|office hour|lunch break|dress code|attendance)\b/i', $text)) {
            $intentTags[] = 'policy_question';
        }

        if (preg_match('/\b(dashboard|metric|stats|statistics|sales|conversion|workload)\b/i', $text)) {
            $intentTags[] = 'metric_question';
        }

        return [
            'supported_intent' => $intentTags[0],
            'intent_tags' => array_values(array_unique($intentTags)),
            'intent_conflicts' => array_values(array_unique($conflicts)),
        ];
    }
}
