<?php

namespace App\Services\Assistant;

class ProposalTemplateMatcher
{
    private const DIRECT_SCORE = 180;
    private const RESOLVE_THRESHOLD = 70;
    private const AMBIGUITY_GAP = 25;

    private const STOP_TOKENS = [
        'about' => true,
        'article' => true,
        'content' => true,
        'detail' => true,
        'explain' => true,
        'inside' => true,
        'proposal' => true,
        'service' => true,
        'template' => true,
        'tell' => true,
    ];

    public function __construct(private readonly AssistantText $text) {}

    public function resolve(string $question, array $rows): array
    {
        $ranked = $this->rankedMatches($question, $rows);
        if ($ranked === []) {
            return ['status' => 'none', 'row' => null, 'matches' => []];
        }

        $exactCode = array_values(array_filter(
            $ranked,
            static fn (array $match): bool => ($match['match_kind'] ?? '') === 'exact_code',
        ));
        if ($exactCode !== []) {
            return count($exactCode) === 1
                ? ['status' => 'resolved', 'row' => $exactCode[0]['row'], 'matches' => [$exactCode[0]['row']], 'score' => $exactCode[0]['score']]
                : ['status' => 'ambiguous', 'row' => null, 'matches' => array_column(array_slice($exactCode, 0, 5), 'row')];
        }

        $exactTitle = array_values(array_filter(
            $ranked,
            static fn (array $match): bool => ($match['match_kind'] ?? '') === 'exact_title',
        ));
        if ($exactTitle !== []) {
            return count($exactTitle) === 1
                ? ['status' => 'resolved', 'row' => $exactTitle[0]['row'], 'matches' => [$exactTitle[0]['row']], 'score' => $exactTitle[0]['score']]
                : ['status' => 'ambiguous', 'row' => null, 'matches' => array_column(array_slice($exactTitle, 0, 5), 'row')];
        }

        $top = $ranked[0];
        if (($top['score'] ?? 0) < self::RESOLVE_THRESHOLD) {
            return ['status' => 'none', 'row' => null, 'matches' => array_column(array_slice($ranked, 0, 5), 'row')];
        }

        $secondScore = $ranked[1]['score'] ?? 0;
        if ($secondScore > 0 && (($top['score'] ?? 0) - $secondScore) < self::AMBIGUITY_GAP) {
            return ['status' => 'ambiguous', 'row' => null, 'matches' => array_column(array_slice($ranked, 0, 5), 'row')];
        }

        return ['status' => 'resolved', 'row' => $top['row'], 'matches' => [$top['row']], 'score' => $top['score']];
    }

    public function rankedMatches(string $question, array $rows): array
    {
        $questionKey = $this->normalized($question);
        $questionTokens = $this->significantTokens($question);
        $questionTokenMap = array_flip($questionTokens);
        $questionPhrase = implode(' ', $questionTokens);
        $ranked = [];

        foreach ($rows as $row) {
            $titleKey = $this->normalized((string) ($row['_assistant_title'] ?? ''));
            $codeKeys = array_values(array_filter(array_map(
                fn (string $identifier): string => $this->normalized($identifier),
                (array) ($row['_assistant_identifiers'] ?? [$row['_assistant_code'] ?? '']),
            )));
            $serviceTypeKey = $this->normalized((string) ($row['_assistant_service_type'] ?? ''));
            $searchKey = $this->normalized((string) ($row['_assistant_search'] ?? ''));
            $score = 0;
            $kind = 'content';

            foreach ($codeKeys as $codeKey) {
                if ($codeKey !== '' && $this->containsPhrase($questionKey, $codeKey)) {
                    $score += self::DIRECT_SCORE;
                    $kind = 'exact_code';
                    break;
                }
            }

            if ($kind !== 'exact_code' && $titleKey !== '' && $this->containsPhrase($questionKey, $titleKey)) {
                $score += self::DIRECT_SCORE;
                $kind = 'exact_title';
            }

            if ($questionPhrase !== '') {
                if ($titleKey !== '' && $this->containsPhrase($titleKey, $questionPhrase)) {
                    $score += 120;
                    $kind = $kind === 'content' ? 'title' : $kind;
                } elseif ($serviceTypeKey !== '' && $this->containsPhrase($serviceTypeKey, $questionPhrase)) {
                    $score += 80;
                    $kind = $kind === 'content' ? 'service_type' : $kind;
                } elseif ($searchKey !== '' && $this->containsPhrase($searchKey, $questionPhrase)) {
                    $score += 80;
                }
            }

            $titleTokens = array_flip($this->significantTokens((string) ($row['_assistant_title'] ?? '')));
            $searchTokens = array_flip($this->significantTokens((string) ($row['_assistant_search'] ?? '')));
            $titleMatches = 0;
            $searchMatches = 0;
            foreach ($questionTokenMap as $token => $_) {
                if (isset($titleTokens[$token])) {
                    $titleMatches++;
                    $score += 20;
                    $kind = $kind === 'content' ? 'title' : $kind;
                } elseif (isset($searchTokens[$token])) {
                    $searchMatches++;
                    $score += 10;
                }
            }

            if ($questionTokens !== [] && $titleMatches > 0) {
                $coverage = $titleMatches / max(1, count($questionTokens));
                if ($coverage >= 0.6) {
                    $score += 60;
                }
            }

            if ($score > 0 && ($titleMatches + $searchMatches) > 1) {
                $score += min(30, ($titleMatches + $searchMatches) * 5);
            }

            if ($score > 0) {
                $ranked[] = ['row' => $row, 'score' => $score, 'match_kind' => $kind];
            }
        }

        usort($ranked, static function (array $a, array $b): int {
            $scoreCompare = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            $priority = ['exact_code' => 4, 'exact_title' => 3, 'title' => 2, 'service_type' => 1, 'content' => 0];

            return ($priority[$b['match_kind'] ?? 'content'] ?? 0) <=> ($priority[$a['match_kind'] ?? 'content'] ?? 0);
        });

        return $ranked;
    }

    private function significantTokens(string $value): array
    {
        return array_values(array_filter(
            $this->text->tokens($value),
            static fn (string $token): bool => strlen($token) >= 2 && ! isset(self::STOP_TOKENS[$token]),
        ));
    }

    private function containsPhrase(string $haystack, string $needle): bool
    {
        if ($haystack === '' || $needle === '') {
            return false;
        }

        return str_contains(" {$haystack} ", " {$needle} ");
    }

    private function normalized(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/i', ' ', strtolower($value)));
    }
}
