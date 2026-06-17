<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\Tasks\TaskClassificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminTaskClassificationExampleController extends Controller
{
    private const TABLE = 'task_classification_examples';

    public function __construct(
        private AuditLogService $auditLog,
        private TaskClassificationService $classifier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->available()) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'available' => false,
                    'examples' => [],
                    'total' => 0,
                ],
            ]);
        }

        $search = trim((string) $request->query('search', ''));
        $taskCategory = $this->stringFilter($request->query('taskCategory', $request->query('task_category')));
        $workType = $this->stringFilter($request->query('workType', $request->query('work_type')));
        $confidence = $this->stringFilter($request->query('confidence'));
        $source = $this->stringFilter($request->query('source'));
        $affected = $this->stringFilter($request->query('affected'));
        $perPage = $this->perPage($request->query('perPage', $request->query('per_page', $request->query('limit', 25))));
        $page = max(1, (int) $request->query('page', 1));
        $query = DB::table(self::TABLE);
        $affectedCounts = $this->affectedTaskCounts();

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function ($where) use ($like): void {
                $where
                    ->where('normalized_title', 'like', $like)
                    ->orWhere('sample_title', 'like', $like)
                    ->orWhere('task_category', 'like', $like)
                    ->orWhere('work_type', 'like', $like)
                    ->orWhere('matched_pattern', 'like', $like)
                    ->orWhere('work_type_matched_pattern', 'like', $like);
            });
        }

        if ($taskCategory !== '') {
            $query->where('task_category', $taskCategory);
        }

        if ($workType !== '') {
            $query->where('work_type', $workType);
        }

        if ($confidence !== '') {
            $query->where('classification_confidence', $confidence);
        }

        if ($source !== '') {
            $query->where('classification_source', $source);
        }

        if ($affected === 'with') {
            $titles = array_keys(array_filter($affectedCounts, fn (int $count): bool => $count > 0));
            if (empty($titles)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('normalized_title', $titles);
            }
        } elseif ($affected === 'without') {
            $titles = array_keys(array_filter($affectedCounts, fn (int $count): bool => $count > 0));
            if (! empty($titles)) {
                $query->whereNotIn('normalized_title', $titles);
            }
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $examples = $query
            ->orderByDesc('last_seen_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (object $row): array => $this->formatRow($row, $affectedCounts))
            ->all();

        return response()->json([
            'status' => 'success',
            'data' => [
                'available' => true,
                'examples' => $examples,
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'lastPage' => $lastPage,
            ],
        ]);
    }

    public function health(): JsonResponse
    {
        $taskStats = $this->taskHealthStats();
        $learnedStats = $this->learnedHealthStats();

        return response()->json([
            'status' => 'success',
            'data' => [
                'available' => $learnedStats['available'],
                'totalClassifiedTasks' => $taskStats['totalClassifiedTasks'],
                'unclearUnratedTasks' => $taskStats['unclearUnratedTasks'],
                'nonWorkTasks' => $taskStats['nonWorkTasks'],
                'lowConfidenceTasks' => $taskStats['lowConfidenceTasks'],
                'aiClassifiedTasks' => $taskStats['aiClassifiedTasks'],
                'learnedCacheTasks' => $taskStats['learnedCacheTasks'],
                'learnedCacheRows' => $learnedStats['learnedCacheRows'],
                'learnedCacheUsage' => $learnedStats['learnedCacheUsage'],
            ],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->available()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Learned classification storage is not available. Run migrations first.',
            ], 404);
        }

        $row = DB::table(self::TABLE)->where('id', $id)->first();
        if ($row === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Learned classification was not found.',
            ], 404);
        }

        $deleted = DB::table(self::TABLE)->where('id', $id)->delete();
        if ($deleted < 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Learned classification was not found.',
            ], 404);
        }

        $this->auditLog->log(
            $request,
            sprintf(
                'Deleted learned workload classification #%d: %s [%s, %s, usage %d]',
                (int) $row->id,
                (string) ($row->normalized_title ?? ''),
                (string) ($row->task_category ?? ''),
                (string) ($row->work_type ?? ''),
                (int) ($row->usage_count ?? 0),
            )
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Learned classification deleted. Existing tasks were not changed.',
        ]);
    }

    private function formatRow(object $row, array $affectedCounts): array
    {
        $workType = TaskClassificationService::normalizeWorkType((string) ($row->work_type ?? 'unclear'));
        $normalizedTitle = (string) ($row->normalized_title ?? '');

        return [
            'id' => (int) $row->id,
            'normalizedTitle' => $normalizedTitle,
            'sampleTitle' => (string) ($row->sample_title ?? ''),
            'taskCategory' => (string) ($row->task_category ?? ''),
            'taskCategoryLabel' => $this->taskCategoryLabel((string) ($row->task_category ?? '')),
            'effortScore' => (float) ($row->effort_score ?? 0),
            'classificationConfidence' => (string) ($row->classification_confidence ?? ''),
            'classificationSource' => (string) ($row->classification_source ?? ''),
            'matchedPattern' => $row->matched_pattern !== null ? (string) $row->matched_pattern : null,
            'workType' => $workType,
            'workTypeLabel' => TaskClassificationService::workTypeLabel($workType),
            'workTypeConfidence' => (string) ($row->work_type_confidence ?? ''),
            'workTypeMatchedPattern' => $row->work_type_matched_pattern !== null ? (string) $row->work_type_matched_pattern : null,
            'usageCount' => (int) ($row->usage_count ?? 0),
            'affectedTaskCount' => (int) ($affectedCounts[$normalizedTitle] ?? 0),
            'lastSeenAt' => $this->timestamp($row->last_seen_at ?? null),
            'createdAt' => $this->timestamp($row->created_at ?? null),
            'updatedAt' => $this->timestamp($row->updated_at ?? null),
        ];
    }

    private function taskCategoryLabel(string $category): string
    {
        $definitions = TaskClassificationService::taskCategoryDefinitions();

        return (string) ($definitions[$category]['label'] ?? $category);
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toISOString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function available(): bool
    {
        return Schema::hasTable(self::TABLE)
            && Schema::hasColumn(self::TABLE, 'id')
            && Schema::hasColumn(self::TABLE, 'normalized_title')
            && Schema::hasColumn(self::TABLE, 'task_category')
            && Schema::hasColumn(self::TABLE, 'work_type');
    }

    private function perPage(mixed $value): int
    {
        $perPage = (int) $value;

        return in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
    }

    private function stringFilter(mixed $value): string
    {
        $value = trim((string) $value);

        return $value === 'all' ? '' : $value;
    }

    private function affectedTaskCounts(): array
    {
        if (! Schema::hasTable('tasks') || ! Schema::hasColumn('tasks', 'title')) {
            return [];
        }

        try {
            $counts = [];
            DB::table('tasks')
                ->select('title')
                ->whereNotNull('title')
                ->orderBy('id')
                ->chunk(500, function ($tasks) use (&$counts): void {
                    foreach ($tasks as $task) {
                        $normalized = $this->classifier->normalizedTitleForLearning((string) ($task->title ?? ''));
                        if ($normalized === '') {
                            continue;
                        }
                        $counts[$normalized] = (int) ($counts[$normalized] ?? 0) + 1;
                    }
                });

            return $counts;
        } catch (\Throwable) {
            return [];
        }
    }

    private function taskHealthStats(): array
    {
        $defaults = [
            'totalClassifiedTasks' => 0,
            'unclearUnratedTasks' => 0,
            'nonWorkTasks' => 0,
            'lowConfidenceTasks' => 0,
            'aiClassifiedTasks' => 0,
            'learnedCacheTasks' => 0,
            'aiLifecycle' => [
                'queued' => 0,
                'processing' => 0,
                'stale' => 0,
                'applied' => 0,
                'cached' => 0,
                'no_result' => 0,
                'failed' => 0,
                'not_applicable' => 0,
            ],
            'queueBacklog' => 0,
        ];

        if (! Schema::hasTable('tasks')) {
            return $defaults;
        }

        $hasCategory = Schema::hasColumn('tasks', 'task_category');
        $hasConfidence = Schema::hasColumn('tasks', 'classification_confidence');
        $hasSource = Schema::hasColumn('tasks', 'classification_source');
        $hasLifecycleStatus = Schema::hasColumn('tasks', 'ai_classification_status');
        $hasLifecycleQueuedAt = Schema::hasColumn('tasks', 'ai_classification_queued_at');
        $hasLifecycleStartedAt = Schema::hasColumn('tasks', 'ai_classification_started_at');

        try {
            $aiLifecycle = $defaults['aiLifecycle'];
            if ($hasLifecycleStatus) {
                $counts = DB::table('tasks')
                    ->select('ai_classification_status', DB::raw('COUNT(*) as total'))
                    ->whereNotNull('ai_classification_status')
                    ->groupBy('ai_classification_status')
                    ->pluck('total', 'ai_classification_status');

                foreach ($aiLifecycle as $status => $_) {
                    $aiLifecycle[$status] = (int) ($counts[$status] ?? 0);
                }

                $staleQueued = $hasLifecycleQueuedAt
                    ? (int) DB::table('tasks')
                        ->where('ai_classification_status', 'queued')
                        ->where('ai_classification_queued_at', '<', now()->subMinutes(10))
                        ->count()
                    : 0;
                $staleProcessing = $hasLifecycleStartedAt
                    ? (int) DB::table('tasks')
                        ->where('ai_classification_status', 'processing')
                        ->where('ai_classification_started_at', '<', now()->subMinutes(10))
                        ->count()
                    : 0;
                $aiLifecycle['stale'] = $staleQueued + $staleProcessing;
            }

            return [
                'totalClassifiedTasks' => $hasCategory
                    ? (int) DB::table('tasks')->whereNotNull('task_category')->where('task_category', '<>', '')->count()
                    : 0,
                'unclearUnratedTasks' => $hasCategory
                    ? (int) DB::table('tasks')->where('task_category', 'unclear_unrated')->count()
                    : 0,
                'nonWorkTasks' => $hasCategory
                    ? (int) DB::table('tasks')->where('task_category', 'non_work')->count()
                    : 0,
                'lowConfidenceTasks' => $hasConfidence
                    ? (int) DB::table('tasks')->where('classification_confidence', 'low')->count()
                    : 0,
                'aiClassifiedTasks' => $hasSource
                    ? (int) DB::table('tasks')->where('classification_source', 'ai')->count()
                    : 0,
                'learnedCacheTasks' => $hasSource
                    ? (int) DB::table('tasks')->where('classification_source', 'ai_cache')->count()
                    : 0,
                'aiLifecycle' => $aiLifecycle,
                'queueBacklog' => Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0,
            ];
        } catch (\Throwable) {
            return $defaults;
        }
    }

    private function learnedHealthStats(): array
    {
        if (! $this->available()) {
            return [
                'available' => false,
                'learnedCacheRows' => 0,
                'learnedCacheUsage' => 0,
            ];
        }

        try {
            return [
                'available' => true,
                'learnedCacheRows' => (int) DB::table(self::TABLE)->count(),
                'learnedCacheUsage' => (int) DB::table(self::TABLE)->sum('usage_count'),
            ];
        } catch (\Throwable) {
            return [
                'available' => false,
                'learnedCacheRows' => 0,
                'learnedCacheUsage' => 0,
            ];
        }
    }
}
