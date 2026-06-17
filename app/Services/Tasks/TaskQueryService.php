<?php

namespace App\Services\Tasks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TaskQueryService extends TaskBaseService
{
    private function taskAiClassificationService(): TaskAiClassificationService
    {
        return app(TaskAiClassificationService::class);
    }

    public function getAllTasks(Request $request)
    {
        [$filterStaff, $staffError] = $this->taskStaffFilterFromRequest($request);
        if ($staffError) {
            return $staffError;
        }

        $start = trim((string) $request->query('start', ''));
        $end = trim((string) $request->query('end', ''));
        if ($dateError = $this->taskDateFilterError($start, $end)) {
            return $dateError;
        }

        [$year, $yearError] = $this->taskYearFilterFromRequest($request);
        if ($yearError) {
            return $yearError;
        }

        $query = DB::table('tasks as t')
            ->join('staff_general as s', 't.staff_id', '=', 's.staff_id');

        $projectColumns = $this->taskProjectSelectColumns($query);
        $classificationColumns = $this->taskClassificationSelectColumns();

        $query
            ->select(array_merge([
                't.id',
                't.staff_id',
                's.full_name',
                's.name_code',
                't.title',
                't.status',
                't.created_at',
                't.due_date',
                't.completed_at',
            ], $projectColumns, $classificationColumns))
            ->orderByDesc('t.created_at');

        if ($filterStaff > 0) {
            $query->where('t.staff_id', $filterStaff);
        }
        if ($start !== '') {
            $query->whereDate('t.created_at', '>=', $start);
        }
        if ($end !== '') {
            $query->whereDate('t.created_at', '<=', $end);
        }
        if ($year > 0) {
            $query->whereYear('t.created_at', $year);
        }

        $rows = $query->get();
        $taskIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $commentsByTask = $this->getCommentsByTaskIds($taskIds);

        $tasks = $rows->map(function ($r) use ($commentsByTask) {
            $id = (int) $r->id;

            return [
                'id' => $id,
                'staffId' => (int) $r->staff_id,
                'staffName' => (string) $r->full_name,
                'staffCode' => (string) $r->name_code,
                'projectId' => $r->project_id !== null ? (int) $r->project_id : null,
                'projectName' => (string) ($r->project_name ?? ''),
                'projectStatus' => (string) ($r->project_status ?? ''),
                'projectProgressId' => $r->project_progress_id !== null ? (int) $r->project_progress_id : null,
                'title' => (string) $r->title,
                'status' => (string) $r->status,
                'createdAt' => (string) $r->created_at,
                'dueDate' => (string) $r->due_date,
                'completedAt' => $r->completed_at ? (string) $r->completed_at : '',
                'commentLogs' => $commentsByTask[$id] ?? [],
            ] + $this->taskClassificationPayload($r);
        })->values();

        return response()->json([
            'status' => 'success',
            'tasks' => $tasks,
        ]);
    }

    public function getPersonalTasks(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated'], 401);
        }

        $start = trim((string) $request->query('start', ''));
        $end = trim((string) $request->query('end', ''));
        if ($dateError = $this->taskDateFilterError($start, $end)) {
            return $dateError;
        }

        [$year, $yearError] = $this->taskYearFilterFromRequest($request);
        if ($yearError) {
            return $yearError;
        }

        $query = DB::table('tasks as t')
            ->leftJoin('staff_general as s', 't.staff_id', '=', 's.staff_id');

        $projectColumns = $this->taskProjectSelectColumns($query);
        $classificationColumns = $this->taskClassificationSelectColumns();

        $query
            ->select(array_merge([
                't.id',
                't.title',
                't.status',
                't.created_at',
                't.due_date',
                't.completed_at',
                's.full_name',
                's.name_code',
            ], $projectColumns, $classificationColumns))
            ->where('t.staff_id', $staffId)
            ->orderByDesc('t.created_at');

        if ($start !== '') {
            $query->whereDate('t.created_at', '>=', $start);
        }
        if ($end !== '') {
            $query->whereDate('t.created_at', '<=', $end);
        }

