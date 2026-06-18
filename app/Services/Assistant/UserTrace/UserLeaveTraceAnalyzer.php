<?php

namespace App\Services\Assistant\UserTrace;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserLeaveTraceAnalyzer
{
    public function __construct(private readonly AssistantTraceDateRangeResolver $dates) {}

    public function analyze(string $question, AssistantUserTraceIdentity $identity, array $dateRange): AssistantUserTraceResult
    {
        if (! Schema::hasTable('hr_leaves_application')) {
            return $this->missing($dateRange, ['hr_leaves_application.table']);
        }

        $rows = [];
        foreach (DB::table('hr_leaves_application')->where('staff_id', $identity->staffId)->limit(1000)->get() as $row) {
            $item = (array) $row;
            if (! $this->overlapsRange($item, $dateRange)) {
                continue;
            }
            $rows[] = [
                'id' => $item['id'] ?? null,
                'type' => $item['type'] ?? 'Leave',
                'start_date' => $item['start_date'] ?? null,
                'end_date' => $item['end_date'] ?? null,
                'duration_days' => $this->duration($item),
                'status' => trim((string) ($item['status'] ?? 'unknown')) ?: 'unknown',
                'applied_at' => isset($item['applied_at']) ? substr((string) $item['applied_at'], 0, 10) : null,
            ];
        }

        $taken = array_values(array_filter($rows, static fn (array $row): bool => in_array(strtolower((string) $row['status']), ['approved', 'taken', 'completed'], true)));
        $pending = array_values(array_filter($rows, static fn (array $row): bool => strtolower((string) $row['status']) === 'pending'));
        $days = round(array_sum(array_map(static fn (array $row): float => (float) $row['duration_days'], $taken)), 2);
        $entitlements = $this->entitlements($identity);

        return new AssistantUserTraceResult(
            'user_trace.leave_taken',
            'My leave trace',
            'Approved or taken personal leave applications for the current user. Pending, rejected, cancelled, and draft applications are not counted as taken.',
            $dateRange,
            [
                'taken_days' => $days,
                'taken_count' => count($taken),
                'pending_count' => count($pending),
            ],
            [
                'by_month' => $this->sumByMonth($taken),
                'by_type' => $this->sumBy($taken, 'type'),
                'by_status' => $this->countBy($rows, 'status'),
                'entitlements' => $entitlements,
            ],
            array_slice($rows, 0, 8),
            ['show pending leave', 'break down by leave type', 'show remaining entitlement'],
            [],
            'high',
            $this->summary($days, count($taken), $dateRange),
            '/my/leaves',
            ['analyzer' => 'leave', 'row_count' => count($rows)],
        );
    }

    private function missing(array $dateRange, array $missing): AssistantUserTraceResult
    {
        return new AssistantUserTraceResult(
            'user_trace.leave_taken',
            'My leave trace',
            'Approved or taken personal leave applications for the current user.',
            $dateRange,
            ['taken_days' => 0, 'taken_count' => 0],
            [],
            [],
            [],
            $missing,
            'low',
            'I could not verify your leave trace because the leave table is not available.',
            '/my/leaves',
        );
    }

    private function overlapsRange(array $row, array $range): bool
    {
        if (($range['is_all_time'] ?? false) === true) {
            return true;
        }
        $start = $row['start_date'] ?? $row['applied_at'] ?? null;
        $end = $row['end_date'] ?? $start;
        if (! $start || ! $end) {
            return false;
        }

        return substr((string) $start, 0, 10) <= (string) $range['end']
            && substr((string) $end, 0, 10) >= (string) $range['start'];
    }

    private function duration(array $row): float
    {
        if (is_numeric($row['duration_days'] ?? null) && (float) $row['duration_days'] > 0) {
            return (float) $row['duration_days'];
        }
        if (! empty($row['start_date']) && ! empty($row['end_date'])) {
            try {
                return Carbon::parse($row['start_date'])->diffInDays(Carbon::parse($row['end_date'])) + 1;
            } catch (\Throwable) {
                return 0;
            }
        }

        return 0;
    }

    private function entitlements(AssistantUserTraceIdentity $identity): array
    {
        if (! Schema::hasTable('hr_leaves_allocation')) {
            return [];
        }

        return DB::table('hr_leaves_allocation')
            ->where('staff_id', $identity->staffId)
            ->limit(20)
            ->get()
            ->map(static fn ($row): array => array_filter([
                'leave_type' => $row->leave_type ?? null,
                'year' => $row->year ?? null,
                'total_days' => $row->total_days ?? null,
                'used_days' => $row->used_days ?? null,
                'remaining' => isset($row->remaining) ? $row->remaining : null,
            ], static fn ($value): bool => $value !== null && $value !== ''))
            ->values()
            ->all();
    }

    private function sumByMonth(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $month = substr((string) ($row['start_date'] ?? 'unknown'), 0, 7) ?: 'unknown';
            $values[$month] = round(($values[$month] ?? 0) + (float) $row['duration_days'], 2);
        }
        ksort($values);

        return $values;
    }

    private function sumBy(array $rows, string $key): array
    {
        $values = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row[$key] ?? 'unknown')) ?: 'unknown';
            $values[$label] = round(($values[$label] ?? 0) + (float) $row['duration_days'], 2);
        }
        arsort($values);

        return $values;
    }

    private function countBy(array $rows, string $key): array
    {
        $values = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row[$key] ?? 'unknown')) ?: 'unknown';
            $values[$label] = ($values[$label] ?? 0) + 1;
        }
        arsort($values);

        return $values;
    }

    private function summary(float $days, int $count, array $dateRange): string
    {
        $range = ($dateRange['is_all_time'] ?? false) ? 'all time' : "{$dateRange['start']} to {$dateRange['end']}";

        return "For your own records, {$range}, you have taken {$days} approved leave day(s) across {$count} application(s).";
    }
}
