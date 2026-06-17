<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextProvider;
use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantProviderAuditMetadata;
use App\Services\Assistant\AssistantRetrievalPlan;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\PlannedAssistantContextProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HandbookContextProvider implements AssistantContextProvider, PlannedAssistantContextProvider, AssistantProviderAuditMetadata
{
    private const MAX_SOURCE_EXCERPT_LENGTH = 2500;

    public function __construct(private readonly AssistantText $text) {}

    public function key(): string
    {
        return 'handbook';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return Schema::hasTable('hr_handbook_versions')
            && ($this->questionLooksRelevant($question) || str_starts_with(trim($currentRoute), '/handbook'));
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        return $this->retrieveForQuestion($question, $currentRoute);
    }

    public function supportsPlan(AssistantRetrievalPlan $plan, string $question, string $currentRoute, Request $request): bool
    {
        return $plan->hasDomain($this->key()) && Schema::hasTable('hr_handbook_versions');
    }

    public function retrievePlanned(AssistantRetrievalPlan $plan, string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        return $this->retrieveForQuestion($plan->expandedQuestion($question), $currentRoute);
    }

    public function auditMetadata(): array
    {
        return [
            'provider_key' => $this->key(),
            'supported_routes' => ['/handbook'],
            'exact_ref_support' => false,
            'detail_route_support' => false,
            'list_support' => false,
            'sanitizer_coverage' => 'not-applicable',
            'source_status_metadata' => 'not-applicable',
            'permission_scope' => 'published handbook only',
            'smoke_sample' => 'what is working time in amiosh',
            'tests_present' => 'covered',
            'classification' => 'not-applicable',
        ];
    }

    private function retrieveForQuestion(string $question, string $currentRoute): AssistantContextResult
    {
        $version = DB::table('hr_handbook_versions')
            ->where('is_current', 1)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->first();

        if (! $version) {
            return AssistantContextResult::empty($this->key());
        }

        $content = json_decode((string) ($version->content_json ?? '{}'), true);
        if (! is_array($content)) {
            return AssistantContextResult::empty($this->key());
        }

        $queryTokens = $this->text->tokens($question);
        if ($queryTokens === [] && ! str_starts_with(trim($currentRoute), '/handbook')) {
            return AssistantContextResult::empty($this->key());
        }

        $sections = $this->flattenHandbookSections($content);
        $sources = collect($sections)
            ->map(fn (array $section): array => $this->scoreSection($section, $queryTokens, $question, $version, $currentRoute))
            ->filter(fn (array $source): bool => ($source['score'] ?? 0) > 0)
            ->sortByDesc('score')
            ->take(2)
            ->values()
            ->all();

        return new AssistantContextResult($sources, 'static', null, [$this->key()]);
    }

    private function questionLooksRelevant(string $question): bool
    {
        $tokens = array_flip($this->text->tokens($question));

        foreach ([
            'handbook', 'policy', 'policie', 'leave', 'claim', 'attendance', 'medical', 'staff',
            'employee', 'hr', 'cuti', 'pekerja', 'staf', 'peraturan', 'polisi',
            'working', 'hour', 'time', 'office', 'lunch', 'break', 'dress', 'code',
        ] as $token) {
            if (isset($tokens[$token])) {
                return true;
            }
        }

        return false;
    }

    private function flattenHandbookSections(array $content): array
    {
        $sections = [];
        $chapters = is_array($content['chapters'] ?? null) ? $content['chapters'] : [];

        foreach ($chapters as $chapterIndex => $chapter) {
            if (! is_array($chapter)) {
                continue;
            }

            $chapterTitle = (string) ($chapter['title'] ?? 'Handbook chapter');
            $chapterSections = is_array($chapter['sections'] ?? null) ? $chapter['sections'] : [];

            if ($chapterSections === []) {
                $sections[] = [
                    'id' => (string) ($chapter['id'] ?? 'chapter-'.$chapterIndex),
                    'title' => $chapterTitle,
                    'chapter_title' => $chapterTitle,
                    'body' => $this->textFromNode($chapter),
                ];

                continue;
            }

            foreach ($chapterSections as $sectionIndex => $section) {
                if (! is_array($section)) {
                    continue;
                }

                $sections[] = [
                    'id' => (string) ($section['id'] ?? 'chapter-'.$chapterIndex.'-section-'.$sectionIndex),
                    'title' => (string) ($section['title'] ?? $chapterTitle),
                    'chapter_title' => $chapterTitle,
                    'body' => $this->textFromNode($section),
                ];
            }
        }

        return $sections;
    }

    private function textFromNode(array $node): string
    {
        $parts = [];
        foreach (['title', 'summary', 'body', 'bodyHtml', 'html', 'content', 'text'] as $key) {
            if (isset($node[$key]) && is_string($node[$key])) {
                $parts[] = in_array($key, ['bodyHtml', 'html'], true)
                    ? $this->text->plainTextFromHtml($node[$key])
                    : $node[$key];
            }
        }

        foreach (['items', 'paragraphs', 'rules', 'children'] as $key) {
            if (! is_array($node[$key] ?? null)) {
                continue;
            }
            foreach ($node[$key] as $item) {
                if (is_string($item)) {
                    $parts[] = $item;
                } elseif (is_array($item)) {
                    $parts[] = $this->textFromNode($item);
                }
            }
        }

        return $this->text->normalizePlainText(implode(' ', array_filter($parts)));
    }

    private function scoreSection(array $section, array $queryTokens, string $question, object $version, string $currentRoute): array
    {
        $titleTokens = $this->text->tokens($section['title']);
        $chapterTokens = $this->text->tokens($section['chapter_title']);
        $bodyTokens = $this->text->tokens($section['body']);
        $questionKey = $this->normalized($question);
        $titleKey = $this->normalized($section['title']);
        $chapterKey = $this->normalized($section['chapter_title']);
        $bodyKey = $this->normalized($section['body']);
        $routeMatches = str_starts_with(trim($currentRoute), '/handbook');

        $score = $routeMatches ? 220 : 0;
        $specificMatches = 0;
        foreach ($this->importantPhrases($questionKey) as $phrase) {
            if ($this->containsPhrase($titleKey, $phrase)) {
                $score += 520;
                $specificMatches++;
            } elseif ($this->containsPhrase($chapterKey, $phrase)) {
                $score += 420;
                $specificMatches++;
            } elseif ($this->containsPhrase($bodyKey, $phrase)) {
                $score += 360;
                $specificMatches++;
            }
        }

        foreach ($queryTokens as $token) {
            if (in_array($token, $titleTokens, true)) {
                $score += 260;
                $specificMatches += $this->text->isActionToken($token) ? 0 : 1;
            } elseif (in_array($token, $chapterTokens, true)) {
                $score += 180;
                $specificMatches += $this->text->isActionToken($token) ? 0 : 1;
            } elseif (in_array($token, $bodyTokens, true)) {
                $score += 90;
                $specificMatches += $this->text->isActionToken($token) ? 0 : 1;
            }
        }

        if (! $routeMatches && $specificMatches === 0) {
            $score = 0;
        }

        $sectionId = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($section['id'])) ?: 'section';
        $slug = 'handbook:'.$version->id.':'.$sectionId;

        return [
            'id' => (int) $version->id,
            'type' => 'handbook',
            'source_type' => 'handbook',
            'title' => 'Handbook: '.$section['title'],
            'slug' => $slug,
            'summary' => trim((string) $section['chapter_title']),
            'category' => 'Handbook',
            'related_route' => '/handbook',
            'excerpt' => $this->text->excerpt($section['body'], self::MAX_SOURCE_EXCERPT_LENGTH),
            'fingerprint' => sha1(implode('|', [
                'handbook',
                (string) $version->id,
                (string) ($version->updated_at ?? ''),
                (string) ($section['id'] ?? ''),
            ])),
            'score' => $score,
            'supported_intent' => 'policy_question',
            'intent_tags' => ['policy_question', 'handbook'],
        ];
    }

    private function importantPhrases(string $questionKey): array
    {
        $phrases = [];
        foreach ([
            'working time',
            'working hour',
            'office hour',
            'lunch break',
            'dress code',
        ] as $phrase) {
            if ($this->containsPhrase($questionKey, $phrase)) {
                $phrases[] = $phrase;
            }
        }

        return $phrases;
    }

    private function containsPhrase(string $haystack, string $needle): bool
    {
        return $haystack !== '' && $needle !== '' && str_contains(" {$haystack} ", " {$needle} ");
    }

    private function normalized(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/i', ' ', strtolower($value)));
    }
}
