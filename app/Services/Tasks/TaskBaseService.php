<?php

namespace App\Services\Tasks;

use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

abstract class TaskBaseService extends PdfRenderer
{
    protected const TASK_GRACE_DAYS = 1;

    protected static bool $dompdfAutoloaderRegistered = false;

    protected function getCommentsByTaskIds(array $taskIds): array
    {
        if (empty($taskIds)) {
            return [];
        }

        $rows = DB::table('task_comments')
            ->select(['task_id', 'comment', 'created_at'])
            ->whereIn('task_id', $taskIds)
            ->orderByDesc('created_at')
            ->get();

        $commentsByTask = [];
        foreach ($rows as $row) {
            $taskId = (int) $row->task_id;
            $commentsByTask[$taskId][] = [
                'text'      => (string) $row->comment,
                'timestamp' => (string) $row->created_at,
            ];
        }

        return $commentsByTask;
    }

    protected function taskStaffFilterFromRequest(Request $request): array
    {
        $raw = trim((string) $request->query('staff_id', $request->input('staff_id', '')));
        if ($raw === '') {
            return [0, null];
        }

        if (! ctype_digit($raw)) {
            return [0, response()->json(['status' => 'error', 'message' => 'Invalid staff filter.'], 422)];
        }

        return [(int) $raw, null];
    }

    protected function taskYearFilterFromRequest(Request $request): array
    {
        $raw = trim((string) $request->query('year', ''));
        if ($raw === '') {
            return [0, null];
        }

        if (! ctype_digit($raw)) {
            return [0, response()->json(['status' => 'error', 'message' => 'Invalid year filter.'], 422)];
        }

        $year = (int) $raw;
        if ($year < 2000 || $year > 2100) {
            return [0, response()->json(['status' => 'error', 'message' => 'Invalid year filter.'], 422)];
        }

        return [$year, null];
    }

    protected function taskDateFilterError(string $start, string $end)
    {
        if ($start !== '' && ! $this->isStrictTaskDateFilter($start)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid start date format. Use YYYY-MM-DD.'], 422);
        }
        if ($end !== '' && ! $this->isStrictTaskDateFilter($end)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid end date format. Use YYYY-MM-DD.'], 422);
        }

        $startDate = $start !== '' ? $this->taskDateOnly($start) : null;
        $endDate = $end !== '' ? $this->taskDateOnly($end) : null;
        if ($startDate !== null && $endDate !== null && $startDate->getTimestamp() > $endDate->getTimestamp()) {
            return response()->json(['status' => 'error', 'message' => 'Start date cannot be after end date.'], 422);
        }

        return null;
    }

    protected function isStrictTaskDateFilter(string $value): bool
    {
        $dateText = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateText) === 1
            && $this->taskDateOnly($dateText) !== null;
    }

    protected function taskPeriodLabel(string $start, string $end, int $year = 0): string
    {
        if ($start !== '' || $end !== '') {
            return 'Period: ' . ($start !== '' ? $start : '(all)') . ' to ' . ($end !== '' ? $end : '(all)');
        }
        if ($year > 0) {
            return 'Year: ' . $year;
        }
        return 'All records';
    }

    protected function taskStatusText(object $task, string $todayStr): string
    {
        if ((string) $task->status === 'Completed') {
            if (! $task->completed_at) {
                return 'Completed';
            }
            $completedAt = (string) $task->completed_at;
            $lateDays = $this->taskDaysBetween((string) $task->due_date, $completedAt);
            if ($lateDays === null) {
                return 'Completed';
            }
            $lateDays = max(0, $lateDays - self::TASK_GRACE_DAYS);
            return $lateDays > 0
                ? 'Completed (Late by ' . $lateDays . ' day' . ($lateDays > 1 ? 's' : '') . ')'
                : 'Completed (On time)';
        }

        $dueDate = $this->taskDateOnly((string) $task->due_date);
        $todayDate = $this->taskDateOnly($todayStr);
        if ($dueDate !== null && $todayDate !== null && $todayDate > $dueDate->modify('+' . self::TASK_GRACE_DAYS . ' day')) {
            return 'Overdue';
        }

        return 'Ongoing';
    }

    protected function taskDaysLapsed(string $dueDate, string $todayStr, string $completedAt = ''): string
    {
        $days = $this->taskDaysBetween($dueDate, $completedAt !== '' ? $completedAt : $todayStr);
        return $days !== null ? (string) max(0, $days - self::TASK_GRACE_DAYS) : '0';
    }

    protected function taskDaysLapsedInfo(object $task, string $todayStr): array
    {
        $isCompleted = (string) $task->status === 'Completed';
        $dueDate = (string) ($task->due_date ?? '');
        $endDate = $isCompleted ? (string) ($task->completed_at ?? '') : $todayStr;

        if ($dueDate === '' || $endDate === '') {
            return [
                'value' => null,
                'display' => '-',
                'basis' => $isCompleted ? 'Completion date missing' : 'Due date missing',
            ];
        }

        $days = $this->taskDaysBetween($dueDate, $endDate);
        if ($days === null) {
            return ['value' => null, 'display' => '-', 'basis' => 'Invalid date'];
        }

        $value = max(0, $days - self::TASK_GRACE_DAYS);
        if (! $isCompleted && $this->taskStatusText($task, $todayStr) !== 'Overdue') {
            return [
                'value' => 0,
                'display' => '0',
                'basis' => 'Not overdue',
            ];
        }

        if ($isCompleted && $value > 0) {
            return [
                'value' => $value,
                'display' => $value . ' day' . ($value === 1 ? '' : 's') . ' until completion',
                'basis' => 'Until completion',
            ];
        }

        if (! $isCompleted && $value > 0) {
            return [
                'value' => $value,
                'display' => $value . ' day' . ($value === 1 ? '' : 's'),
                'basis' => 'Running',
            ];
        }

        return [
            'value' => $value,
            'display' => '0',
            'basis' => $isCompleted ? 'Completed on time' : 'Not overdue',
        ];
    }

    protected function taskDaysBetween(string $startDate, string $endDate): ?int
    {
        $start = $this->taskDateOnly($startDate);
        $end = $this->taskDateOnly($endDate);
        if ($start === null || $end === null) {
            return null;
        }

        return (int) ceil(($end->getTimestamp() - $start->getTimestamp()) / 86400);
    }

    protected function taskDateOnly(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $matches) !== 1) {
            return null;
        }

        $dateText = $matches[1];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
        if (! $date) {
            return null;
        }

        return $date->format('Y-m-d') === $dateText ? $date : null;
    }
}
