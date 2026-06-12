<?php

namespace App\Services\Stats;

class DashboardDimensionNormalizer
{
    public function cleanText($value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return '';
        }

        return preg_replace('/\s+/', ' ', $text) ?: '';
    }

    public function serviceGroup($serviceGroup): string
    {
        $raw = $this->cleanText($serviceGroup);
        if ($raw === '') {
            return 'Unclassified';
        }

        return match ($this->serviceKey($raw)) {
            'training' => 'Training',
            'industrial_hygiene' => 'Industrial Hygiene',
            'equipment_supply' => 'Equipment Supply',
            'manpower_supply' => 'Manpower Supply',
            'special_service' => 'Special Service',
            'unclassified' => 'Unclassified',
            default => $raw,
        };
    }

    public function serviceKey($serviceGroup): string
    {
        $raw = $this->cleanText($serviceGroup);
        if ($raw === '') {
            return 'unclassified';
        }

        $normalized = strtolower(str_replace(['_', '-'], ' ', $raw));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?: '';
        $normalized = trim($normalized);

        $exact = match ($normalized) {
            'training' => 'training',
            'ih', 'industrial hygiene' => 'industrial_hygiene',
            'equipment', 'equipment supply' => 'equipment_supply',
            'man power', 'manpower', 'manpower supply' => 'manpower_supply',
            'special', 'special service' => 'special_service',
            default => null,
        };

        if ($exact !== null) {
            return $exact;
        }

        if (str_contains($normalized, 'training')) {
            return 'training';
        }
        if (str_contains($normalized, 'industrial') || $normalized === 'ih') {
            return 'industrial_hygiene';
        }
        if (str_contains($normalized, 'equipment')) {
            return 'equipment_supply';
        }
        if (str_contains($normalized, 'manpower') || str_contains($normalized, 'man power')) {
            return 'manpower_supply';
        }
        if (str_contains($normalized, 'special')) {
            return 'special_service';
        }

        return $normalized;
    }

    public function source($source): string
    {
        $source = $this->cleanText($source);

        return $source !== '' ? $source : 'Unattributed';
    }

    public function staffCode($staffCode): string
    {
        $staffCode = strtoupper($this->cleanText($staffCode));

        return $staffCode !== '' ? $staffCode : 'UNASSIGNED';
    }

    public function staffName($staffName): string
    {
        $staffName = $this->cleanText($staffName);

        return $staffName !== '' ? $staffName : 'Unassigned';
    }
}
