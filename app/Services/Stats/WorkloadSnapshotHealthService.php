<?php

namespace App\Services\Stats;

use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WorkloadSnapshotHealthService
{
    private const CHECK_TABLE = 'workload_daily_snapshot_checks';

    private const SNAPSHOT_TABLE = 'workload_daily_snapshots';

    private const STAFF_TABLE = 'workload_daily_staff_snapshots';

    private const MODULE_KEY = 'system.admin.workload_snapshots';

    private const ENTITY_TYPE = 'workload_daily_snapshot';

    private const MISSED_CAPTURE = 'missed_capture';

    private const MISSED_CAPTURE_TYPE = 'workload_daily_snapshot_missing';

    private const RETENTION_DAYS = 180;

    private const SCORE_JUMP_THRESHOLD = 0.40;

    private const STAFF_DROP_THRESHOLD = 0.25;

    public function __construct(
        private AppNotificationService $notifications,
    ) {}

    public function recordCaptureChecks(Carbon|string $date): array
    {
        if (! $this->tablesAvailable()) {
            return [];
        }

        $snapshotDate = $this->normalizeDate($date);
        $current = DB::table(self::SNAPSHOT_TABLE)->where('snapshot_date', $snapshotDate)->first();
        if (! $current) {
            return [];
        }

        DB::table(self::CHECK_TABLE)
            ->where('snapshot_date', $snapshotDate)
            ->where('check_key', '!=', self::MISSED_CAPTURE)
            ->delete();

        $this->resolveMissedCapture($snapshotDate);

        $previous = DB::table(self::SNAPSHOT_TABLE)
            ->whereDate('snapshot_date', '<', $snapshotDate)
            ->orderByDesc('snapshot_date')
            ->first();

        $checks = [];
        if ((int) $current->staff_count === 0) {
            $checks[] = $this->upsertCheck(
                $snapshotDate,
                'critical',
                'zero_staff',
                'Daily workload snapshot captured zero staff rows.',
                ['staffCount' => 0],
            );
        }

        if ((int) $current->total_active_tasks === 0) {
            $checks[] = $this->upsertCheck(
                $snapshotDate,
                'warning',
                'active_tasks_zero',
                'Daily workload snapshot captured zero active tasks.',
                ['totalActiveTasks' => 0],
            );
        }

        if ($previous) {
            $previousScore = (float) $previous->total_score;
            $currentScore = (float) $current->total_score;
            if ($previousScore > 0) {
                $changeRatio = abs($currentScore - $previousScore) / $previousScore;
                if ($changeRatio > self::SCORE_JUMP_THRESHOLD) {
                    $checks[] = $this->upsertCheck(
                        $snapshotDate,
                        'warning',
                        'score_jump',
                        'Daily workload snapshot total score changed by more than 40% versus the previous snapshot.',
                        [
                            'previousSnapshotDate' => $this->dateString($previous->snapshot_date),
                            'previousTotalScore' => round($previousScore, 2),
                            'previousCaptureMode' => (string) ($previous->capture_mode ?? 'captured'),
                            'currentTotalScore' => round($currentScore, 2),
                            'currentCaptureMode' => (string) ($current->capture_mode ?? 'captured'),
                            'changeRatio' => round($changeRatio, 4),
                        ],
                    );
                }
            }

            $previousStaffCount = (int) $previous->staff_count;
            $currentStaffCount = (int) $current->staff_count;
            if ($previousStaffCount > 0) {
                $dropRatio = ($previousStaffCount - $currentStaffCount) / $previousStaffCount;
                if ($dropRatio > self::STAFF_DROP_THRESHOLD) {
                    $checks[] = $this->upsertCheck(
                        $snapshotDate,
                        'warning',
                        'staff_count_drop',
                        'Daily workload snapshot staff count dropped by more than 25% versus the previous snapshot.',
                        [
                            'previousSnapshotDate' => $this->dateString($previous->snapshot_date),
                            'previousStaffCount' => $previousStaffCount,
                            'currentStaffCount' => $currentStaffCount,
                            'dropRatio' => round($dropRatio, 4),
                        ],
                    );
                }
            }
        }

        return $checks;
    }

    public function checkDailyCapture(Carbon|string|null $expectedDate = null): array
    {
        if (! $this->tablesAvailable()) {
            return [
                'status' => 'unavailable',
                'expectedCaptureDate' => $this->normalizeDate($expectedDate ?? Carbon::yesterday()),
                'message' => 'Workload daily snapshot tables are not available.',
            ];
        }

        $snapshotDate = $this->normalizeDate($expectedDate ?? Carbon::yesterday());
        if (DB::table(self::SNAPSHOT_TABLE)->where('snapshot_date', $snapshotDate)->exists()) {
            $this->resolveMissedCapture($snapshotDate);

            return [
                'status' => 'ok',
                'expectedCaptureDate' => $snapshotDate,
                'message' => 'Daily workload snapshot exists.',
            ];
        }

        $check = $this->upsertCheck(
            $snapshotDate,
            'critical',
            self::MISSED_CAPTURE,
            'Daily workload snapshot is missing after the scheduled capture time.',
            ['scheduledCaptureTime' => '23:55', 'checkedAfter' => '00:30'],
        );
        $notifiedStaffIds = $this->createMissingCaptureNotifications($snapshotDate);

        return [
            'status' => 'missing',
            'expectedCaptureDate' => $snapshotDate,
            'check' => $check,
            'notifiedStaffIds' => $notifiedStaffIds,
        ];
    }

    public function healthPayload(): JsonResponse
    {
        if (! $this->tablesAvailable()) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'available' => false,
                    'captureStatus' => 'unavailable',
                    'expectedCaptureDate' => Carbon::yesterday()->toDateString(),
                    'capturedSnapshotsLast31Days' => 0,
                    'reconstructedSnapshotsLast31Days' => 0,
                    'latestSnapshot' => null,
                    'unresolvedChecks' => [],
                    'checkCounts' => [],
                    'retention' => $this->payloadRetentionSummary(),
                ],
            ]);
        }

        $expectedDate = Carbon::yesterday()->toDateString();
        $latest = DB::table(self::SNAPSHOT_TABLE)->orderByDesc('snapshot_date')->first();
        $missing = ! DB::table(self::SNAPSHOT_TABLE)->where('snapshot_date', $expectedDate)->exists();
        $checks = DB::table(self::CHECK_TABLE)
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderByDesc('snapshot_date')
            ->get()
            ->map(fn ($row): array => [
                'snapshotDate' => $this->dateString($row->snapshot_date),
                'severity' => (string) $row->severity,
                'checkKey' => (string) $row->check_key,
                'message' => (string) ($row->message ?? ''),
                'metadata' => $this->decodeJson($row->metadata_json ?? null),
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ])
            ->all();

        $groupedChecks = [];
        foreach ($checks as $check) {
            $groupedChecks[$check['severity']][] = $check;
        }

        $checkCounts = [];
        foreach ($groupedChecks as $severity => $rows) {
            $checkCounts[$severity] = count($rows);
        }

        $captureStatus = 'ok';
        if ($missing) {
            $captureStatus = 'missing';
        } elseif (! empty($groupedChecks['critical']) || ! empty($groupedChecks['warning'])) {
            $captureStatus = 'warning';
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'available' => true,
                'captureStatus' => $captureStatus,
                'expectedCaptureDate' => $expectedDate,
                'capturedSnapshotsLast31Days' => $this->snapshotModeCount('captured', 31),
                'reconstructedSnapshotsLast31Days' => $this->snapshotModeCount('reconstructed', 31),
                'latestSnapshot' => $latest ? [
                    'snapshotDate' => $this->dateString($latest->snapshot_date),
                    'captureMode' => (string) ($latest->capture_mode ?? 'captured'),
                    'staffCount' => (int) $latest->staff_count,
                    'totalScore' => round((float) $latest->total_score, 2),
                    'avgScore' => round((float) $latest->avg_score, 2),
                    'totalActiveTasks' => (int) $latest->total_active_tasks,
                    'totalOverdueTasks' => (int) $latest->total_overdue_tasks,
                    'totalDueSoonTasks' => (int) $latest->total_due_soon_tasks,
                    'totalCompletedInPeriod' => (int) $latest->total_completed_in_period,
                    'payloadRetained' => $latest->payload_json !== null,
                ] : null,
                'unresolvedChecks' => $groupedChecks,
                'checkCounts' => $checkCounts,
                'retention' => $this->payloadRetentionSummary(),
            ],
        ]);
    }

    public function prunePayloads(int $olderThanDays = self::RETENTION_DAYS): array
    {
        if (! $this->baseTablesAvailable()) {
            return [
                'status' => 'unavailable',
                'olderThanDays' => $olderThanDays,
                'aggregatePayloadsPruned' => 0,
                'staffPayloadsPruned' => 0,
            ];
        }

        $olderThanDays = max(1, $olderThanDays);
        $cutoffDate = Carbon::today()->subDays($olderThanDays)->toDateString();

        $aggregatePayloadsPruned = DB::table(self::SNAPSHOT_TABLE)
            ->whereDate('snapshot_date', '<', $cutoffDate)
            ->whereNotNull('payload_json')
            ->update([
                'payload_json' => null,
                'updated_at' => now(),
            ]);

        $staffPayloadsPruned = DB::table(self::STAFF_TABLE)
            ->whereDate('snapshot_date', '<', $cutoffDate)
            ->whereNotNull('row_payload_json')
            ->update([
                'row_payload_json' => null,
                'updated_at' => now(),
            ]);

        $result = [
            'status' => 'pruned',
            'olderThanDays' => $olderThanDays,
            'cutoffDate' => $cutoffDate,
            'aggregatePayloadsPruned' => $aggregatePayloadsPruned,
            'staffPayloadsPruned' => $staffPayloadsPruned,
        ];

        Log::info('Workload daily snapshot payload pruning completed.', $result);

        return $result;
    }

    private function resolveMissedCapture(string $snapshotDate): void
    {
        if (! Schema::hasTable(self::CHECK_TABLE)) {
            return;
        }

        DB::table(self::CHECK_TABLE)
            ->where('snapshot_date', $snapshotDate)
            ->where('check_key', self::MISSED_CAPTURE)
            ->delete();

        $this->notifications->resolveActive(
            self::MODULE_KEY,
            self::ENTITY_TYPE,
            $this->entityIdForDate($snapshotDate),
            [self::MISSED_CAPTURE_TYPE],
        );
    }

    private function createMissingCaptureNotifications(string $snapshotDate): array
    {
        $staffIds = $this->notifications->staffIdsForRoles(['System Admin']);
        if (empty($staffIds) || ! Schema::hasTable('in_app_notifications')) {
            return [];
        }

        $entityId = $this->entityIdForDate($snapshotDate);
        $existingStaffIds = DB::table('in_app_notifications')
            ->where('module_key', self::MODULE_KEY)
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $entityId)
            ->where('type', self::MISSED_CAPTURE_TYPE)
            ->whereNull('resolved_at')
            ->whereNull('consumed_at')
            ->pluck('recipient_staff_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $targetStaffIds = array_values(array_diff($staffIds, $existingStaffIds));
        if (empty($targetStaffIds)) {
            return [];
        }

        $this->notifications->createForStaff($targetStaffIds, [
            'module_key' => self::MODULE_KEY,
            'entity_type' => self::ENTITY_TYPE,
            'entity_id' => $entityId,
            'type' => self::MISSED_CAPTURE_TYPE,
            'title' => 'Daily workload snapshot missing',
            'message' => "No workload daily snapshot exists for {$snapshotDate} after the scheduled capture time.",
            'route' => '/system-admin/dashboard',
            'severity' => 'danger',
            'metadata' => [
                'snapshotDate' => $snapshotDate,
                'scheduledCaptureTime' => '23:55',
                'checkedAfter' => '00:30',
            ],
        ]);

        return $targetStaffIds;
    }

    private function upsertCheck(
        string $snapshotDate,
        string $severity,
        string $checkKey,
        string $message,
        array $metadata = [],
    ): array {
        $now = now();
        $payload = [
            'snapshot_date' => $snapshotDate,
            'severity' => $severity,
            'check_key' => $checkKey,
            'message' => $message,
            'metadata_json' => $this->encodeJson($metadata),
            'updated_at' => $now,
        ];

        DB::table(self::CHECK_TABLE)->updateOrInsert(
            ['snapshot_date' => $snapshotDate, 'check_key' => $checkKey],
            $payload + ['created_at' => $now],
        );

        return [
            'snapshotDate' => $snapshotDate,
            'severity' => $severity,
            'checkKey' => $checkKey,
            'message' => $message,
            'metadata' => $metadata,
        ];
    }

    private function payloadRetentionSummary(): array
    {
        $summary = [
            'olderThanDays' => self::RETENTION_DAYS,
            'cutoffDate' => Carbon::today()->subDays(self::RETENTION_DAYS)->toDateString(),
            'aggregatePayloadsRetainedBeyondCutoff' => 0,
            'staffPayloadsRetainedBeyondCutoff' => 0,
            'payloadsRetainedBeyondCutoff' => 0,
            'oldestRetainedPayloadDate' => null,
            'lastPrunedAt' => null,
            'lastPruneState' => 'not_run',
        ];

        if (! $this->baseTablesAvailable()) {
            return $summary + ['available' => false];
        }

        $summary['available'] = true;
        $summary['aggregatePayloadsRetainedBeyondCutoff'] = (int) DB::table(self::SNAPSHOT_TABLE)
            ->whereDate('snapshot_date', '<', $summary['cutoffDate'])
            ->whereNotNull('payload_json')
            ->count();
        $summary['staffPayloadsRetainedBeyondCutoff'] = (int) DB::table(self::STAFF_TABLE)
            ->whereDate('snapshot_date', '<', $summary['cutoffDate'])
            ->whereNotNull('row_payload_json')
            ->count();
        $summary['payloadsRetainedBeyondCutoff'] = $summary['aggregatePayloadsRetainedBeyondCutoff']
            + $summary['staffPayloadsRetainedBeyondCutoff'];

        $oldestAggregate = DB::table(self::SNAPSHOT_TABLE)
            ->whereNotNull('payload_json')
            ->min('snapshot_date');
        $oldestStaff = DB::table(self::STAFF_TABLE)
            ->whereNotNull('row_payload_json')
            ->min('snapshot_date');
        $oldestDates = array_filter([$oldestAggregate, $oldestStaff]);
        if (! empty($oldestDates)) {
            $summary['oldestRetainedPayloadDate'] = $this->dateString(min($oldestDates));
        }

        $lastPrunedAt = collect([
            DB::table(self::SNAPSHOT_TABLE)->whereNull('payload_json')->max('updated_at'),
            DB::table(self::STAFF_TABLE)->whereNull('row_payload_json')->max('updated_at'),
        ])->filter()->max();
        $summary['lastPrunedAt'] = $lastPrunedAt ?: null;
        $summary['lastPruneState'] = $lastPrunedAt ? 'completed' : 'not_run';

        return $summary;
    }

    private function tablesAvailable(): bool
    {
        return $this->baseTablesAvailable() && Schema::hasTable(self::CHECK_TABLE);
    }

    private function baseTablesAvailable(): bool
    {
        return Schema::hasTable(self::SNAPSHOT_TABLE) && Schema::hasTable(self::STAFF_TABLE);
    }

    private function snapshotModeCount(string $captureMode, int $days): int
    {
        if (! Schema::hasColumn(self::SNAPSHOT_TABLE, 'capture_mode')) {
            return 0;
        }

        return (int) DB::table(self::SNAPSHOT_TABLE)
            ->whereDate('snapshot_date', '>=', Carbon::today()->subDays(max(0, $days - 1))->toDateString())
            ->whereDate('snapshot_date', '<=', Carbon::today()->toDateString())
            ->where('capture_mode', $captureMode)
            ->count();
    }

    private function normalizeDate(Carbon|string $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();
    }

    private function dateString(mixed $date): string
    {
        return Carbon::parse($date)->toDateString();
    }

    private function entityIdForDate(string $snapshotDate): int
    {
        return (int) str_replace('-', '', $snapshotDate);
    }

    private function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }

    private function decodeJson(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
