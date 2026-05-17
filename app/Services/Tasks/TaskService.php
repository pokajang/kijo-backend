<?php

namespace App\Services\Tasks;

use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TaskService
{
    private function taskQueryService(): TaskQueryService
    {
        return app(TaskQueryService::class);
    }

    private function taskMutationService(): TaskMutationService
    {
        return app(TaskMutationService::class);
    }

    private function taskCommentService(): TaskCommentService
    {
        return app(TaskCommentService::class);
    }

    private function taskPdfService(): TaskPdfService
    {
        return app(TaskPdfService::class);
    }

    public function getAllTasks(Request $request)
    {
        return $this->taskQueryService()->getAllTasks($request);
    }

    public function getPersonalTasks(Request $request)
    {
        return $this->taskQueryService()->getPersonalTasks($request);
    }

    public function createTask(Request $request)
    {
        return $this->taskMutationService()->createTask($request);
    }

    public function createTasksBatch(Request $request)
    {
        return $this->taskMutationService()->createTasksBatch($request);
    }

    public function markCompleted(Request $request)
    {
        return $this->taskMutationService()->markCompleted($request);
    }

    public function deleteTask(Request $request)
    {
        return $this->taskMutationService()->deleteTask($request);
    }

    public function createComment(Request $request)
    {
        return $this->taskCommentService()->createComment($request);
    }

    public function exportAllTasksPdf(Request $request)
    {
        return $this->taskPdfService()->exportAllTasksPdf($request);
    }

    public function exportPersonalTasksPdf(Request $request)
    {
        return $this->taskPdfService()->exportPersonalTasksPdf($request);
    }

}
