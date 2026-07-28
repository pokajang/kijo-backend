<?php

namespace App\Support;

final class InvoicePdfDescription
{
    public static function clientVisible(mixed $value): string
    {
        $description = trim((string) $value);

        return self::isInternalCalculation($description) ? '' : $description;
    }

    private static function isInternalCalculation(string $description): bool
    {
        if ($description === '') {
            return false;
        }

        $number = '\d[\d,]*(?:\.\d+)?';
        $rate = 'RM\s*'.$number.'(?:\/\S+)?';

        if (preg_match(
            '/^'.$number.'\s+pax\s+[x×]\s+'.$number.'\s+month(?:s|\(s\))?(?:\s+[x×]\s+'.$rate.')?$/iu',
            $description,
        )) {
            return true;
        }

        $hygieneBasis = '(?:[x×]\s+'.$number.'\s+work unit(?:s|\(s\))?|-\s+lump sum work unit)';
        $internalFactor = '(?:complexity\s+\d+\s+\('.$number.'x\)|'.$rate.')';
        $internalFactors = '(?:\s+[x×]\s+'.$internalFactor.')*';
        $historical = '(?:;\s*preserved historical quoted amount)?';

        if (preg_match(
            '/^'.$number.'\s+.+?\s+'.$hygieneBasis.$internalFactors.$historical.'$/iu',
            $description,
        )) {
            return true;
        }

        return (bool) preg_match(
            '/^'.$number.'\s+\S+(?:\(s\))?\s+[x×]\s+'.$rate.'$/iu',
            $description,
        );
    }
}