        if ($year > 0) {
            $query->whereYear('t.created_at', $year);
        }

        $rows = $query->get();

        $taskIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $commentsByTask = $this->getCommentsByTaskIds($taskIds);

        $tasks = $rows->map(function ($r) use ($commentsByTask, $staffId) {
            $id = (int) $r->id;

            return [
                'id' => $id,
                'staffId' => $staffId,
                'staffName' => (string) ($r->full_name ?? ''),
                'staffCode' => (string) ($r->name_code ?? ''),
                'projectId' => $r->project_id !== null ? (int) $r->project_id : null,
                'projectName' => (string) ($r->project_name ?? ''),
                'projectStatus' => (string) ($r->project_status ?? ''),
                'projectProgressId' => $r->project_progress_id !== null ? (int) $r->project_progress_id : null,
                'title' => (string) $r->title,
                'status' => (string) $r->status,
                'createdAt' => (string) $r->created_at,
                'dueDate' => (string) $r->due_date,
                'completedAt' => $r->completed_at ? (string) $r->completed_at : '',
                'commentLogs' => $commentsByTask[$id] ?? [],
            ] + $this->taskClassificationPayload($r);
        })->values();

        return response()->json([
            'status' => 'success',
            'tasks' => $tasks,
        ]);
    }

    private function taskProjectSelectColumns($query): array
    {
        $hasProjectId = Schema::hasColumn('tasks', 'project_id');
        $hasProjectProgressId = Schema::hasColumn('tasks', 'project_progress_id');

        if ($hasProjectId && Schema::hasTable('projects_main')) {
            $query->leftJoin('projects_main as p', 'p.id', '=', 't.project_id');
        }

        return [
            $hasProjectId ? 't.project_id' : DB::raw('NULL as project_id'),
            $hasProjectProgressId
                ? 't.project_progress_id'
                : DB::raw('NULL as project_progress_id'),
            $hasProjectId && Schema::hasTable('projects_main')
                ? 'p.project_name'
                : DB::raw('NULL as project_name'),
            $hasProjectId && Schema::hasTable('projects_main')
                ? 'p.status as project_status'
                : DB::raw('NULL as project_status'),
        ];
    }

    private function taskClassificationSelectColumns(): array
    {
        return [
            Schema::hasColumn('tasks', 'task_category')
                ? 't.task_category'
                : DB::raw("'uncategorised' as task_category"),
            Schema::hasColumn('tasks', 'effort_score')
                ? 't.effort_score'
                : DB::raw('1 as effort_score'),
            Schema::hasColumn('tasks', 'classification_confidence')
                ? 't.classification_confidence'
                : DB::raw("'low' as classification_confidence"),
            Schema::hasColumn('tasks', 'classification_source')
                ? 't.classification_source'
                : DB::raw("'system' as classification_source"),
            Schema::hasColumn('tasks', 'user_override')
                ? 't.user_override'
                : DB::raw('0 as user_override'),
            Schema::hasColumn('tasks', 'matched_pattern')
                ? 't.matched_pattern'
                : DB::raw('NULL as matched_pattern'),
            Schema::hasColumn('tasks', 'work_type')
                ? 't.work_type'
                : DB::raw("'unclear' as work_type"),
            Schema::hasColumn('tasks', 'work_type_confidence')
                ? 't.work_type_confidence'
                : DB::raw("'low' as work_type_confidence"),
            Schema::hasColumn('tasks', 'work_type_matched_pattern')
                ? 't.work_type_matched_pattern'
                : DB::raw('NULL as work_type_matched_pattern'),
            Schema::hasColumn('tasks', 'ai_classification_status')
                ? 't.ai_classification_status'
                : DB::raw('NULL as ai_classification_status'),
            Schema::hasColumn('tasks', 'ai_classification_queued_at')
                ? 't.ai_classification_queued_at'
                : DB::raw('NULL as ai_classification_queued_at'),
            Schema::hasColumn('tasks', 'ai_classification_started_at')
                ? 't.ai_classification_started_at'
                : DB::raw('NULL as ai_classification_started_at'),
            Schema::hasColumn('tasks', 'ai_classification_completed_at')
                ? 't.ai_classification_completed_at'
                : DB::raw('NULL as ai_classification_completed_at'),
            Schema::hasColumn('tasks', 'ai_classification_error')
                ? 't.ai_classification_error'
                : DB::raw('NULL as ai_classification_error'),
        ];
    }

    private function taskClassificationPayload(object $row): array
    {
        $taskCategory = TaskClassificationService::normalizeTaskCategory((string) ($row->task_category ?? 'uncategorised'));
        $taskCategoryDefinitions = TaskClassificationService::taskCategoryDefinitions();
        $workType = TaskClassificationService::normalizeWorkType((string) ($row->work_type ?? 'unclear'));

        $classification = [
            'taskCategory' => $taskCategory,
            'taskCategoryLabel' => $taskCategoryDefinitions[$taskCategory]['label']
                ?? $taskCategoryDefinitions['uncategorised']['label'],
            'effortScore' => (float) ($row->effort_score ?? 1),
            'classificationConfidence' => (string) ($row->classification_confidence ?? 'low'),
            'classificationSource' => (string) ($row->classification_source ?? 'system'),
            'userOverride' => (bool) ($row->user_override ?? false),
            'matchedPattern' => $row->matched_pattern !== null ? (string) $row->matched_pattern : null,
            'workType' => $workType,
            'workTypeLabel' => TaskClassificationService::workTypeLabel($workType),
            'workTypeConfidence' => (string) ($row->work_type_confidence ?? 'low'),
            'workTypeMatchedPattern' => $row->work_type_matched_pattern !== null ? (string) $row->work_type_matched_pattern : null,
            'aiClassificationQueuedAt' => $row->ai_classification_queued_at !== null ? (string) $row->ai_classification_queued_at : null,
            'aiClassificationStartedAt' => $row->ai_classification_started_at !== null ? (string) $row->ai_classification_started_at : null,
            'aiClassificationCompletedAt' => $row->ai_classification_completed_at !== null ? (string) $row->ai_classification_completed_at : null,
            'aiClassificationError' => $row->ai_classification_error !== null ? (string) $row->ai_classification_error : null,
        ];

        $classification['aiClassificationStatus'] = $this->supportsAiClassificationStatus()
            ? $this->aiClassificationStatusForRow($row, $classification)
            : 'not_applicable';

        return $classification;
    }

    private function aiClassificationStatusForRow(object $row, array $classification): string
    {
        $status = $this->taskAiClassificationService()->statusForClassification([
                'task_category' => $classification['taskCategory'],
                'effort_score' => $classification['effortScore'],
                'classification_confidence' => $classification['classificationConfidence'],
                'classification_source' => $classification['classificationSource'],
                'work_type' => $classification['workType'],
                'ai_classification_status' => $row->ai_classification_status ?? null,
        ]);

        if ($status === 'queued' && ! empty($row->ai_classification_queued_at)) {
            return $this->staleStatusFromTimestamp($status, (string) $row->ai_classification_queued_at);
        }

        if ($status !== 'processing' || empty($row->ai_classification_started_at)) {
            return $status;
        }

        return $this->staleStatusFromTimestamp($status, (string) $row->ai_classification_started_at);
    }

    private function staleStatusFromTimestamp(string $status, string $timestamp): string
    {
        $time = strtotime($timestamp);
        if ($time === false) {
            return $status;
        }

        return $time < now()->subMinutes(10)->getTimestamp() ? 'stale' : $status;
    }

    private function supportsAiClassificationStatus(): bool
    {
        return Schema::hasColumn('tasks', 'task_category')
            && Schema::hasColumn('tasks', 'effort_score')
            && Schema::hasColumn('tasks', 'classification_confidence')
            && Schema::hasColumn('tasks', 'classification_source')
            && Schema::hasColumn('tasks', 'user_override')
            && Schema::hasColumn('tasks', 'matched_pattern')
            && Schema::hasColumn('tasks', 'work_type')
            && Schema::hasColumn('tasks', 'work_type_confidence')
            && Schema::hasColumn('tasks', 'work_type_matched_pattern');
    }
}
