<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Workflows\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function __construct(private WorkflowService $workflowService) {}

    public function templates(Request $request): JsonResponse
    {
        return $this->workflowService->templates($request);
    }

    public function template(Request $request, string $key): JsonResponse
    {
        return $this->workflowService->template($request, $key);
    }

    public function updateTemplate(Request $request, string $key): JsonResponse
    {
        return $this->workflowService->updateTemplate($request, $key);
    }

    public function inbox(Request $request): JsonResponse
    {
        return $this->workflowService->inbox($request);
    }

    public function action(Request $request, int $id): JsonResponse
    {
        return $this->workflowService->action($request, $id);
    }
}
