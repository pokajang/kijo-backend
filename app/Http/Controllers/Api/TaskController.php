<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\Tasks\TaskService;

class TaskController extends Controller
{
    private function taskService(): TaskService
    {
        return app(TaskService::class);
    }


        public function getAllTasks(Request $request)
    {
        return $this->taskService()->getAllTasks($request);
    }


        public function getPersonalTasks(Request $request)
    {
        return $this->taskService()->getPersonalTasks($request);
    }


        public function createTask(Request $request)
    {
        return $this->taskService()->createTask($request);
    }


        public function createTasksBatch(Request $request)
    {
        return $this->taskService()->createTasksBatch($request);
    }


        public function markCompleted(Request $request)
    {
        return $this->taskService()->markCompleted($request);
    }


        public function createComment(Request $request)
    {
        return $this->taskService()->createComment($request);
    }


        public function deleteTask(Request $request)
    {
        return $this->taskService()->deleteTask($request);
    }


        public function exportAllTasksPdf(Request $request)
    {
        return $this->taskService()->exportAllTasksPdf($request);
    }


        public function exportPersonalTasksPdf(Request $request)
    {
        return $this->taskService()->exportPersonalTasksPdf($request);
    }

}
