<?php

namespace App\Jobs;

use App\Services\Tasks\TaskAiClassificationService;
use App\Services\Tasks\TaskClassificationService;
use App\Services\Tasks\TaskLearnedClassificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnrichTaskClassificationWithAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly int $taskId) {}

    public function taskId(): int
    {
        return $this->taskId;
    }

    public function handle(
        TaskAiClassificationService $aiClassifier,
        TaskClassificationService $localClassifier,
        TaskLearnedClassificationService $learnedClassifier,
    ): void {
        if (! $this->supportsTaskClassification()) {
            return;
        }

        if (! $aiClassifier->enabled()) {
            $this->updateAiLifecycle('failed', 'ai_disabled_or_unconfigured');
            return;
        }

        $task = DB::table('tasks')
            ->select([
                'id',
                'title',
                'task_category',
                'effort_score',
                'classification_confidence',
                'classification_source',
                'user_override',
                'matched_pattern',
                'work_type',
                'work_type_confidence',
                'work_type_matched_pattern',
            ])
            ->where('id', $this->taskId)
            ->first();

        if ($task === null) {
            return;
        }

        if ((bool) ($task->user_override ?? false)) {
            $this->updateAiLifecycle('not_applicable');
            return;
        }

        $title = trim((string) ($task->title ?? ''));
        if ($title === '') {
            $this->updateAiLifecycle('no_result');
            return;
        }

        $this->updateAiLifecycle('processing');

        $currentClassification = [
            'task_category' => (string) ($task->task_category ?? ''),
            'effort_score' => (float) ($task->effort_score ?? 0),
            'classification_confidence' => (string) ($task->classification_confidence ?? 'low'),
            'classification_source' => (string) ($task->classification_source ?? 'system'),
            'user_override' => false,
            'matched_pattern' => $task->matched_pattern ?? null,
            'work_type' => (string) ($task->work_type ?? 'unclear'),
            'work_type_confidence' => (string) ($task->work_type_confidence ?? 'low'),
            'work_type_matched_pattern' => $task->work_type_matched_pattern ?? null,
        ];

        if (($currentClassification['task_category'] ?? '') === '') {
            $currentClassification = $localClassifier->classifyTitle($title);
        }

        try {
            $aiClassification = $aiClassifier->classifyTitle($title, $currentClassification);
        } catch (\Throwable $exception) {
            $this->updateAiLifecycle('failed', $this->sanitizeError($exception->getMessage()));

            return;
        }

        if ($aiClassification === null) {
            $this->updateAiLifecycle('no_result');
            return;
        }

        $updates = $localClassifier->insertColumns($aiClassification, true);
        if ($this->supportsAiLifecycleStorage()) {
            $updates = array_merge($updates, $this->lifecycleColumns('applied'));
        }

        $updated = DB::table('tasks')
            ->where('id', $this->taskId)
            ->where(function ($query): void {
                $query
                    ->whereNull('user_override')
                    ->orWhere('user_override', false)
                    ->orWhere('user_override', 0);
            })
            ->update($updates);

        if ($updated > 0) {
            $learnedClassifier->remember(
                $title,
                $localClassifier->normalizedTitleForLearning($title),
                $aiClassification,
            );
        }
    }

    private function supportsTaskClassification(): bool
    {
        return Schema::hasTable('tasks')
            && Schema::hasColumn('tasks', 'task_category')
            && Schema::hasColumn('tasks', 'effort_score')
            && Schema::hasColumn('tasks', 'classification_confidence')
            && Schema::hasColumn('tasks', 'classification_source')
            && Schema::hasColumn('tasks', 'user_override')
            && Schema::hasColumn('tasks', 'matched_pattern')
            && Schema::hasColumn('tasks', 'work_type')
            && Schema::hasColumn('tasks', 'work_type_confidence')
            && Schema::hasColumn('tasks', 'work_type_matched_pattern');
    }

    private function supportsAiLifecycleStorage(): bool
    {
        return Schema::hasColumn('tasks', 'ai_classification_status')
            && Schema::hasColumn('tasks', 'ai_classification_queued_at')
            && Schema::hasColumn('tasks', 'ai_classification_started_at')
            && Schema::hasColumn('tasks', 'ai_classification_completed_at')
            && Schema::hasColumn('tasks', 'ai_classification_error');
    }

    private function updateAiLifecycle(string $status, ?string $error = null): void
    {
        if (! $this->supportsAiLifecycleStorage()) {
            return;
        }

        DB::table('tasks')
            ->where('id', $this->taskId)
            ->update($this->lifecycleColumns($status, $error));
    }

    private function lifecycleColumns(string $status, ?string $error = null): array
    {
        $now = now();
        $terminal = in_array($status, ['applied', 'cached', 'no_result', 'failed', 'not_applicable', 'stale'], true);

        $columns = [
            'ai_classification_status' => $status,
            'ai_classification_error' => $status === 'failed' ? $this->sanitizeError($error) : null,
        ];

        if ($status === 'processing') {
            $columns['ai_classification_started_at'] = $now;
            $columns['ai_classification_completed_at'] = null;
        }

        if ($terminal) {
            $columns['ai_classification_completed_at'] = $now;
        }

        return $columns;
    }

    private function sanitizeError(?string $error): string
    {
        $message = preg_replace('/\s+/', ' ', trim((string) $error)) ?: 'ai_classification_failed';

        return substr($message, 0, 255);
    }
}
