<?php

namespace App\Services\Stats;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkloadDailySnapshotService
{
    public function __construct(
        private WorkloadDashboardStatsService $workloadStats,
    ) {}

    public function capture(Carbon|string $date, bool $force = false, array $metadata = []): array
    {
        $snapshotDate = $this->normalizeDate($date);
        $captureMode = $this->captureMode($metadata['captureMode'] ?? null);
        $capturedByCommand = $this->nullableString($metadata['capturedByCommand'] ?? null);
        $captureNote = $this->nullableString($metadata['captureNote'] ?? null);

        $this->ensureTables();

        if (! $force && DB::table('workload_daily_snapshots')->where('snapshot_date', $snapshotDate)->exists()) {
            $staffCount = (int) DB::table('workload_daily_staff_snapshots')
                ->where('snapshot_date', $snapshotDate)
                ->count();

            return [
                'status' => 'skipped',
                'snapshotDate' => $snapshotDate,
                'staffCount' => $staffCount,
            ];
        }

        $request = Request::create('/stats/workload', 'GET', [
            'start_date' => $snapshotDate,
            'end_date' => $snapshotDate,
        ]);
        $payload = $this->workloadStats->workloadPayload($request);
        $staffRows = array_values(is_array($payload['staff'] ?? null) ? $payload['staff'] : []);
        $payloadJson = $this->encodeJson($payload, 'Unable to encode workload snapshot payload.');
        $now = now();

        $totals = [
            'staffCount' => count($staffRows),
            'totalScore' => array_sum(array_map(fn (array $row): float => (float) ($row['score'] ?? 0), $staffRows)),
            'totalActiveTasks' => array_sum(array_map(fn (array $row): int => (int) ($row['activeTasks'] ?? 0), $staffRows)),
            'totalOverdueTasks' => array_sum(array_map(fn (array $row): int => (int) ($row['overdueTasks'] ?? 0), $staffRows)),
            'totalDueSoonTasks' => array_sum(array_map(fn (array $row): int => (int) ($row['dueSoonTasks'] ?? 0), $staffRows)),
            'totalCompletedInPeriod' => array_sum(array_map(fn (array $row): int => (int) ($row['completedInPeriod'] ?? 0), $staffRows)),
        ];
        $avgScore = $totals['staffCount'] > 0 ? $totals['totalScore'] / $totals['staffCount'] : 0;

        DB::transaction(function () use ($avgScore, $captureMode, $captureNote, $capturedByCommand, $force, $now, $payloadJson, $snapshotDate, $staffRows, $totals): void {
            if ($force) {
                DB::table('workload_daily_staff_snapshots')->where('snapshot_date', $snapshotDate)->delete();
                DB::table('workload_daily_snapshots')->where('snapshot_date', $snapshotDate)->delete();
            }

            $snapshotRow = [
                'snapshot_date' => $snapshotDate,
                'start_date' => $snapshotDate,
                'end_date' => $snapshotDate,
                'staff_count' => $totals['staffCount'],
                'total_score' => round($totals['totalScore'], 2),
                'avg_score' => round($avgScore, 2),
                'total_active_tasks' => $totals['totalActiveTasks'],
                'total_overdue_tasks' => $totals['totalOverdueTasks'],
                'total_due_soon_tasks' => $totals['totalDueSoonTasks'],
                'total_completed_in_period' => $totals['totalCompletedInPeriod'],
                'payload_json' => $payloadJson,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('workload_daily_snapshots', 'capture_mode')) {
                $snapshotRow['capture_mode'] = $captureMode;
            }
            if (Schema::hasColumn('workload_daily_snapshots', 'captured_by_command')) {
                $snapshotRow['captured_by_command'] = $capturedByCommand;
            }
            if (Schema::hasColumn('workload_daily_snapshots', 'capture_note')) {
                $snapshotRow['capture_note'] = $captureNote;
            }

            DB::table('workload_daily_snapshots')->insert($snapshotRow);

            foreach ($staffRows as $row) {
                DB::table('workload_daily_staff_snapshots')->insert([
                    'snapshot_date' => $snapshotDate,
                    'staff_id' => isset($row['staffId']) && $row['staffId'] !== null ? (int) $row['staffId'] : null,
                    'staff_key' => (string) ($row['staffKey'] ?? ''),
                    'staff_code' => $this->nullableString($row['staffCode'] ?? null),
                    'staff_name' => $this->nullableString($row['staffName'] ?? null),
                    'score' => round((float) ($row['score'] ?? 0), 2),
                    'active_tasks' => (int) ($row['activeTasks'] ?? 0),
                    'overdue_tasks' => (int) ($row['overdueTasks'] ?? 0),
                    'due_soon_tasks' => (int) ($row['dueSoonTasks'] ?? 0),
                    'project_tagged_active_tasks' => (int) ($row['projectTaggedActiveTasks'] ?? 0),
                    'project_group_count' => (int) ($row['projectGroupCount'] ?? 0),
                    'completed_in_period' => (int) ($row['completedInPeriod'] ?? 0),
                    'late_completed_in_period' => (int) ($row['lateCompletedInPeriod'] ?? 0),
                    'avg_days_lapsed' => (int) ($row['avgDaysLapsed'] ?? 0),
                    'score_breakdown_json' => $this->encodeJson($row['scoreBreakdown'] ?? [], 'Unable to encode workload score breakdown.'),
                    'work_type_breakdown_json' => $this->encodeJson($row['workTypeBreakdown'] ?? [], 'Unable to encode workload work type breakdown.'),
                    'row_payload_json' => $this->encodeJson($row, 'Unable to encode workload staff snapshot payload.'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        app(WorkloadSnapshotHealthService::class)->recordCaptureChecks($snapshotDate);

        return [
            'status' => 'captured',
            'snapshotDate' => $snapshotDate,
            'staffCount' => $totals['staffCount'],
            'captureMode' => $captureMode,
        ];
    }

    public function history(Request $request): JsonResponse
    {
        if (! Schema::hasTable('workload_daily_staff_snapshots')) {
            return response()->json([
                'status' => 'success',
                'startDate' => $this->dateParam($request, ['start_date', 'start']),
                'endDate' => $this->dateParam($request, ['end_date', 'end']) ?: Carbon::today()->toDateString(),
                'staff' => [],
            ]);
        }

        $startDate = $this->dateParam($request, ['start_date', 'start']);
        $endDate = $this->dateParam($request, ['end_date', 'end']) ?: Carbon::today()->toDateString();

        $select = [
            'workload_daily_staff_snapshots.snapshot_date',
            'workload_daily_staff_snapshots.staff_id',
            'workload_daily_staff_snapshots.staff_key',
            'workload_daily_staff_snapshots.staff_code',
            'workload_daily_staff_snapshots.staff_name',
            'workload_daily_staff_snapshots.score',
        ];
        $hasCaptureMode = Schema::hasTable('workload_daily_snapshots')
            && Schema::hasColumn('workload_daily_snapshots', 'capture_mode');
        if ($hasCaptureMode) {
            $select[] = 'workload_daily_snapshots.capture_mode';
        }

        $query = DB::table('workload_daily_staff_snapshots')
            ->when($hasCaptureMode, function ($query): void {
                $query->leftJoin('workload_daily_snapshots', 'workload_daily_snapshots.snapshot_date', '=', 'workload_daily_staff_snapshots.snapshot_date');
            })
            ->select($select)
            ->whereDate('workload_daily_staff_snapshots.snapshot_date', '<=', $endDate);

        if ($startDate !== '') {
            $query->whereDate('workload_daily_staff_snapshots.snapshot_date', '>=', $startDate);
        }

        $rows = $query
            ->orderBy('workload_daily_staff_snapshots.staff_key')
            ->orderBy('workload_daily_staff_snapshots.snapshot_date')
            ->get();

        $staff = [];
        foreach ($rows as $row) {
            $staffKey = (string) $row->staff_key;
            if ($staffKey === '') {
                continue;
            }

            if (! isset($staff[$staffKey])) {
                $staff[$staffKey] = [
                    'staffKey' => $staffKey,
                    'staffId' => $row->staff_id !== null ? (int) $row->staff_id : null,
                    'staffCode' => (string) ($row->staff_code ?? ''),
                    'staffName' => (string) ($row->staff_name ?? ''),
                    'points' => [],
                    '_latestScore' => null,
                ];
            }

            $score = round((float) $row->score, 2);
            $staff[$staffKey]['points'][] = [
                'date' => Carbon::parse($row->snapshot_date)->toDateString(),
                'score' => $score,
                'captureMode' => (string) ($row->capture_mode ?? 'captured'),
            ];
            $staff[$staffKey]['_latestScore'] = $score;
        }

        $staffRows = array_values($staff);
        usort($staffRows, function (array $a, array $b): int {
            $scoreDelta = ((float) ($b['_latestScore'] ?? 0)) <=> ((float) ($a['_latestScore'] ?? 0));
            if ($scoreDelta !== 0) {
                return $scoreDelta;
            }

            $aLabel = trim(($a['staffCode'] ?? '').' '.($a['staffName'] ?? ''));
            $bLabel = trim(($b['staffCode'] ?? '').' '.($b['staffName'] ?? ''));

            return strcmp($aLabel, $bLabel);
        });

        $staffRows = array_map(function (array $row): array {
            unset($row['_latestScore']);

            return $row;
        }, $staffRows);

        return response()->json([
            'status' => 'success',
            'startDate' => $startDate,
            'endDate' => $endDate,
            'staff' => $staffRows,
        ]);
    }

    private function ensureTables(): void
    {
        if (! Schema::hasTable('workload_daily_snapshots') || ! Schema::hasTable('workload_daily_staff_snapshots')) {
            throw new \RuntimeException('Workload daily snapshot tables are missing. Run the latest migrations.');
        }
    }

    private function normalizeDate(Carbon|string $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function captureMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, ['captured', 'reconstructed'], true) ? $mode : 'captured';
    }

    private function encodeJson(mixed $value, string $message): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException($message);
        }

        return $json;
    }

    private function dateParam(Request $request, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) $request->input($key, ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return $value;
            }
        }

        return '';
    }
}
