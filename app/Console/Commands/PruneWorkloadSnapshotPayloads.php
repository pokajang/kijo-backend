<?php

namespace App\Console\Commands;

use App\Services\Stats\WorkloadSnapshotHealthService;
use Illuminate\Console\Command;

class PruneWorkloadSnapshotPayloads extends Command
{
    protected $signature = 'workload:prune-snapshot-payloads {--older-than-days=180}';

    protected $description = 'Prune large retained workload daily snapshot evidence payloads.';

    public function handle(WorkloadSnapshotHealthService $health): int
    {
        $olderThanDays = (int) $this->option('older-than-days');
        if ($olderThanDays < 1) {
            $this->error('--older-than-days must be greater than zero.');

            return self::FAILURE;
        }

        $result = $health->prunePayloads($olderThanDays);
        if (($result['status'] ?? '') === 'unavailable') {
            $this->warn('Daily workload snapshot tables are unavailable; nothing pruned.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Pruned workload snapshot payloads older than %d days before %s (%d aggregate, %d staff row payloads).',
            (int) $result['olderThanDays'],
            (string) $result['cutoffDate'],
            (int) $result['aggregatePayloadsPruned'],
            (int) $result['staffPayloadsPruned'],
        ));

        return self::SUCCESS;
    }
}
