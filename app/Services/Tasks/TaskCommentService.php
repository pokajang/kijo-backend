<?php

namespace App\Services\Tasks;

use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TaskCommentService extends TaskBaseService
{

    public function createComment(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated'], 401);
        }

        $taskId = (int) $request->input('task_id', 0);
        $text = trim((string) $request->input('text', ''));
        if ($taskId <= 0 || $text === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid task ID or empty comment',
            ], 400);
        }

        $owned = DB::table('tasks')
            ->where('id', $taskId)
            ->where('staff_id', $staffId)
            ->exists();

        if (! $owned) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Task not found or access denied',
            ], 404);
        }

        $commentId = (int) DB::table('task_comments')->insertGetId([
            'task_id'    => $taskId,
            'comment'    => $text,
            'created_at' => now(),
        ]);

        $timestamp = (string) optional(DB::table('task_comments')->select('created_at')->where('id', $commentId)->first())->created_at;

        return response()->json([
            'status'  => 'success',
            'comment' => [
                'task_id'   => $taskId,
                'text'      => $text,
                'timestamp' => $timestamp !== '' ? $timestamp : now()->toDateTimeString(),
            ],
        ]);
    }
}
