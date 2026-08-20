<?php

namespace App\Services\Invoices\Pdf;

/**
 * Fits user-provided JD14 values into their fixed printable cells.
 *
 * The source record is never altered. When a value cannot fit at the minimum
 * readable font size, only its PDF display value is shortened.
 */
class Jd14TextFitter
{
    /**
     * @return array{text: string, font_size: float, was_compacted: bool, was_truncated: bool}
     */
    public function fit(
        object $pdf,
        string $text,
        float $availableWidth,
        float $availableHeight,
        float $defaultFontSize = 9.0,
        float $minimumFontSize = 6.5,
        float $lineHeightRatio = 1.05,
    ): array {
        $text = $this->normalise($text);
        $fontSize = $defaultFontSize;

        while ($fontSize >= $minimumFontSize) {
            if ($this->fits($pdf, $text, $availableWidth, $availableHeight, $fontSize, $lineHeightRatio)) {
                return [
                    'text' => $text,
                    'font_size' => $fontSize,
                    'was_compacted' => $fontSize < $defaultFontSize,
                    'was_truncated' => false,
                ];
            }

            $fontSize = round($fontSize - 0.25, 2);
        }

        $fontSize = $minimumFontSize;

        return [
            'text' => $this->truncateToFit($pdf, $text, $availableWidth, $availableHeight, $fontSize, $lineHeightRatio),
            'font_size' => $fontSize,
            'was_compacted' => true,
            'was_truncated' => true,
        ];
    }

    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = preg_split('/\n/u', $text) ?: [];
        $lines = array_map(
            static fn (string $line): string => preg_replace('/[\t ]+/u', ' ', trim($line)) ?? '',
            $lines,
        );

        $result = [];
        $lastWasBlank = false;
        foreach ($lines as $line) {
            $isBlank = $line === '';
            if ($isBlank && $lastWasBlank) {
                continue;
            }

            $result[] = $line;
            $lastWasBlank = $isBlank;
        }

        return trim(implode("\n", $result));
    }

    private function fits(
        object $pdf,
        string $text,
        float $width,
        float $height,
        float $fontSize,
        float $lineHeightRatio,
    ): bool {
        $pdf->SetFont('helvetica', '', $fontSize);

        return $pdf->getStringHeight($width, $text, false, true, '', $lineHeightRatio) <= $height + 0.01;
    }

    private function truncateToFit(
        object $pdf,
        string $text,
        float $width,
        float $height,
        float $fontSize,
        float $lineHeightRatio,
    ): string {
        if ($text === '' || $this->fits($pdf, $text, $width, $height, $fontSize, $lineHeightRatio)) {
            return $text;
        }

        $suffix = '…';
        $low = 0;
        $high = mb_strlen($text);
        $best = '';

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $candidate = rtrim(mb_substr($text, 0, $middle)).$suffix;

            if ($this->fits($pdf, $candidate, $width, $height, $fontSize, $lineHeightRatio)) {
                $best = $candidate;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $best !== '' ? $best : $suffix;
    }
}
