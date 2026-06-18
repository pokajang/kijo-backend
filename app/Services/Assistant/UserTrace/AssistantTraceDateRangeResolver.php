<?php

namespace App\Services\Assistant\UserTrace;

use Carbon\Carbon;

class AssistantTraceDateRangeResolver
{
    public function resolve(string $question): array
    {
        $today = Carbon::today();

        if (preg_match('/\b(all\s+time|ever|sepanjang\s+masa)\b/i', $question)) {
            return [
                'label' => 'all time',
                'start' => null,
                'end' => null,
                'is_all_time' => true,
            ];
        }

        if ($explicit = $this->explicitRange($question)) {
            return $explicit;
        }

        if (preg_match('/\b(last\s+12\s+months|12\s+bulan\s+lepas)\b/i', $question)) {
            $start = $today->copy()->subMonthsNoOverflow(11)->startOfMonth();
            return $this->range('last 12 months', $start, $today);
        }

        if (preg_match('/\b(last\s+month|bulan\s+lepas)\b/i', $question)) {
            $month = $today->copy()->subMonthNoOverflow();
            return $this->range('last month', $month->copy()->startOfMonth(), $month->copy()->endOfMonth());
        }

        if (preg_match('/\b(this\s+month|current\s+month|bulan\s+ini)\b/i', $question)) {
            return $this->range('this month', $today->copy()->startOfMonth(), $today->copy()->endOfMonth());
        }

        if (preg_match('/\b(last\s+year|tahun\s+lepas)\b/i', $question)) {
            $year = $today->year - 1;
            return $this->range('last year', Carbon::create($year, 1, 1), Carbon::create($year, 12, 31));
        }

        if (preg_match('/\b(20\d{2})\b/', $question, $match)) {
            $year = (int) $match[1];
            return $this->range((string) $year, Carbon::create($year, 1, 1), Carbon::create($year, 12, 31));
        }

        if (preg_match('/\b(this\s+year|current\s+year|tahun\s+ini)\b/i', $question)) {
            return $this->range('this year', $today->copy()->startOfYear(), $today->copy()->endOfYear());
        }

        return $this->range('current calendar year', $today->copy()->startOfYear(), $today->copy()->endOfYear());
    }

    public function contains(?string $date, array $range): bool
    {
        if (($range['is_all_time'] ?? false) === true) {
            return true;
        }
        if (! $date) {
            return false;
        }

        try {
            $value = Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return false;
        }

        return $value >= (string) ($range['start'] ?? '') && $value <= (string) ($range['end'] ?? '');
    }

    private function range(string $label, Carbon $start, Carbon $end): array
    {
        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return [
            'label' => $label,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'is_all_time' => false,
        ];
    }

    private function explicitRange(string $question): ?array
    {
        if (! preg_match('/\b(?:from|between)?\s*(20\d{2}-\d{2}-\d{2})\s*(?:to|until|and|-)\s*(20\d{2}-\d{2}-\d{2})\b/i', $question, $match)) {
            return null;
        }

        try {
            return $this->range('explicit date range', Carbon::parse($match[1]), Carbon::parse($match[2]));
        } catch (\Throwable) {
            return null;
        }
    }
}
