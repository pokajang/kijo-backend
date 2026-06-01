<?php

namespace App\Services\Tasks;

use App\Jobs\EnrichTaskClassificationWithAiJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class TaskMutationService extends TaskBaseService
{
    private const TAGGABLE_PROJECT_ROLES = ['leader', 'pic', 'owner', 'assistant', 'collaborator'];

    private function taskClassificationService(): TaskClassificationService
    {
        return app(TaskClassificationService::class);
    }

    private function taskAiClassificationService(): TaskAiClassificationService
    {
        return app(TaskAiClassificationService::class);
    }

    public function createTask(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'project_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid input: title and due_date (YYYY-MM-DD) required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $title = trim($data['title']);
        $classification = $this->taskClassificationService()->classifyTitle($title);
        $requestedProjectId = $data['project_id'] ?? null;
        if ($requestedProjectId !== null && ! $this->supportsTaskProjectLinking()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Project tagging is not available until the task project-link migration is run.',
                'errors' => ['project_id' => ['Project tagging is not available until the task project-link migration is run.']],
            ], 422);
        }

        $projectId = $this->validActiveProjectId($requestedProjectId, $staffId);
        if ($requestedProjectId !== null && $projectId === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Selected project is not active or is not assigned to you.',
                'errors' => ['project_id' => ['Selected project is not active or is not assigned to you.']],
            ], 422);
        }
        if ($title === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid input: title and due_date (YYYY-MM-DD) required.',
                'errors' => ['title' => ['The title field is required.']],
            ], 422);
        }

        $insert = [
            'staff_id' => $staffId,
            'title' => $title,
            'due_date' => $data['due_date'],
            'status' => 'Ongoing',
            'created_at' => now(),
            'completed_at' => null,
        ];
        if ($this->supportsTaskProjectLinking()) {
            $insert['project_id'] = $projectId;
            $insert['project_progress_id'] = null;
        }
        if ($this->supportsTaskClassification()) {
            $insert = array_merge($insert, $this->taskClassificationService()->insertColumns(
                $classification,
                $this->supportsTaskWorkType()
            ));
        }

        $taskId = DB::transaction(function () use ($insert, $projectId, $staffId, $title): int {
            $taskId = (int) DB::table('tasks')->insertGetId($insert);

            if ($this->supportsTaskProjectLinking() && $projectId !== null) {
                $progressId = $this->insertProgressForOngoingTask(
                    $taskId,
                    $projectId,
                    $staffId,
                    $title,
                    now()->toDateString(),
                );

                DB::table('tasks')
                    ->where('id', $taskId)
                    ->update(['project_progress_id' => $progressId]);
            }

            return $taskId;
        });

        $row = DB::table('tasks')->select('created_at', 'project_progress_id')->where('id', $taskId)->first();
        $aiQueued = $this->queueAiClassificationIfNeeded($taskId, $classification);
        $classificationResponse = $this->taskClassificationService()->toResponse($classification);
        $classificationResponse['aiClassificationStatus'] = $aiQueued
            ? 'pending'
            : $this->aiClassificationStatusForPayload($classification);

        return response()->json([
            'status' => 'success',
            'task' => [
                'id' => $taskId,
                'staff_id' => $staffId,
                'projectId' => $projectId,
                'projectProgressId' => $row?->project_progress_id ? (int) $row->project_progress_id : null,
                'title' => $title,
                'due_date' => $data['due_date'],
                'created_at' => $row?->created_at ? (string) $row->created_at : now()->toDateTimeString(),
                'status' => 'Ongoing',
                'completed_at' => null,
                'commentLogs' => [],
            ] + $classificationResponse,
        ]);
    }

    public function createTasksBatch(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'tasks' => ['required', 'array', 'min:1', 'max:50'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.due_date' => ['required', 'date_format:Y-m-d'],
            'tasks.*.project_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid input: each task needs title and due_date (YYYY-MM-DD).',
                'errors' => $validator->errors(),
            ], 422);
        }

        $now = now();
        $tasks = collect($validator->validated()['tasks'])
            ->map(fn (array $task) => [
                'title' => trim((string) $task['title']),
                'due_date' => (string) $task['due_date'],
                'project_id' => $task['project_id'] ?? null,
                'classification' => $this->taskClassificationService()->classifyTitle(trim((string) $task['title'])),
            ])
            ->values();
        $blankTitleErrors = [];
        $projectErrors = [];
        $supportsProjectLinking = $this->supportsTaskProjectLinking();
        foreach ($tasks as $index => $task) {
            if ($task['title'] === '') {
                $blankTitleErrors["tasks.{$index}.title"] = ['The title field is required.'];
            }

            if ($task['project_id'] !== null && ! $supportsProjectLinking) {
                $projectErrors["tasks.{$index}.project_id"] = ['Project tagging is not available until the task project-link migration is run.'];
            } elseif ($task['project_id'] !== null && $this->validActiveProjectId($task['project_id'], $staffId) === null) {
                $projectErrors["tasks.{$index}.project_id"] = ['Selected project is not active or is not assigned to you.'];
            }
        }
        if (! empty($blankTitleErrors) || ! empty($projectErrors)) {
            $message = ! empty($projectErrors) && empty($blankTitleErrors)
                ? collect($projectErrors)->flatten()->first()
                : 'Invalid input: each task needs title and due_date (YYYY-MM-DD).';

            return response()->json([
                'status' => 'error',
                'message' => $message,
                'errors' => array_merge($blankTitleErrors, $projectErrors),
            ], 422);
        }

        $createdTasks = DB::transaction(function () use ($tasks, $staffId, $now, $supportsProjectLinking) {
            return $tasks
                ->map(function (array $task) use ($staffId, $now, $supportsProjectLinking) {
                    $projectId = $this->validActiveProjectId($task['project_id'], $staffId);
                    $insert = [
                        'staff_id' => $staffId,
                        'title' => $task['title'],
                        'due_date' => $task['due_date'],
                        'status' => 'Ongoing',
                        'created_at' => $now,
                        'completed_at' => null,
                    ];
                    if ($supportsProjectLinking) {
                        $insert['project_id'] = $projectId;
                        $insert['project_progress_id'] = null;
                    }
                    if ($this->supportsTaskClassification()) {
                        $insert = array_merge($insert, $this->taskClassificationService()->insertColumns(
                            $task['classification'],
                            $this->supportsTaskWorkType()
                        ));
                    }

                    $taskId = (int) DB::table('tasks')->insertGetId($insert);
                    $projectProgressId = null;

                    if ($supportsProjectLinking && $projectId !== null) {
                        $projectProgressId = $this->insertProgressForOngoingTask(
                            $taskId,
                            $projectId,
                            $staffId,
                            $task['title'],
                            $now->toDateString(),
                        );

                        DB::table('tasks')
                            ->where('id', $taskId)
                            ->update(['project_progress_id' => $projectProgressId]);
                    }

                    return [
                        'id' => $taskId,
                        'staff_id' => $staffId,
                        'projectId' => $projectId,
                        'projectProgressId' => $projectProgressId,
                        'title' => $task['title'],
                        'due_date' => $task['due_date'],
                        'created_at' => $now->toDateTimeString(),
                        'status' => 'Ongoing',
                        'completed_at' => null,
                        'commentLogs' => [],
                    ] + $this->taskClassificationService()->toResponse($task['classification']);
                })
                ->values()
                ->all();
        });

        foreach ($createdTasks as $index => $createdTask) {
            $aiQueued = $this->queueAiClassificationIfNeeded(
                (int) $createdTask['id'],
                $this->classificationFromResponsePayload($createdTask),
            );
            $createdTasks[$index]['aiClassificationStatus'] = $aiQueued
                ? 'pending'
                : $this->aiClassificationStatusForPayload($this->classificationFromResponsePayload($createdTask));
        }

        return response()->json([
            'status' => 'success',
            'tasks' => $createdTasks,
        ]);
    }

    public function markCompleted(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated'], 401);
        }

        $taskId = (int) $request->input('task_id', 0);
        if ($taskId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Invalid task ID'], 400);
        }

        $completedAt = now()->toDateString();
        $result = DB::transaction(function () use ($taskId, $staffId, $completedAt) {
            $task = DB::table('tasks')
                ->where('id', $taskId)
                ->where('staff_id', $staffId)
                ->lockForUpdate()
                ->first();

            if (! $task) {
                return null;
            }

            $wasCompleted = (string) $task->status === 'Completed';
            if (! $wasCompleted) {
                DB::table('tasks')
                    ->where('id', $taskId)
                    ->where('staff_id', $staffId)
                    ->update([
                        'status' => 'Completed',
                        'completed_at' => $completedAt,
                    ]);
            }

            $projectId = (int) ($task->project_id ?? 0);
            $existingProgressId = (int) ($task->project_progress_id ?? 0);
            if ($this->supportsTaskProjectLinking() && $projectId > 0 && $existingProgressId > 0) {
                if (! $wasCompleted) {
                    $this->updateProgressForCompletedTask($task, $existingProgressId, $projectId, $staffId, $completedAt);
                }

                return $existingProgressId;
            }

            if ($this->supportsTaskProjectLinking() && $projectId > 0) {
                $progressId = $this->insertProgressForCompletedTask($task, $projectId, $staffId, $completedAt);
                DB::table('tasks')
                    ->where('id', $taskId)
                    ->where('staff_id', $staffId)
                    ->update(['project_progress_id' => $progressId]);

                return $progressId;
            }

            return $existingProgressId > 0 ? $existingProgressId : 0;
        });

        if ($result === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Task not found or access denied',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'completed_at' => $completedAt,
            'project_progress_id' => $result > 0 ? $result : null,
        ]);
    }

    public function deleteTask(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated'], 401);
        }

        $taskId = (int) $request->input('task_id', 0);
        if ($taskId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Invalid task ID'], 400);
        }

        $task = DB::table('tasks')
            ->where('id', $taskId)
            ->where('staff_id', $staffId)
            ->first();

        if (! $task) {
            return response()->json([
                'status' => 'error',
                'message' => 'Task not found or access denied',
            ], 404);
        }

        DB::transaction(function () use ($task, $taskId, $staffId): void {
            $progressId = (int) ($task->project_progress_id ?? 0);
            $projectId = (int) ($task->project_id ?? 0);

            if ($progressId > 0 && $projectId > 0 && Schema::hasTable('project_progress')) {
                DB::table('project_progress')
                    ->where('id', $progressId)
                    ->where('project_id', $projectId)
                    ->delete();
            }

            DB::table('tasks')
                ->where('id', $taskId)
                ->where('staff_id', $staffId)
                ->delete();

            if (Schema::hasTable('task_comments')) {
                DB::table('task_comments')->where('task_id', $taskId)->delete();
            }
        });

        return response()->json(['status' => 'success']);
    }

    private function validActiveProjectId(mixed $projectId, int $staffId): ?int
    {
        if ($projectId === null || $projectId === '') {
            return null;
        }

        $id = (int) $projectId;
        if (
            $id <= 0
            || $staffId <= 0
            || ! Schema::hasTable('projects_main')
            || ! Schema::hasTable('project_collaborators')
        ) {
            return null;
        }

        $exists = DB::table('projects_main as p')
            ->join('project_collaborators as pc', 'pc.project_id', '=', 'p.id')
            ->where('p.id', $id)
            ->where('pc.staff_id', $staffId)
            ->whereIn(DB::raw('LOWER(TRIM(pc.project_role))'), self::TAGGABLE_PROJECT_ROLES)
            ->whereRaw('LOWER(TRIM(p.status)) = ?', ['active'])
            ->exists();

        return $exists ? $id : null;
    }

    private function supportsTaskProjectLinking(): bool
    {
        return Schema::hasTable('project_progress')
            && Schema::hasColumn('tasks', 'project_id')
            && Schema::hasColumn('tasks', 'project_progress_id');
    }

    private function supportsTaskClassification(): bool
    {
        return Schema::hasColumn('tasks', 'task_category')
            && Schema::hasColumn('tasks', 'effort_score')
            && Schema::hasColumn('tasks', 'classification_confidence')
            && Schema::hasColumn('tasks', 'classification_source')
            && Schema::hasColumn('tasks', 'user_override')
            && Schema::hasColumn('tasks', 'matched_pattern');
    }

    private function supportsTaskWorkType(): bool
    {
        return Schema::hasColumn('tasks', 'work_type')
            && Schema::hasColumn('tasks', 'work_type_confidence')
            && Schema::hasColumn('tasks', 'work_type_matched_pattern');
    }

    private function queueAiClassificationIfNeeded(int $taskId, array $classification): bool
    {
        if (
            ! $this->supportsAiClassificationStorage()
            || ! $this->taskAiClassificationService()->shouldAttemptAiFallback($classification)
        ) {
            return false;
        }

        EnrichTaskClassificationWithAiJob::dispatch($taskId)->afterResponse();

        return true;
    }

    private function aiClassificationStatusForPayload(array $classification): string
    {
        if (! $this->supportsAiClassificationStorage()) {
            return 'not_applicable';
        }

        return $this->taskAiClassificationService()->statusForClassification($classification);
    }

    private function supportsAiClassificationStorage(): bool
    {
        return $this->supportsTaskClassification() && $this->supportsTaskWorkType();
    }

    private function classificationFromResponsePayload(array $task): array
    {
        return [
            'task_category' => $task['taskCategory'] ?? 'uncategorised',
            'effort_score' => $task['effortScore'] ?? 1,
            'classification_confidence' => $task['classificationConfidence'] ?? 'low',
            'classification_source' => $task['classificationSource'] ?? 'system',
            'user_override' => $task['userOverride'] ?? false,
            'matched_pattern' => $task['matchedPattern'] ?? null,
            'work_type' => $task['workType'] ?? 'unclear',
            'work_type_confidence' => $task['workTypeConfidence'] ?? 'low',
            'work_type_matched_pattern' => $task['workTypeMatchedPattern'] ?? null,
        ];
    }

    private function insertProgressForCompletedTask(object $task, int $projectId, int $staffId, string $completedAt): int
    {
        $payload = [
            'project_id' => $projectId,
            'progress_date' => $completedAt,
            'progress_text' => 'Completed task: '.(string) $task->title,
            'updated_by' => $staffId,
            'updated_on' => now(),
        ];

        if (Schema::hasColumn('project_progress', 'source_type')) {
            $payload['source_type'] = 'task';
        }
        if (Schema::hasColumn('project_progress', 'source_task_id')) {
            $payload['source_task_id'] = (int) $task->id;
        }

        return (int) DB::table('project_progress')->insertGetId($payload);
    }

    private function insertProgressForOngoingTask(
        int $taskId,
        int $projectId,
        int $staffId,
        string $title,
        string $createdDate
    ): int {
        $payload = [
            'project_id' => $projectId,
            'progress_date' => $createdDate,
            'progress_text' => 'Ongoing task: '.$title,
            'updated_by' => $staffId,
            'updated_on' => now(),
        ];

        if (Schema::hasColumn('project_progress', 'source_type')) {
            $payload['source_type'] = 'task';
        }
        if (Schema::hasColumn('project_progress', 'source_task_id')) {
            $payload['source_task_id'] = $taskId;
        }

        return (int) DB::table('project_progress')->insertGetId($payload);
    }

    private function updateProgressForCompletedTask(
        object $task,
        int $progressId,
        int $projectId,
        int $staffId,
        string $completedAt
    ): void {
        $payload = [
            'progress_date' => $completedAt,
            'progress_text' => 'Completed task: '.(string) $task->title,
            'updated_by' => $staffId,
            'updated_on' => now(),
        ];

        if (Schema::hasColumn('project_progress', 'source_type')) {
            $payload['source_type'] = 'task';
        }
        if (Schema::hasColumn('project_progress', 'source_task_id')) {
            $payload['source_task_id'] = (int) $task->id;
        }

        DB::table('project_progress')
            ->where('id', $progressId)
            ->where('project_id', $projectId)
            ->update($payload);
    }
}
