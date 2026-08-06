<?php

namespace App\Support;

final class PdfText
{
    /**
     * Split long text into table-row-safe pieces without dropping characters.
     * Dompdf cannot reliably page-break one oversized table row.
     *
     * @return list<string>
     */
    public static function chunks($value, int $limit = 700): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim((string) $value));
        if ($text === '') {
            return [];
        }

        $chunks = [];
        foreach (explode("\n", $text) as $line) {
            if ($line === '') {
                $chunks[] = '';

                continue;
            }

            while (mb_strlen($line, 'UTF-8') > $limit) {
                $candidate = mb_substr($line, 0, $limit, 'UTF-8');
                $breakAt = mb_strrpos($candidate, ' ', 0, 'UTF-8');
                if ($breakAt === false || $breakAt < (int) ($limit * 0.6)) {
                    $breakAt = $limit;
                }

                $chunks[] = rtrim(mb_substr($line, 0, $breakAt, 'UTF-8'));
                $line = ltrim(mb_substr($line, $breakAt, null, 'UTF-8'));
            }

            $chunks[] = $line;
        }

        return $chunks;
    }
}
