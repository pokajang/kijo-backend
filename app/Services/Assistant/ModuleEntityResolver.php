<?php

namespace App\Services\Assistant;

class ModuleEntityResolver
{
    public function __construct(private readonly AssistantText $text) {}

    public function resolve(
        string $question,
        string $currentRoute,
        array $rows,
        string $idField,
        string $nameField,
        array $searchFields,
        array $routePatterns = [],
    ): array {
        $routeId = $this->routeId($currentRoute, $routePatterns);
        if ($routeId > 0) {
            foreach ($rows as $row) {
                if ((int) ($row[$idField] ?? 0) === $routeId) {
                    return ['status' => 'resolved', 'row' => $row, 'matches' => [$row], 'score' => 1000];
                }
            }
        }

        $ranked = $this->rankedMatches($question, $rows, $nameField, $searchFields);
        if ($ranked === [] || ($ranked[0]['score'] ?? 0) < 35) {
            return ['status' => 'none', 'row' => null, 'matches' => array_column(array_slice($ranked, 0, 5), 'row')];
        }

        $top = $ranked[0];
        $secondScore = $ranked[1]['score'] ?? 0;
        if ($secondScore > 0 && $top['score'] - $secondScore < 12) {
            return ['status' => 'ambiguous', 'row' => null, 'matches' => array_column(array_slice($ranked, 0, 5), 'row')];
        }

        return ['status' => 'resolved', 'row' => $top['row'], 'matches' => [$top['row']], 'score' => $top['score']];
    }

    public function rankedMatches(string $question, array $rows, string $nameField, array $searchFields): array
    {
        $questionKey = $this->normalized($question);
        $questionTokens = array_flip($this->text->tokens($question));
        $ranked = [];

        foreach ($rows as $row) {
            $name = (string) ($row[$nameField] ?? '');
            $haystack = implode(' ', array_map(fn (string $field): string => $this->fieldText($row[$field] ?? ''), $searchFields));
            $haystackKey = $this->normalized($haystack);
            $nameKey = $this->normalized($name);
            $score = 0;

            if ($nameKey !== '' && str_contains($questionKey, $nameKey)) {
                $score += 90;
            }
            if ($questionKey !== '' && $nameKey !== '' && str_contains($nameKey, $questionKey)) {
                $score += 65;
            }

            foreach ($this->text->tokens($haystack) as $token) {
                if (isset($questionTokens[$token])) {
                    $score += str_contains($this->normalized($name), $token) ? 18 : 8;
                }
            }

            if ($score > 0) {
                $ranked[] = ['row' => $row, 'score' => $score];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return $ranked;
    }

    public function routeId(string $currentRoute, array $patterns): int
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $currentRoute, $matches)) {
                return (int) ($matches[1] ?? 0);
            }
        }

        return 0;
    }

    private function normalized(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/i', ' ', strtolower($value)));
    }

    private function fieldText(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' ', array_map(fn ($item): string => $this->fieldText($item), $value));
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
