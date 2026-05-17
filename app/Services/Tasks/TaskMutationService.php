<?php

namespace App\Services\Tasks;

use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TaskMutationService extends TaskBaseService
{

    public function createTask(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'title'    => ['required', 'string', 'max:255'],
            'due_date' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid input: title and due_date (YYYY-MM-DD) required.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $title = trim($data['title']);
        if ($title === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid input: title and due_date (YYYY-MM-DD) required.',
                'errors'  => ['title' => ['The title field is required.']],
            ], 422);
        }

        $taskId = (int) DB::table('tasks')->insertGetId([
            'staff_id'    => $staffId,
            'title'       => $title,
            'due_date'    => $data['due_date'],
            'status'      => 'Ongoing',
            'created_at'  => now(),
            'completed_at'=> null,
        ]);

        $row = DB::table('tasks')->select('created_at')->where('id', $taskId)->first();

        return response()->json([
            'status' => 'success',
            'task'   => [
                'id'           => $taskId,
                'staff_id'     => $staffId,
                'title'        => $title,
                'due_date'     => $data['due_date'],
                'created_at'   => $row?->created_at ? (string) $row->created_at : now()->toDateTimeString(),
                'status'       => 'Ongoing',
                'completed_at' => null,
                'commentLogs'  => [],
            ],
        ]);
    }

    public function createTasksBatch(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'tasks'             => ['required', 'array', 'min:1', 'max:50'],
            'tasks.*.title'     => ['required', 'string', 'max:255'],
            'tasks.*.due_date'  => ['required', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid input: each task needs title and due_date (YYYY-MM-DD).',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $now = now();
        $tasks = collect($validator->validated()['tasks'])
            ->map(fn (array $task) => [
                'title' => trim((string) $task['title']),
                'due_date' => (string) $task['due_date'],
            ])
            ->values();
        $blankTitleErrors = [];
        foreach ($tasks as $index => $task) {
            if ($task['title'] === '') {
                $blankTitleErrors["tasks.{$index}.title"] = ['The title field is required.'];
            }
        }
        if (! empty($blankTitleErrors)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid input: each task needs title and due_date (YYYY-MM-DD).',
                'errors'  => $blankTitleErrors,
            ], 422);
        }

        $createdTasks = DB::transaction(function () use ($tasks, $staffId, $now) {
            return $tasks
                ->map(function (array $task) use ($staffId, $now) {
                    $taskId = (int) DB::table('tasks')->insertGetId([
                        'staff_id'      => $staffId,
                        'title'         => $task['title'],
                        'due_date'      => $task['due_date'],
                        'status'        => 'Ongoing',
                        'created_at'    => $now,
                        'completed_at'  => null,
                    ]);

                    return [
                        'id'           => $taskId,
                        'staff_id'     => $staffId,
                        'title'        => $task['title'],
                        'due_date'     => $task['due_date'],
                        'created_at'   => $now->toDateTimeString(),
                        'status'       => 'Ongoing',
                        'completed_at' => null,
                        'commentLogs'  => [],
                    ];
                })
                ->values()
                ->all();
        });

        return response()->json([
            'status' => 'success',
            'tasks'  => $createdTasks,
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
        $updated = DB::table('tasks')
            ->where('id', $taskId)
            ->where('staff_id', $staffId)
            ->update([
                'status'       => 'Completed',
                'completed_at' => $completedAt,
            ]);

        if ($updated === 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Task not found or access denied',
            ], 404);
        }

        return response()->json([
            'status'       => 'success',
            'completed_at' => $completedAt,
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

        $deleted = DB::table('tasks')
            ->where('id', $taskId)
            ->where('staff_id', $staffId)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Task not found or access denied',
            ], 404);
        }

        DB::table('task_comments')->where('task_id', $taskId)->delete();

        return response()->json(['status' => 'success']);
    }
}
