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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectService
{
    private function projectCoreService(): ProjectCoreService
    {
        return app(ProjectCoreService::class);
    }

    private function projectVendorService(): ProjectVendorService
    {
        return app(ProjectVendorService::class);
    }

    private function projectLoaService(): ProjectLoaService
    {
        return app(ProjectLoaService::class);
    }

    private function projectFinanceService(): ProjectFinanceService
    {
        return app(ProjectFinanceService::class);
    }

    private function projectProgressService(): ProjectProgressService
    {
        return app(ProjectProgressService::class);
    }

    private function projectCommercialDocsService(): ProjectCommercialDocsService
    {
        return app(ProjectCommercialDocsService::class);
    }

    private function projectValueService(): ProjectValueService
    {
        return app(ProjectValueService::class);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->projectCoreService()->index($request);
    }

    public function options(Request $request): JsonResponse
    {
        return $this->projectCoreService()->options($request);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        return $this->projectCoreService()->store($request);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->projectCoreService()->show($request, $id);
    }

    public function update(UpdateProjectRequest $request): JsonResponse
    {
        return $this->projectCoreService()->update($request);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->projectCoreService()->destroy($request, $id);
    }

    public function close(CloseProjectRequest $request): JsonResponse
    {
        return $this->projectCoreService()->close($request);
    }

    public function reloadPoNumber(Request $request): JsonResponse
    {
        return $this->projectCoreService()->reloadPoNumber($request);
    }

    public function updateValue(Request $request): JsonResponse
    {
        return $this->projectValueService()->updateProjectValueFromRequest(
            $request,
            (int) $request->input('project_id')
        );
    }

    public function previewValueImpact(Request $request): JsonResponse
    {
        return $this->projectValueService()->previewProjectValueImpact(
            $request,
            (int) $request->input('project_id')
        );
    }

    public function crmDetails(Request $request): JsonResponse
    {
        return $this->projectCoreService()->crmDetails($request);
    }

    public function commercialDocs(Request $request): JsonResponse
    {
        return $this->projectCommercialDocsService()->commercialDocs($request);
    }

    public function addCollaborator(AddCollaboratorRequest $request): JsonResponse
    {
        return $this->projectVendorService()->addCollaborator($request);
    }

    public function listCollaborators(Request $request): JsonResponse
    {
        return $this->projectVendorService()->listCollaborators($request);
    }

    public function removeCollaborator(Request $request): JsonResponse
    {
        return $this->projectVendorService()->removeCollaborator($request);
    }

    public function assignVendor(AssignVendorRequest $request): JsonResponse
    {
        return $this->projectVendorService()->assignVendor($request);
    }

    public function listVendors(Request $request): JsonResponse
    {
        return $this->projectVendorService()->listVendors($request);
    }

    public function removeVendor(Request $request): JsonResponse
    {
        return $this->projectVendorService()->removeVendor($request);
    }

    public function updateVendor(Request $request): JsonResponse
    {
        return $this->projectVendorService()->updateVendor($request);
    }

    public function allVendors(Request $request): JsonResponse
    {
        return $this->projectVendorService()->allVendors($request);
    }

    public function generateLoa(Request $request): mixed
    {
        return $this->projectLoaService()->generateLoa($request);
    }

    public function addExpense(AddExpenseRequest $request): JsonResponse
    {
        return $this->projectFinanceService()->addExpense($request);
    }

    public function deleteExpense(Request $request): JsonResponse
    {
        return $this->projectFinanceService()->deleteExpense($request);
    }

    public function financeData(Request $request): JsonResponse
    {
        return $this->projectFinanceService()->financeData($request);
    }

    public function addProgress(AddProgressRequest $request): JsonResponse
    {
        return $this->projectProgressService()->addProgress($request);
    }

    public function listProgress(Request $request): JsonResponse
    {
        return $this->projectProgressService()->listProgress($request);
    }

    public function updateProgress(UpdateProgressRequest $request): JsonResponse
    {
        return $this->projectProgressService()->updateProgress($request);
    }

    public function deleteProgress(Request $request): JsonResponse
    {
        return $this->projectProgressService()->deleteProgress($request);
    }
}
