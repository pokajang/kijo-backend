<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneInAppNotifications extends Command
{
    protected $signature = 'notifications:prune {--days= : Delete resolved/consumed rows older than this many days (defaults to NOTIFICATIONS_PRUNE_DAYS or 90)} {--dry-run : Report without deleting}';

    protected $description = 'Delete in-app notifications that have been resolved or consumed beyond the retention window. Active (unresolved, unconsumed) rows are never removed.';

    private const TABLE = 'in_app_notifications';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?? env('NOTIFICATIONS_PRUNE_DAYS', 90)));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subDays($days);

        if (! Schema::hasTable(self::TABLE)) {
            $this->warn(self::TABLE.' table not found; nothing to prune.');

            return self::SUCCESS;
        }

        // Only rows that are no longer actionable (resolved OR consumed) AND have
        // been idle past the cutoff. Active rows (both timestamps null) are kept.
        $query = DB::table(self::TABLE)
            ->where(function ($scoped): void {
                $scoped
                    ->whereNotNull('resolved_at')
                    ->orWhereNotNull('consumed_at');
            })
            ->where('updated_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No in-app notifications to prune.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[dry-run] Would delete {$count} in-app notification(s) idle since {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $query->delete();

        $this->info("Pruned {$count} in-app notification(s) idle since {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
