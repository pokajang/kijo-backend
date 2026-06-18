<?php

namespace App\Services\Assistant\UserTrace;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserTaskTraceAnalyzer
{
    public function __construct(private readonly AssistantTraceDateRangeResolver $dates) {}

    public function analyze(string $question, AssistantUserTraceIdentity $identity, array $dateRange): AssistantUserTraceResult
    {
        if (! Schema::hasTable('tasks')) {
            return new AssistantUserTraceResult(
                'user_trace.task_status',
                'My task trace',
                'Tasks assigned to the current user.',
                $dateRange,
                ['count' => 0],
                [],
                [],
                [],
                ['tasks.table'],
                'low',
                'I could not verify your task trace because the task table is not available.',
                '/task-manager',
            );
        }

        $columns = Schema::getColumnListing('tasks');
        $dateColumn = in_array('created_at', $columns, true) ? 'created_at' : null;
        $rows = [];
        $query = DB::table('tasks')->where('staff_id', $identity->staffId);
        foreach ($query->limit(500)->get() as $row) {
            $item = (array) $row;
            $date = $dateColumn ? substr((string) ($item[$dateColumn] ?? ''), 0, 10) : null;
            if ($dateColumn && ! $this->dates->contains($date, $dateRange)) {
                continue;
            }
            $rows[] = [
                'id' => $item['id'] ?? null,
                'title' => $item['title'] ?? null,
                'status' => $item['status'] ?? 'unknown',
                'due_date' => $item['due_date'] ?? null,
                'task_category' => $item['task_category'] ?? null,
                'created_at' => $date,
            ];
        }

        return new AssistantUserTraceResult(
            'user_trace.task_status',
            'My task trace',
            'Tasks assigned to the current user.',
            $dateRange,
            ['count' => count($rows), 'open_count' => count(array_filter($rows, static fn (array $row): bool => strtolower((string) $row['status']) !== 'completed'))],
            ['by_status' => $this->countBy($rows, 'status'), 'by_category' => $this->countBy($rows, 'task_category')],
            array_slice($rows, 0, 8),
            ['show open tasks', 'break down by status', 'break down by category'],
            [],
            'high',
            'I found '.count($rows).' task(s) assigned to you for the selected range.',
            '/task-manager',
            ['analyzer' => 'task', 'row_count' => count($rows)],
        );
    }

    private function countBy(array $rows, string $key): array
    {
        $values = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row[$key] ?? 'unknown')) ?: 'unknown';
            $values[$label] = ($values[$label] ?? 0) + 1;
        }
        arsort($values);

        return $values;
    }
}
