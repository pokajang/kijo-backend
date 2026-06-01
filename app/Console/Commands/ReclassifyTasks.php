<?php

namespace App\Console\Commands;

use App\Services\Tasks\TaskAiClassificationService;
use App\Services\Tasks\TaskClassificationService;
use App\Services\Tasks\TaskLearnedClassificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReclassifyTasks extends Command
{
    protected $signature = 'tasks:reclassify {--dry-run : Report changes without updating rows} {--limit=500 : Maximum rows to scan} {--all : Reclassify every non-overridden task} {--ai : Use optional AI fallback for unclear or low-confidence rows}';

    protected $description = 'Backfill task classification metadata for legacy task rows.';

    public function handle(
        TaskClassificationService $classifier,
        TaskAiClassificationService $aiClassifier,
        TaskLearnedClassificationService $learnedClassifier,
    ): int {
        if (! Schema::hasTable('tasks')) {
            $this->error('The tasks table does not exist.');

            return self::FAILURE;
        }

        $requiredColumns = [
            'title',
            'task_category',
            'effort_score',
            'classification_confidence',
            'classification_source',
            'user_override',
            'matched_pattern',
        ];

        foreach ($requiredColumns as $column) {
            if (! Schema::hasColumn('tasks', $column)) {
                $this->error("The tasks table is missing the {$column} column.");

                return self::FAILURE;
            }
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $includeAll = (bool) $this->option('all');
        $useAi = (bool) $this->option('ai');
        $supportsWorkType = Schema::hasColumn('tasks', 'work_type')
            && Schema::hasColumn('tasks', 'work_type_confidence')
            && Schema::hasColumn('tasks', 'work_type_matched_pattern');

        $query = DB::table('tasks')
            ->select([
                'id',
                'title',
                'task_category',
                'effort_score',
                'classification_confidence',
                'classification_source',
                'user_override',
                'matched_pattern',
                $supportsWorkType ? 'work_type' : DB::raw("'unclear' as work_type"),
                $supportsWorkType ? 'work_type_confidence' : DB::raw("'low' as work_type_confidence"),
                $supportsWorkType ? 'work_type_matched_pattern' : DB::raw('NULL as work_type_matched_pattern'),
            ])
            ->where(function ($overrideQuery): void {
                $overrideQuery
                    ->whereNull('user_override')
                    ->orWhere('user_override', false)
                    ->orWhere('user_override', 0);
            })
            ->orderBy('id')
            ->limit($limit);

        if (! $includeAll) {
            $query->where(function ($candidateQuery) use ($supportsWorkType): void {
                $candidateQuery
                    ->whereNull('task_category')
                    ->orWhere('task_category', '')
                    ->orWhere('task_category', 'uncategorised')
                    ->orWhereNull('classification_confidence')
                    ->orWhere('classification_confidence', '')
                    ->orWhere('classification_confidence', 'low')
                    ->orWhereNull('matched_pattern')
                    ->orWhere('matched_pattern', '');
                if ($supportsWorkType) {
                    $candidateQuery
                        ->orWhereNull('work_type')
                        ->orWhere('work_type', '')
                        ->orWhere('work_type', 'unclear')
                        ->orWhereNull('work_type_confidence')
                        ->orWhere('work_type_confidence', '');
                }
            });
        }

        $summary = [
            'scanned' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'learned_used' => 0,
            'ai_attempted' => 0,
            'ai_applied' => 0,
            'ai_failed' => 0,
            'dryRun' => $dryRun,
        ];

        foreach ($query->get() as $task) {
            $summary['scanned']++;
            $title = trim((string) ($task->title ?? ''));

            if ($title === '') {
                $summary['skipped']++;

                continue;
            }

            $classification = $classifier->classifyTitle($title);
            if (($classification['classification_origin'] ?? '') === 'learned_cache') {
                $summary['learned_used']++;
            }
            $aiApplied = false;
            if ($useAi && $aiClassifier->shouldAttemptAiFallback($classification)) {
                $summary['ai_attempted']++;
                $aiClassification = $aiClassifier->classifyTitle($title, $classification);
                if ($aiClassification !== null) {
                    $classification = $aiClassification;
                    $aiApplied = true;
                    $summary['ai_applied']++;
                } else {
                    $summary['ai_failed']++;
                }
            }

            $updates = $classifier->insertColumns($classification, $supportsWorkType);

            if (! $this->classificationChanged($task, $updates)) {
                $summary['unchanged']++;

                continue;
            }

            $summary['changed']++;
            if (! $dryRun) {
                DB::table('tasks')
                    ->where('id', (int) $task->id)
                    ->update($updates);
                if ($aiApplied) {
                    $learnedClassifier->remember(
                        $title,
                        $classifier->normalizedTitleForLearning($title),
                        $classification,
                    );
                }
            }
        }

        $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function classificationChanged(object $task, array $updates): bool
    {
        return (string) ($task->task_category ?? '') !== (string) $updates['task_category']
            || (float) ($task->effort_score ?? 0) !== (float) $updates['effort_score']
            || (string) ($task->classification_confidence ?? '') !== (string) $updates['classification_confidence']
            || (string) ($task->classification_source ?? '') !== (string) $updates['classification_source']
            || (bool) ($task->user_override ?? false) !== (bool) $updates['user_override']
            || (string) ($task->matched_pattern ?? '') !== (string) ($updates['matched_pattern'] ?? '')
            || (string) ($task->work_type ?? '') !== (string) ($updates['work_type'] ?? '')
            || (string) ($task->work_type_confidence ?? '') !== (string) ($updates['work_type_confidence'] ?? '')
            || (string) ($task->work_type_matched_pattern ?? '') !== (string) ($updates['work_type_matched_pattern'] ?? '');
    }
}
