<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\ModuleEntityResolver;
use App\Services\Tasks\TaskQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TaskContextProvider extends ModuleContextProvider
{
    use ProviderAuditMetadata;

    private const ROUTE_PATTERNS = [
        '~/task-manager/(\d+)(?:/|$)~i',
        '~/staff/tasks/(\d+)(?:/|$)~i',
    ];

    public function __construct(
        AssistantText $text,
        private readonly TaskQueryService $tasks,
        private readonly ModuleEntityResolver $resolver,
        private readonly AssistantContextSanitizer $sanitizer,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'task';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return Schema::hasTable('tasks')
            && (
                str_contains(strtolower($currentRoute), '/task')
                || $this->hasToken($question, [
                    'task', 'tasks', 'todo', 'reminder', 'overdue', 'due', 'completed',
                    'workload',
                ])
            );
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $allStaff = $this->canViewAllTasks($request)
            && ($this->hasToken($question, ['staff', 'team', 'all']) || str_starts_with(strtolower($currentRoute), '/staff/tasks'));
        $rows = $this->taskRows($request, $allStaff);
        if ($rows === []) {
            return AssistantContextResult::empty($this->key());
        }

        $resolved = $this->resolver->resolve(
            $question,
            $currentRoute,
            $rows,
            'id',
            'title',
            ['title', 'status', 'staffName', 'staffCode', 'projectName', 'taskCategory', 'workTypeLabel'],
            self::ROUTE_PATTERNS,
        );

        if ($resolved['status'] === 'ambiguous') {
            return $this->resultFromSource($this->ambiguousSource($resolved['matches'], $allStaff));
        }

        if ($resolved['status'] === 'resolved') {
            return $this->resultFromSource($this->taskSource((array) $resolved['row'], $allStaff));
        }

        $ranked = $this->resolver->rankedMatches($question, $rows, 'title', [
            'title', 'status', 'staffName', 'staffCode', 'projectName', 'taskCategory', 'workTypeLabel',
        ]);
        $matches = array_column(array_slice($ranked, 0, 8), 'row');
        $filtered = $this->filterByIntent($matches ?: $rows, $question);
        if ($filtered === [] && ! $this->hasListIntent($question) && ! str_contains(strtolower($currentRoute), '/task')) {
            return AssistantContextResult::empty($this->key());
        }

        return $this->resultFromSource($this->taskListSource($filtered ?: array_slice($rows, 0, 8), $allStaff));
    }

    public function auditMetadata(): array
    {
        return $this->auditMetadataRow([
            'supported_routes' => ['/task-manager', '/task-manager/{id}', '/staff/tasks', '/staff/tasks/{id}'],
            'exact_ref_support' => true,
            'detail_route_support' => true,
            'list_support' => true,
            'sanitizer_coverage' => 'covered',
            'permission_scope' => 'self-or-manager-admin',
            'smoke_sample' => 'show overdue tasks',
            'classification' => 'detail-ready',
        ]);
    }

    private function taskRows(Request $request, bool $allStaff): array
    {
        $payload = $this->responseData(fn () => $allStaff
            ? $this->tasks->getAllTasks($this->clonedRequest($request, '/assistant/tasks', ['year' => now()->year]))
            : $this->tasks->getPersonalTasks($this->clonedRequest($request, '/assistant/tasks/personal', ['year' => now()->year])));

        return array_map(fn ($row): array => (array) $row, $payload['tasks'] ?? []);
    }

    private function filterByIntent(array $rows, string $question): array
    {
        if ($this->hasToken($question, ['completed', 'done'])) {
            return array_values(array_filter($rows, fn (array $row): bool => strtolower((string) ($row['status'] ?? '')) === 'completed'));
        }

        if ($this->hasToken($question, ['open', 'active', 'pending', 'overdue', 'due'])) {
            return array_values(array_filter($rows, fn (array $row): bool => strtolower((string) ($row['status'] ?? '')) !== 'completed'));
        }

        return $rows;
    }

    private function taskSource(array $task, bool $allStaff): ?array
    {
        $id = (int) ($task['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        return $this->source(
            "task:{$id}",
            'task',
            (string) ($task['title'] ?? "Task #{$id}"),
            ($allStaff ? '/staff/tasks/' : '/task-manager/').$id,
            ['task' => $this->sanitizer->keep($task, [
                'id',
                'staffId',
                'staffName',
                'staffCode',
                'projectId',
                'projectName',
                'projectStatus',
                'title',
                'status',
                'createdAt',
                'dueDate',
                'completedAt',
                'taskCategory',
                'effortScore',
                'workTypeLabel',
                'classificationConfidence',
                'commentLogs',
            ])],
            420,
            'Tasks',
        );
    }

    private function taskListSource(array $tasks, bool $allStaff): ?array
    {
        $rows = $this->sanitizer->rows($tasks, [
            'id',
            'staffName',
            'staffCode',
            'projectName',
            'title',
            'status',
            'createdAt',
            'dueDate',
            'completedAt',
            'taskCategory',
            'effortScore',
            'workTypeLabel',
        ], 8);

        return $this->source(
            'task:list:'.substr(sha1(json_encode($rows)), 0, 12),
            'task',
            $allStaff ? 'Staff task matches' : 'My task matches',
            $allStaff ? '/staff/tasks' : '/task-manager',
            [
                'note' => $allStaff
                    ? 'Showing all-staff task context because the user has a manager/admin role.'
                    : 'Showing only the current user personal task context.',
                'tasks' => $rows,
            ],
            330,
            'Tasks',
        );
    }

    private function ambiguousSource(array $matches, bool $allStaff): ?array
    {
        $rows = $this->sanitizer->rows($matches, [
            'id',
            'staffName',
            'staffCode',
            'title',
            'status',
            'dueDate',
        ], 5);

        return $this->source(
            'task:ambiguous:'.substr(sha1(json_encode($rows)), 0, 12),
            'live_entity',
            'Ambiguous task matches',
            $allStaff ? '/staff/tasks' : '/task-manager',
            [
                'note' => 'The question matched multiple tasks. Ask again with the exact task title or task ID.',
                'matches' => $rows,
            ],
            360,
            'Tasks',
        );
    }

    private function canViewAllTasks(Request $request): bool
    {
        return $this->hasAnyRole($request, ['Manager', 'System Admin']);
    }

    private function resultFromSource(?array $source): AssistantContextResult
    {
        return new AssistantContextResult(
            $source ? [$source] : [],
            $source ? 'live' : 'static',
            $source ? $this->freshnessLabel() : null,
            [$this->key()],
        );
    }
}
