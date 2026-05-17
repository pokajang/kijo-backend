<?php

namespace App\Services\Stats;

use Carbon\Carbon;

class StatsDateRange
{
    public function parse(?string $start, ?string $end): array
    {
        $startDate = $this->normalize($start) ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $this->normalize($end) ?? Carbon::now()->toDateString();

        if ($startDate > $endDate) {
            return [$endDate, $startDate];
        }

        return [$startDate, $endDate];
    }

    public function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public function periodDates(string $start, string $end): array
    {
        $dates = [];
        $cursor = Carbon::parse($start)->startOfDay();
        $last = Carbon::parse($end)->startOfDay();

        while ($cursor <= $last) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }
}
