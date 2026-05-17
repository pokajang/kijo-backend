<?php

namespace App\Services\Projects;

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

class ProjectCoreService
{
    private function projectListService(): ProjectListService
    {
        return app(ProjectListService::class);
    }

    private function projectMutationService(): ProjectMutationService
    {
        return app(ProjectMutationService::class);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->projectListService()->index($request);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        return $this->projectMutationService()->store($request);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->projectListService()->show($request, $id);
    }

    public function update(UpdateProjectRequest $request): JsonResponse
    {
        return $this->projectMutationService()->update($request);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->projectMutationService()->destroy($request, $id);
    }

    public function close(CloseProjectRequest $request): JsonResponse
    {
        return $this->projectMutationService()->close($request);
    }

    public function reloadPoNumber(Request $request): JsonResponse
    {
        return $this->projectMutationService()->reloadPoNumber($request);
    }

    public function crmDetails(Request $request): JsonResponse
    {
        return $this->projectListService()->crmDetails($request);
    }
}
