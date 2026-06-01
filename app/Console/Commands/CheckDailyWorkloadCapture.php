<?php

namespace App\Console\Commands;

use App\Services\Stats\WorkloadSnapshotHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CheckDailyWorkloadCapture extends Command
{
    protected $signature = 'workload:check-daily-capture {--date=}';

    protected $description = 'Check that the scheduled daily workload snapshot was captured.';

    public function handle(WorkloadSnapshotHealthService $health): int
    {
        $dateOption = trim((string) $this->option('date'));
        try {
            $expectedDate = $dateOption !== '' ? Carbon::parse($dateOption)->toDateString() : Carbon::yesterday()->toDateString();
        } catch (\Throwable) {
            $this->error('Invalid workload snapshot check date. Use YYYY-MM-DD.');

            return self::FAILURE;
        }

        $result = $health->checkDailyCapture($expectedDate);
        $status = (string) ($result['status'] ?? 'unavailable');

        if ($status === 'ok') {
            $this->info("Daily workload snapshot {$expectedDate} exists.");

            return self::SUCCESS;
        }

        if ($status === 'missing') {
            $notified = count($result['notifiedStaffIds'] ?? []);
            $this->error("Daily workload snapshot {$expectedDate} is missing; notified {$notified} System Admin user(s).");

            return self::SUCCESS;
        }

        $this->warn('Daily workload snapshot health checks are unavailable.');

        return self::SUCCESS;
    }
}
