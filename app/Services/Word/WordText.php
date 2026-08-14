<?php

namespace App\Services\Word;

final class WordText
{
    public static function clean(mixed $value): string
    {
        $text = mb_convert_encoding((string) $value, 'UTF-8', 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return preg_replace(
            '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            '',
            $text,
        ) ?? '';
    }

    /** @return list<string> */
    public static function lines(mixed $value): array
    {
        return explode("\n", self::clean($value));
    }
}
