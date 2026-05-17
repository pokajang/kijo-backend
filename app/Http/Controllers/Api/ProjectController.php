<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\AddCollaboratorRequest;
use App\Http\Requests\Project\AddExpenseRequest;
use App\Http\Requests\Project\AddProgressRequest;
use App\Http\Requests\Project\AssignVendorRequest;
use App\Http\Requests\Project\CloseProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProgressRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Services\Projects\ProjectService;

class ProjectController extends Controller
{
    private function projectService(): ProjectService
    {
        return app(ProjectService::class);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->projectService()->index($request);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        return $this->projectService()->store($request);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->projectService()->show($request, $id);
    }

    public function update(UpdateProjectRequest $request): JsonResponse
    {
        return $this->projectService()->update($request);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->projectService()->destroy($request, $id);
    }

    public function close(CloseProjectRequest $request): JsonResponse
    {
        return $this->projectService()->close($request);
    }

    public function reloadPoNumber(Request $request): JsonResponse
    {
        return $this->projectService()->reloadPoNumber($request);
    }

    public function crmDetails(Request $request): JsonResponse
    {
        return $this->projectService()->crmDetails($request);
    }

    public function addCollaborator(AddCollaboratorRequest $request): JsonResponse
    {
        return $this->projectService()->addCollaborator($request);
    }

    public function listCollaborators(Request $request): JsonResponse
    {
        return $this->projectService()->listCollaborators($request);
    }

    public function removeCollaborator(Request $request): JsonResponse
    {
        return $this->projectService()->removeCollaborator($request);
    }

    public function assignVendor(AssignVendorRequest $request): JsonResponse
    {
        return $this->projectService()->assignVendor($request);
    }

    public function listVendors(Request $request): JsonResponse
    {
        return $this->projectService()->listVendors($request);
    }

    public function removeVendor(Request $request): JsonResponse
    {
        return $this->projectService()->removeVendor($request);
    }

    public function updateVendor(Request $request): JsonResponse
    {
        return $this->projectService()->updateVendor($request);
    }

    public function allVendors(Request $request): JsonResponse
    {
        return $this->projectService()->allVendors($request);
    }

    public function generateLoa(Request $request): mixed
    {
        return $this->projectService()->generateLoa($request);
    }

    public function addExpense(AddExpenseRequest $request): JsonResponse
    {
        return $this->projectService()->addExpense($request);
    }

    public function deleteExpense(Request $request): JsonResponse
    {
        return $this->projectService()->deleteExpense($request);
    }

    public function financeData(Request $request): JsonResponse
    {
        return $this->projectService()->financeData($request);
    }

    public function addProgress(AddProgressRequest $request): JsonResponse
    {
        return $this->projectService()->addProgress($request);
    }

    public function listProgress(Request $request): JsonResponse
    {
        return $this->projectService()->listProgress($request);
    }

    public function updateProgress(UpdateProgressRequest $request): JsonResponse
    {
        return $this->projectService()->updateProgress($request);
    }

    public function deleteProgress(Request $request): JsonResponse
    {
        return $this->projectService()->deleteProgress($request);
    }
}
