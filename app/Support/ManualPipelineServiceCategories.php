<?php

namespace App\Support;

final class ManualPipelineServiceCategories
{
    public const LABELS = [
        'training' => 'TRAINING',
        'consultancy_iso' => 'CONSULTANCY -ISO',
        'consultancy_ihoh' => 'CONSULTANCY - IHOH',
        'consultancy_osh' => 'CONSULTANCY - OSH',
        'man_power' => 'MAN POWER',
        'equipment_supply' => 'EQUIPMENT SUPPLY',
        'engineering' => 'ENGINEERING',
        'infrastructure' => 'INFRASTRUCTURE',
        'other' => 'OTHERS',
    ];

    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function normalize($value): ?string
    {
        $category = trim((string) ($value ?? ''));

        return array_key_exists($category, self::LABELS) ? $category : null;
    }

    public static function statusLabel($value): ?string
    {
        $category = self::normalize($value);

        return $category !== null ? self::LABELS[$category] : null;
    }

    public static function requiresCustomDescription($value): bool
    {
        return self::normalize($value) === 'other';
    }
}
