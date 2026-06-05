<?php

namespace App\Services\Tasks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskPdfService extends TaskBaseService
{
    private const MAX_PDF_TASKS = 750;

    private function tooManyTasksPdfResponse(int $taskCount)
    {
        return response()->json([
            'status' => 'error',
            'message' => "PDF export is limited to " . self::MAX_PDF_TASKS . " tasks. This selection contains {$taskCount} tasks. Please choose a shorter period or staff filter, or use CSV for larger exports.",
        ], 422);
    }

    public function exportAllTasksPdf(Request $request)
    {
        $start = trim((string) $request->query('start', ''));
        $end   = trim((string) $request->query('end', ''));
        [$staffId, $staffError] = $this->taskStaffFilterFromRequest($request);
        if ($staffError) {
            return $staffError;
        }

        if ($dateError = $this->taskDateFilterError($start, $end)) {
            return $dateError;
        }

        [$year, $yearError] = $this->taskYearFilterFromRequest($request);
        if ($yearError) {
            return $yearError;
        }

        try {
            $selectedStaff = $staffId > 0
                ? DB::table('staff_general')->where('staff_id', $staffId)->first(['full_name', 'name_code'])
                : null;
            $query = DB::table('tasks as t')
                ->join('staff_general as s', 't.staff_id', '=', 's.staff_id')
                ->select([
                    't.id',
                    't.title',
                    't.status',
                    't.created_at',
                    't.due_date',
                    't.completed_at',
                    's.full_name',
                    's.name_code',
                ])
                ->orderByDesc('t.created_at');

            if ($staffId > 0) {
                $query->where('t.staff_id', $staffId);
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

            $taskCount = (clone $query)->count();
            if ($taskCount > self::MAX_PDF_TASKS) {
                return $this->tooManyTasksPdfResponse($taskCount);
            }

            $tasks = $query->get();
            $todayStr = now()->toDateString();
            $commentsByTask = $this->getCommentsByTaskIds(
                $tasks->pluck('id')->map(fn ($id) => (int) $id)->all()
            );
            $reportTasks = $tasks->map(function ($task) use ($commentsByTask, $todayStr) {
                $comments = $commentsByTask[(int) $task->id] ?? [];
                $commentSummary = collect($comments)
                    ->map(fn (array $comment) => trim((string) $comment['text']) . ' (' . (string) $comment['timestamp'] . ')')
                    ->filter(fn (string $comment) => $comment !== '()')
                    ->implode("\n");
                $daysLapsed = $this->taskDaysLapsedInfo($task, $todayStr);

                return [
                    'createdAt'      => (string) $task->created_at,
                    'dueDate'        => (string) ($task->due_date ?? '-'),
                    'staff'          => trim((string) $task->name_code . ' - ' . (string) $task->full_name),
                    'title'          => (string) $task->title,
                    'statusText'     => $this->taskStatusText($task, $todayStr),
                    'daysLapsed'     => $daysLapsed['display'],
                    'daysLapsedBasis'=> $daysLapsed['basis'],
                    'completedAt'    => $task->completed_at ? (string) $task->completed_at : '-',
                    'commentSummary' => $commentSummary !== '' ? $commentSummary : '-',
                ];
            })->values();

            $periodLabel = $this->taskPeriodLabel($start, $end, $year);
            $staffLabel = $staffId > 0
                ? 'Staff: ' . trim((string) ($selectedStaff?->name_code ?? "Staff #{$staffId}") . ' - ' . (string) ($selectedStaff?->full_name ?? ''))
                : 'Staff: All Staff';

            $generatedAt = now();
            $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
            $generatorCode = (string) $request->session()->get('name_code', '');

            $html = view('pdf.all-tasks-report', [
                'title' => 'All Staff Tasks',
                'periodLabel' => $periodLabel,
                'staffLabel' => $staffLabel,
                'tasks' => $reportTasks,
                'logoDataUri' => $this->companyLogoDataUri(),
            ])->render();

            $dompdf = $this->renderWithFooter($html, $generatedAt, $generatorCode, $generatorId, 'landscape');

            return response($dompdf->output(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="all-tasks-report.pdf"',
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response('Error generating PDF.', 500);
        }
    }

    public function exportPersonalTasksPdf(Request $request)
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

        try {
            $selectedStaff = DB::table('staff_general')
                ->where('staff_id', $staffId)
                ->first(['full_name', 'name_code']);

            $query = DB::table('tasks as t')
                ->leftJoin('staff_general as s', 't.staff_id', '=', 's.staff_id')
                ->select([
                    't.id',
                    't.title',
                    't.status',
                    't.created_at',
                    't.due_date',
                    't.completed_at',
                    's.full_name',
                    's.name_code',
                ])
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

            $taskCount = (clone $query)->count();
            if ($taskCount > self::MAX_PDF_TASKS) {
                return $this->tooManyTasksPdfResponse($taskCount);
            }

            $tasks = $query->get();
            $todayStr = now()->toDateString();
            $commentsByTask = $this->getCommentsByTaskIds(
                $tasks->pluck('id')->map(fn ($id) => (int) $id)->all()
            );
            $reportTasks = $tasks->map(function ($task) use ($commentsByTask, $todayStr) {
                $comments = $commentsByTask[(int) $task->id] ?? [];
                $commentSummary = collect($comments)
                    ->map(fn (array $comment) => trim((string) $comment['text']) . ' (' . (string) $comment['timestamp'] . ')')
                    ->filter(fn (string $comment) => $comment !== '()')
                    ->implode("\n");
                $daysLapsed = $this->taskDaysLapsedInfo($task, $todayStr);

                return [
                    'createdAt'      => (string) $task->created_at,
                    'dueDate'        => (string) ($task->due_date ?? '-'),
                    'staff'          => trim((string) $task->name_code . ' - ' . (string) $task->full_name),
                    'title'          => (string) $task->title,
                    'statusText'     => $this->taskStatusText($task, $todayStr),
                    'daysLapsed'     => $daysLapsed['display'],
                    'daysLapsedBasis'=> $daysLapsed['basis'],
                    'completedAt'    => $task->completed_at ? (string) $task->completed_at : '-',
                    'commentSummary' => $commentSummary !== '' ? $commentSummary : '-',
                ];
            })->values();

            $periodLabel = $this->taskPeriodLabel($start, $end, $year);
            $staffLabel = 'Staff: ' . trim((string) ($selectedStaff?->name_code ?? "Staff #{$staffId}") . ' - ' . (string) ($selectedStaff?->full_name ?? ''));

            $generatedAt = now();
            $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
            $generatorCode = (string) $request->session()->get('name_code', '');

            $html = view('pdf.all-tasks-report', [
                'title' => 'My Tasks',
                'periodLabel' => $periodLabel,
                'staffLabel' => $staffLabel,
                'tasks' => $reportTasks,
                'logoDataUri' => $this->companyLogoDataUri(),
            ])->render();

            $dompdf = $this->renderWithFooter($html, $generatedAt, $generatorCode, $generatorId, 'landscape');

            return response($dompdf->output(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="my-tasks-report.pdf"',
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response('Error generating PDF.', 500);
        }
    }
}
