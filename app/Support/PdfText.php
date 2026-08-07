<?php

namespace App\Support;

final class PdfText
{
    /**
     * Build page-safe item-cell segments while keeping ordinary items in one cell.
     *
     * @return list<array{description: string, remarks: string, show_description_label: bool, show_remarks_label: bool}>
     */
    public static function itemCellSegments($description, $remarks, int $limit = 700, int $maxLines = 18): array
    {
        $limit = max(100, $limit);
        $maxLines = max(1, $maxLines);
        $description = self::compactInline($description);
        $remarks = self::compactInline($remarks);
        $combined = implode("\n", array_filter([$description, $remarks], static fn (string $value): bool => $value !== ''));

        if (count(self::pageChunks($combined, $limit, $maxLines)) <= 1) {
            return [[
                'description' => $description,
                'remarks' => $remarks,
                'show_description_label' => $description !== '',
                'show_remarks_label' => $remarks !== '',
            ]];
        }

        $segments = [];
        foreach (self::pageChunks($description, $limit, $maxLines) as $index => $chunk) {
            $segments[] = [
                'description' => $chunk,
                'remarks' => '',
                'show_description_label' => $index === 0,
                'show_remarks_label' => false,
            ];
        }
        foreach (self::pageChunks($remarks, $limit, $maxLines) as $index => $chunk) {
            $segments[] = [
                'description' => '',
                'remarks' => $chunk,
                'show_description_label' => false,
                'show_remarks_label' => $index === 0,
            ];
        }

        return $segments ?: [[
            'description' => '',
            'remarks' => '',
            'show_description_label' => false,
            'show_remarks_label' => false,
        ]];
    }

    /**
     * Split long text into table-row-safe pieces without dropping characters.
     * Dompdf cannot reliably page-break one oversized table row.
     *
     * @return list<string>
     */
    public static function chunks($value, int $limit = 700): array
    {
        $text = self::normalize($value);
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

    /**
     * Convert pasted list-style text into a compact PDF-safe paragraph.
     * Explicit numbering is preserved; unsupported bullets become separators.
     */
    public static function compactInline($value): string
    {
        $text = self::normalize($value);
        if ($text === '') {
            return '';
        }

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = preg_replace('/^[\x{2022}\x{2023}\x{25E6}\x{2043}\x{2219}\x{25AA}\x{25AB}]\s*/u', '', trim($line)) ?? '';
            $line = preg_replace('/\s+/u', ' ', $line) ?? '';
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $result = '';
        foreach ($lines as $line) {
            if ($result === '') {
                $result = $line;

                continue;
            }

            $result .= str_ends_with($result, ':') ? ' '.$line : '; '.$line;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function pageChunks(string $text, int $limit, int $maxLines): array
    {
        if ($text === '') {
            return [];
        }

        $chunks = [];
        $current = [];
        $currentLength = 0;
        foreach (explode("\n", $text) as $line) {
            $lineParts = self::chunks($line, $limit) ?: [''];
            foreach ($lineParts as $part) {
                $separatorLength = $current === [] ? 0 : 1;
                $wouldOverflow = $current !== [] && (
                    count($current) >= $maxLines
                    || $currentLength + $separatorLength + mb_strlen($part, 'UTF-8') > $limit
                );
                if ($wouldOverflow) {
                    $chunks[] = implode("\n", $current);
                    $current = [];
                    $currentLength = 0;
                    $separatorLength = 0;
                }

                $current[] = $part;
                $currentLength += $separatorLength + mb_strlen($part, 'UTF-8');
            }
        }

        if ($current !== []) {
            $chunks[] = implode("\n", $current);
        }

        return $chunks;
    }

    private static function normalize($value): string
    {
        return str_replace(["\r\n", "\r"], "\n", trim((string) $value));
    }
}
