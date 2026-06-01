<?php

namespace App\Console\Commands;

use App\Services\Stats\WorkloadDailySnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CaptureDailyWorkloadSnapshot extends Command
{
    protected $signature = 'workload:capture-daily {--date=} {--force} {--start-date=} {--end-date=} {--repair-only}';

    protected $description = 'Capture the daily workload dashboard snapshot for graph history.';

    public function handle(WorkloadDailySnapshotService $snapshots): int
    {
        $startOption = trim((string) $this->option('start-date'));
        $endOption = trim((string) $this->option('end-date'));
        if ($startOption !== '' || $endOption !== '') {
            return $this->handleRangeReplay($snapshots, $startOption, $endOption);
        }

        if ((bool) $this->option('repair-only')) {
            $this->error('--repair-only requires --start-date and --end-date.');

            return self::FAILURE;
        }

        $dateOption = trim((string) $this->option('date'));
        try {
            $snapshotDate = $dateOption !== '' ? Carbon::parse($dateOption)->toDateString() : Carbon::today()->toDateString();
        } catch (\Throwable) {
            $this->error('Invalid workload snapshot date. Use YYYY-MM-DD.');

            return self::FAILURE;
        }

        $result = $snapshots->capture($snapshotDate, (bool) $this->option('force'));

        if (($result['status'] ?? '') === 'skipped') {
            $this->info("Workload snapshot {$result['snapshotDate']} already exists; skipped ({$result['staffCount']} staff row(s)).");

            return self::SUCCESS;
        }

        $this->info("Captured workload snapshot {$result['snapshotDate']} ({$result['staffCount']} staff row(s)).");

        return self::SUCCESS;
    }

    private function handleRangeReplay(WorkloadDailySnapshotService $snapshots, string $startOption, string $endOption): int
    {
        if (! (bool) $this->option('repair-only')) {
            $this->error('Range replay requires --repair-only.');

            return self::FAILURE;
        }

        if ((bool) $this->option('force')) {
            $this->error('--force is not allowed with range replay.');

            return self::FAILURE;
        }

        if (trim((string) $this->option('date')) !== '') {
            $this->error('--date cannot be combined with --start-date/--end-date.');

            return self::FAILURE;
        }

        if ($startOption === '' || $endOption === '') {
            $this->error('Range replay requires both --start-date and --end-date.');

            return self::FAILURE;
        }

        try {
            $startDate = Carbon::parse($startOption)->startOfDay();
            $endDate = Carbon::parse($endOption)->startOfDay();
        } catch (\Throwable) {
            $this->error('Invalid workload snapshot replay range. Use YYYY-MM-DD dates.');

            return self::FAILURE;
        }

        if ($startDate->gt($endDate)) {
            $this->error('--start-date must be on or before --end-date.');

            return self::FAILURE;
        }

        if ($endDate->gt(Carbon::today()->startOfDay())) {
            $this->error('--end-date cannot be in the future.');

            return self::FAILURE;
        }

        $dayCount = $startDate->diffInDays($endDate) + 1;
        if ($dayCount > 31) {
            $this->error('Range replay is limited to 31 calendar days.');

            return self::FAILURE;
        }

        $earliestReplayDate = Carbon::today()->startOfDay()->subDays(30);
        if ($startDate->lt($earliestReplayDate)) {
            $this->error('Range replay is limited to the latest 31-day window.');

            return self::FAILURE;
        }

        $captured = 0;
        $skipped = 0;
        $failed = 0;
        $date = $startDate->copy();
        $this->info("Replaying workload snapshots {$startDate->toDateString()} to {$endDate->toDateString()} ({$dayCount} day(s)).");

        while ($date->lte($endDate)) {
            $snapshotDate = $date->toDateString();
            try {
                $result = $snapshots->capture($snapshotDate, false, [
                    'captureMode' => 'reconstructed',
                    'capturedByCommand' => 'workload:capture-daily --repair-only',
                    'captureNote' => 'Limited one-month reconstructed replay. Existing snapshots are preserved.',
                ]);

                if (($result['status'] ?? '') === 'skipped') {
                    $skipped++;
                    $this->line("SKIPPED {$snapshotDate} ({$result['staffCount']} existing staff row(s)).");
                } else {
                    $captured++;
                    $this->line("CAPTURED {$snapshotDate} ({$result['staffCount']} staff row(s)).");
                }
            } catch (\Throwable $exception) {
                $failed++;
                $this->error("FAILED {$snapshotDate}: {$exception->getMessage()}");
            }

            $date->addDay();
        }

        $this->info("Replay summary: captured {$captured}, skipped {$skipped}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
