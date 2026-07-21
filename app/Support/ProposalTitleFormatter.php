<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class ProposalTitleFormatter
{
    private const FALLBACK_TITLE = 'Proposal';
    private const MAX_TITLE_LENGTH = 180;

    private const STOP_WORDS = [
        'AND' => 'And',
        'OR' => 'Or',
        'OF' => 'Of',
        'FOR' => 'For',
        'IN' => 'In',
        'AT' => 'At',
        'ON' => 'On',
        'BY' => 'By',
        'WITH' => 'With',
        'FROM' => 'From',
        'TO' => 'To',
        'AS' => 'As',
        'A' => 'A',
        'AN' => 'An',
        'THE' => 'The',
        'PER' => 'Per',
        'AND/OR' => 'And/Or',
        'I' => 'I',
    ];

    private const ACRONYMS = [
        'HIRARC' => 'HIRARC',
        'HAZMAT' => 'HAZMAT',
        'SHO' => 'SHO',
        'SSS' => 'SSS',
        'OSHA' => 'OSHA',
        'ISO' => 'ISO',
        'HSE' => 'HSE',
        'PPE' => 'PPE',
        'BPM' => 'BPM',
        'JSA' => 'JSA',
        'RAM' => 'RAM',
        'MSD' => 'MSD',
        'MSA' => 'MSA',
        'MSDS' => 'MSDS',
        'QA' => 'QA',
        'QC' => 'QC',
        'HIRA' => 'HIRA',
        'JHA' => 'JHA',
    ];

    public static function formatProposalTitle(
        string $rawTitle,
        ?string $fallback = self::FALLBACK_TITLE,
        string $suffix = '',
        string $context = ''
    ): string {
        $fallback = $fallback === null ? null : trim((string) $fallback);
        if ($fallback === '') {
            $fallback = self::FALLBACK_TITLE;
        }

        $normalized = self::safeNormalize((string) $rawTitle, $context, $fallback);
        if ($normalized !== '') {
            return self::appendSuffix($normalized, $suffix);
        }

        if ($fallback === null) {
            return '';
        }

        return self::appendSuffix(self::normalizeFallback($fallback), $suffix);
    }

    public static function normalize(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim((string) $value));
        if (!is_string($value) || $value === '') {
            return '';
        }

        $parts = preg_split('/(\s+)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $value;
        }

        $normalized = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\s+$/u', $part)) {
                $normalized .= $part;
            } else {
                $normalized .= self::normalizeToken((string) $part);
            }
        }

        return trim($normalized);
    }

    public static function removeSuffix(string $value, string $suffix): string
    {
        $value = trim((string) $value);
        if ($value === '' || $suffix === '') {
            return $value;
        }

        return trim((string) preg_replace(
            '/\s*' . preg_quote($suffix, '/') . '\s*$/iu',
            '',
            $value
        ));
    }

    public static function withSuffix(string $value, string $suffix): string
    {
        $value = self::normalize($value);
        $suffix = trim((string) $suffix);

        if ($value === '' || $suffix === '') {
            return $value !== '' ? $value : $suffix;
        }
        if (self::hasSuffix($value, $suffix)) {
            return $value;
        }

        return $value . ' ' . $suffix;
    }

    private static function safeNormalize(string $value, string $context, ?string $fallback): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            $normalized = self::normalize($value);
            if (self::isSafeTitle($normalized)) {
                return $normalized;
            }

            $fallbackNormalized = self::sanitizeRawFallback($value, $fallback);
            if (self::isSafeTitle($fallbackNormalized)) {
                return $fallbackNormalized;
            }

            return '';
        } catch (\Throwable $e) {
            self::logFormattingFailure($context, $value, $fallback, $e);
            return $fallback === null ? '' : self::normalizeFallback($fallback);
        }
    }

    private static function sanitizeRawFallback(string $value, ?string $fallback): string
    {
        $fallback = $fallback === null ? null : trim((string) $fallback);
        if ($fallback === '') {
            $fallback = null;
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        if ($clean === null || $clean === '') {
            return $fallback ?? '';
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $clean));
        if (!is_string($clean) || $clean === '') {
            return $fallback ?? '';
        }

        if (mb_strlen($clean, 'UTF-8') > self::MAX_TITLE_LENGTH) {
            $clean = mb_substr($clean, 0, self::MAX_TITLE_LENGTH, 'UTF-8');
            $clean = trim((string) preg_replace('/\s+\S*$/u', '', $clean));
            if ($clean === '') {
                $clean = mb_substr((string) $value, 0, self::MAX_TITLE_LENGTH, 'UTF-8');
            }
        }

        if ($clean === '') {
            return $fallback ?? '';
        }

        try {
            $normalized = self::normalize($clean);
            return self::isSafeTitle($normalized) ? $normalized : ($fallback ?? '');
        } catch (\Throwable $e) {
            self::logFormattingFailure('sanitize', $value, $fallback, $e);
            return $fallback ?? '';
        }
    }

    private static function normalizeFallback(string $fallback): string
    {
        try {
            $normalized = self::normalize($fallback);
            return self::isSafeTitle($normalized) ? $normalized : self::FALLBACK_TITLE;
        } catch (\Throwable) {
            return self::FALLBACK_TITLE;
        }
    }

    private static function isSafeTitle(string $title): bool
    {
        if ($title === '' || trim((string) $title) === '') {
            return false;
        }

        if (mb_strlen($title, 'UTF-8') > self::MAX_TITLE_LENGTH) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', (string) $title)) {
            return false;
        }

        if (preg_match('/\p{Sc}|\p{So}/u', (string) $title)) {
            return false;
        }

        return true;
    }

    private static function appendSuffix(string $title, string $suffix): string
    {
        $title = trim((string) $title);
        $suffix = trim((string) $suffix);
        if ($suffix === '') {
            return $title;
        }

        return self::withSuffix($title, $suffix);
    }

    private static function hasSuffix(string $value, string $suffix): bool
    {
        return preg_match(
            '/\s*' . preg_quote($suffix, '/') . '\s*$/iu',
            $value
        ) === 1;
    }

    private static function logFormattingFailure(string $context, string $value, ?string $fallback, \Throwable $e): void
    {
        Log::warning('Proposal title formatting failed, using fallback', [
            'context' => $context !== '' ? $context : 'unknown',
            'input_length' => strlen($value),
            'fallback' => $fallback ?? self::FALLBACK_TITLE,
            'exception' => $e->getMessage(),
            'error_class' => get_class($e),
        ]);
    }

    private static function normalizeToken(string $token): string
    {
        if (!preg_match('/\pL/u', $token)) {
            return $token;
        }

        if (!preg_match('/^([^\pL\pN]*)(.*?)([^\pL\pN]*)$/u', $token, $parts)) {
            return $token;
        }

        $leading = $parts[1];
        $core = $parts[2];
        $trailing = $parts[3];

        if ($core === '') {
            return $token;
        }

        if (!preg_match('/\pL/u', $core)) {
            return $token;
        }

        $segments = preg_split('/(?=[-\/&])|(?<=[-\/&])/u', $core);
        if (!is_array($segments)) {
            return $leading . self::normalizeWord((string) $core) . $trailing;
        }

        $normalizedCore = '';
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '-' || $segment === '/' || $segment === '&') {
                $normalizedCore .= $segment;
                continue;
            }

            $normalizedCore .= self::normalizeWord((string) $segment);
        }

        return $leading . $normalizedCore . $trailing;
    }

    private static function normalizeWord(string $word): string
    {
        $word = trim((string) $word);
        if ($word === '') {
            return $word;
        }

        if (!preg_match('/\pL/u', $word)) {
            return $word;
        }

        if (preg_match('/\pN/u', $word)) {
            return $word;
        }

        $upper = mb_strtoupper($word, 'UTF-8');
        if (isset(self::ACRONYMS[$upper])) {
            return self::ACRONYMS[$upper];
        }

        if (isset(self::STOP_WORDS[$upper])) {
            return self::STOP_WORDS[$upper];
        }

        return mb_convert_case(mb_strtolower($word, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}

