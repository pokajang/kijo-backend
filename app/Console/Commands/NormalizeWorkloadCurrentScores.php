<?php

namespace App\Console\Commands;

use App\Services\Stats\WorkloadCurrentScoreSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NormalizeWorkloadCurrentScores extends Command
{
    protected $signature = 'workload:normalize-current-scores {--dry-run} {--start-date=} {--end-date=}';

    protected $description = 'Remove completed-work credit from stored workload daily snapshot scores.';

    public function handle(WorkloadCurrentScoreSanitizer $sanitizer): int
    {
        if (! Schema::hasTable('workload_daily_snapshots') || ! Schema::hasTable('workload_daily_staff_snapshots')) {
            $this->warn('Daily workload snapshot tables are unavailable; nothing normalized.');

            return self::SUCCESS;
        }

        try {
            $startDate = $this->dateOption('start-date');
            $endDate = $this->dateOption('end-date');
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            $this->error('--start-date must be on or before --end-date.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $query = DB::table('workload_daily_staff_snapshots')
            ->select([
                'id',
                'snapshot_date',
                'score_breakdown_json',
                'row_payload_json',
            ]);

        if ($startDate !== null) {
            $query->whereDate('snapshot_date', '>=', $startDate);
        }
        if ($endDate !== null) {
            $query->whereDate('snapshot_date', '<=', $endDate);
        }

        $rows = $query->orderBy('snapshot_date')->orderBy('staff_key')->get();
        $affectedDates = [];
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        DB::transaction(function () use ($dryRun, $rows, $sanitizer, &$affectedDates, &$updated, &$unchanged, &$skipped): void {
            foreach ($rows as $row) {
                $breakdown = $this->decodeJson($row->score_breakdown_json);
                if (! is_array($breakdown)) {
                    $skipped++;

                    continue;
                }

                if (! $sanitizer->hasCompletedWorkLine($breakdown)) {
                    $unchanged++;

                    continue;
                }

                $currentBreakdown = $sanitizer->currentScoreBreakdown($breakdown);
                $currentScore = $sanitizer->scoreFromBreakdown($currentBreakdown);
                $payloadJson = $row->row_payload_json;
                $payload = $this->decodeJson($payloadJson);
                if (is_array($payload)) {
                    $payload = $sanitizer->sanitizeStaffRow($payload);
                    $payload['score'] = $currentScore;
                    $payload['scoreBreakdown'] = $currentBreakdown;
                    $payloadJson = $this->encodeJson($payload);
                }

                $updated++;
                $affectedDates[(string) $row->snapshot_date] = true;

                if ($dryRun) {
                    continue;
                }

                DB::table('workload_daily_staff_snapshots')
                    ->where('id', $row->id)
                    ->update([
                        'score' => $currentScore,
                        'completed_in_period' => 0,
                        'late_completed_in_period' => 0,
                        'score_breakdown_json' => $this->encodeJson($currentBreakdown),
                        'row_payload_json' => $payloadJson,
                        'updated_at' => now(),
                    ]);
            }

            if (! $dryRun) {
                foreach (array_keys($affectedDates) as $snapshotDate) {
                    $this->recalculateSnapshotTotals($snapshotDate);
                }
            }
        });

        $message = sprintf(
            '%s workload current-score normalization: %d row(s) %s, %d unchanged, %d skipped, %d snapshot day(s) affected.',
            $dryRun ? 'Dry run' : 'Completed',
            $updated,
            $dryRun ? 'would be updated' : 'updated',
            $unchanged,
            $skipped,
            count($affectedDates),
        );

        $this->info($message);

        return self::SUCCESS;
    }

    private function recalculateSnapshotTotals(string $snapshotDate): void
    {
        $summary = DB::table('workload_daily_staff_snapshots')
            ->whereDate('snapshot_date', $snapshotDate)
            ->selectRaw('COUNT(*) as staff_count, COALESCE(SUM(score), 0) as total_score')
            ->first();

        $staffCount = (int) ($summary->staff_count ?? 0);
        $totalScore = round((float) ($summary->total_score ?? 0), 2);
        $avgScore = $staffCount > 0 ? round($totalScore / $staffCount, 2) : 0.0;

        DB::table('workload_daily_snapshots')
            ->whereDate('snapshot_date', $snapshotDate)
            ->update([
                'total_score' => $totalScore,
                'avg_score' => $avgScore,
                'total_completed_in_period' => 0,
                'updated_at' => now(),
            ]);
    }

    private function dateOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw new \InvalidArgumentException("--{$name} must be a valid YYYY-MM-DD date.");
        }
    }

    private function decodeJson(mixed $json): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode((string) $json, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
