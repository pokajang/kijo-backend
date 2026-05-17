<?php

namespace App\Services\Tasks;

use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TaskQueryService extends TaskBaseService
{

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
            ->join('staff_general as s', 't.staff_id', '=', 's.staff_id')
            ->select([
                't.id',
                't.staff_id',
                's.full_name',
                's.name_code',
                't.title',
                't.status',
                't.created_at',
                't.due_date',
                't.completed_at',
            ])
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
                'id'          => $id,
                'staffId'     => (int) $r->staff_id,
                'staffName'   => (string) $r->full_name,
                'staffCode'   => (string) $r->name_code,
                'title'       => (string) $r->title,
                'status'      => (string) $r->status,
                'createdAt'   => (string) $r->created_at,
                'dueDate'     => (string) $r->due_date,
                'completedAt' => $r->completed_at ? (string) $r->completed_at : '',
                'commentLogs' => $commentsByTask[$id] ?? [],
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'tasks'  => $tasks,
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

        $rows = $query->get();

        $taskIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $commentsByTask = $this->getCommentsByTaskIds($taskIds);

        $tasks = $rows->map(function ($r) use ($commentsByTask, $staffId) {
            $id = (int) $r->id;
            return [
                'id'          => $id,
                'staffId'     => $staffId,
                'staffName'   => (string) ($r->full_name ?? ''),
                'staffCode'   => (string) ($r->name_code ?? ''),
                'title'       => (string) $r->title,
                'status'      => (string) $r->status,
                'createdAt'   => (string) $r->created_at,
                'dueDate'     => (string) $r->due_date,
                'completedAt' => $r->completed_at ? (string) $r->completed_at : '',
                'commentLogs' => $commentsByTask[$id] ?? [],
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'tasks'  => $tasks,
        ]);
    }
}
