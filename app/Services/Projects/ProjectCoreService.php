<?php

namespace App\Services\Projects;

use App\Http\Requests\Project\CloseProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function options(Request $request): JsonResponse
    {
        return $this->projectListService()->options($request);
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
