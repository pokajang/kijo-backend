<?php

namespace App\Console\Commands;

use App\Jobs\EnrichTaskClassificationWithAiJob;
use App\Services\Tasks\TaskAiClassificationService;
use App\Services\Tasks\TaskClassificationService;
use DateTimeInterface;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MaintainTaskAiClassification extends Command
{
    protected $signature = 'tasks:ai-classification-maintain
        {--dry-run : Report candidate work without updating rows or dispatching jobs}
        {--limit=50 : Maximum recoverable rows to process}
        {--older-than=10 : Minimum age in minutes before a row can be recovered}
        {--requeue : Requeue unresolved AI classification rows}
        {--mark-no-result : Mark unresolved AI classification rows as no_result}';

    protected $description = 'Recover stale task AI classification lifecycle rows.';

    private const ACTIVE_STATUSES = ['queued', 'processing', 'stale'];

    public function handle(TaskAiClassificationService $aiClassifier): int
    {
        if (! $this->supportsRequiredColumns()) {
            $this->error('Task AI classification lifecycle columns are not available.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $olderThan = max(0, (int) $this->option('older-than'));
        $mode = $this->resolveMode();
        if ($mode === null) {
            $this->error('Choose either --requeue or --mark-no-result, not both.');

            return self::FAILURE;
        }
        $cutoff = now()->subMinutes($olderThan);

        $summary = [
            'scanned' => 0,
            'requeued' => 0,
            'markedNoResult' => 0,
            'skipped' => 0,
            'dryRun' => $dryRun,
        ];

        $lastScannedId = 0;
        $processed = 0;

        while ($processed < $limit) {
            $rows = $this->candidateQuery($cutoff)
                ->where('id', '>', $lastScannedId)
                ->orderBy('id')
                ->limit($limit - $processed)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $task) {
                $lastScannedId = (int) $task->id;
                $summary['scanned']++;

                if (! $this->isRecoverableCandidate($task, $aiClassifier, $cutoff)) {
                    $summary['skipped']++;

                    continue;
                }

                if ($mode === 'mark-no-result') {
                    $summary['markedNoResult']++;
                    $processed++;
                    if (! $dryRun) {
                        $this->markNoResult((int) $task->id);
                    }

                    continue;
                }

                $summary['requeued']++;
                $processed++;
                if (! $dryRun) {
                    $this->requeue((int) $task->id);
                }
            }
        }

        $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function resolveMode(): ?string
    {
        if ((bool) $this->option('requeue') && (bool) $this->option('mark-no-result')) {
            return null;
        }

        if ((bool) $this->option('mark-no-result')) {
            return 'mark-no-result';
        }

        return 'requeue';
    }

    private function candidateQuery(Carbon $cutoff)
    {
        $knownWorkTypes = array_keys(TaskClassificationService::workTypeDefinitions());

        return DB::table('tasks')
            ->select([
                'id',
                'title',
                'task_category',
                'effort_score',
                'classification_confidence',
                'classification_source',
                'user_override',
                'work_type',
                'ai_classification_status',
                'ai_classification_queued_at',
                'ai_classification_started_at',
                'created_at',
            ])
            ->where(function ($overrideQuery): void {
                $overrideQuery
                    ->whereNull('user_override')
                    ->orWhere('user_override', false)
                    ->orWhere('user_override', 0);
            })
            ->whereRaw("TRIM(COALESCE(title, '')) <> ''")
            ->where(function ($query) use ($cutoff, $knownWorkTypes): void {
                $query
                    ->where(function ($queued) use ($cutoff): void {
                        $queued
                            ->where('ai_classification_status', 'queued')
                            ->where(function ($age) use ($cutoff): void {
                                $age
                                    ->where('ai_classification_queued_at', '<=', $cutoff)
                                    ->orWhere(function ($fallback) use ($cutoff): void {
                                        $fallback
                                            ->whereNull('ai_classification_queued_at')
                                            ->where('created_at', '<=', $cutoff);
                                    });
                            });
                    })
                    ->orWhere(function ($processing) use ($cutoff): void {
                        $processing
                            ->whereIn('ai_classification_status', ['processing', 'stale'])
                            ->where(function ($age) use ($cutoff): void {
                                $age
                                    ->where('ai_classification_started_at', '<=', $cutoff)
                                    ->orWhere(function ($queuedFallback) use ($cutoff): void {
                                        $queuedFallback
                                            ->whereNull('ai_classification_started_at')
                                            ->where('ai_classification_queued_at', '<=', $cutoff);
                                    })
                                    ->orWhere(function ($createdFallback) use ($cutoff): void {
                                        $createdFallback
                                            ->whereNull('ai_classification_started_at')
                                            ->whereNull('ai_classification_queued_at')
                                            ->where('created_at', '<=', $cutoff);
                                    });
                            });
                    })
                    ->orWhere(function ($legacy) use ($cutoff, $knownWorkTypes): void {
                        $legacy
                            ->whereNull('ai_classification_status')
                            ->where('created_at', '<=', $cutoff)
                            ->where(function ($source): void {
                                $source
                                    ->whereNull('classification_source')
                                    ->orWhereNotIn('classification_source', ['ai', 'ai_cache']);
                            })
                            ->where(function ($category): void {
                                $category
                                    ->whereNull('task_category')
                                    ->orWhere('task_category', '<>', 'non_work');
                            })
                            ->where(function ($eligible) use ($knownWorkTypes): void {
                                $eligible
                                    ->where('task_category', 'unclear_unrated')
                                    ->orWhere(function ($uncategorised): void {
                                        $uncategorised
                                            ->where('task_category', 'uncategorised')
                                            ->where(function ($confidence): void {
                                                $confidence
                                                    ->whereNull('classification_confidence')
                                                    ->orWhere('classification_confidence', 'low');
                                            });
                                    })
                                    ->orWhere(function ($workType) use ($knownWorkTypes): void {
                                        $workType
                                            ->whereNull('work_type')
                                            ->orWhere('work_type', 'unclear')
                                            ->orWhereNotIn('work_type', $knownWorkTypes);
                                    });
                            });
                    });
            });
    }

    private function isRecoverableCandidate(
        object $task,
        TaskAiClassificationService $aiClassifier,
        Carbon $cutoff,
    ): bool {
        if ((bool) ($task->user_override ?? false)) {
            return false;
        }

        if (trim((string) ($task->title ?? '')) === '') {
            return false;
        }

        if (! $this->isOldEnough($task, $cutoff)) {
            return false;
        }

        $status = trim((string) ($task->ai_classification_status ?? ''));
        if ($status !== '') {
            return in_array($status, self::ACTIVE_STATUSES, true);
        }

        return $aiClassifier->shouldAttemptAiFallback([
            'task_category' => (string) ($task->task_category ?? ''),
            'effort_score' => (float) ($task->effort_score ?? 0),
            'classification_confidence' => (string) ($task->classification_confidence ?? 'low'),
            'classification_source' => (string) ($task->classification_source ?? 'system'),
            'work_type' => (string) ($task->work_type ?? 'unclear'),
        ]);
    }

    private function isOldEnough(object $task, Carbon $cutoff): bool
    {
        $status = trim((string) ($task->ai_classification_status ?? ''));
        $timestamp = match ($status) {
            'queued' => $task->ai_classification_queued_at ?? $task->created_at ?? null,
            'processing' => $task->ai_classification_started_at ?? $task->ai_classification_queued_at ?? $task->created_at ?? null,
            'stale' => $task->ai_classification_started_at ?? $task->ai_classification_queued_at ?? $task->created_at ?? null,
            default => $task->created_at ?? null,
        };

        $parsedTimestamp = $this->parseStoredTimestamp($timestamp);
        if ($parsedTimestamp === null) {
            return false;
        }

        return $parsedTimestamp->lte($cutoff);
    }

    private function parseStoredTimestamp(mixed $timestamp): ?Carbon
    {
        if ($timestamp instanceof DateTimeInterface) {
            return Carbon::instance($timestamp);
        }

        $value = trim((string) $timestamp);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        foreach (['Y-m-d H:i:s.u', 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if (
                $parsed instanceof DateTimeImmutable
                && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
            ) {
                return Carbon::instance($parsed);
            }
        }

        return null;
    }

    private function requeue(int $taskId): void
    {
        DB::table('tasks')
            ->where('id', $taskId)
            ->update([
                'ai_classification_status' => 'queued',
                'ai_classification_queued_at' => now(),
                'ai_classification_started_at' => null,
                'ai_classification_completed_at' => null,
                'ai_classification_error' => null,
            ]);

        EnrichTaskClassificationWithAiJob::dispatch($taskId);
    }

    private function markNoResult(int $taskId): void
    {
        DB::table('tasks')
            ->where('id', $taskId)
            ->update([
                'ai_classification_status' => 'no_result',
                'ai_classification_completed_at' => now(),
                'ai_classification_error' => null,
            ]);
    }

    private function supportsRequiredColumns(): bool
    {
        foreach ([
            'id',
            'title',
            'task_category',
            'effort_score',
            'classification_confidence',
            'classification_source',
            'user_override',
            'work_type',
            'ai_classification_status',
            'ai_classification_queued_at',
            'ai_classification_started_at',
            'ai_classification_completed_at',
            'ai_classification_error',
            'created_at',
        ] as $column) {
            if (! Schema::hasColumn('tasks', $column)) {
                return false;
            }
        }

        return true;
    }
}
