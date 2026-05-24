<?php

namespace App\Services\Monitoring;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ManualPipelineEntryService
{
    private function manualPipelineEntryMutationService(): ManualPipelineEntryMutationService
    {
        return app(ManualPipelineEntryMutationService::class);
    }

    private function manualPipelineEntryQueryService(): ManualPipelineEntryQueryService
    {
        return app(ManualPipelineEntryQueryService::class);
    }

    private function manualPipelineEntryPhotoService(): ManualPipelineEntryPhotoService
    {
        return app(ManualPipelineEntryPhotoService::class);
    }

    public function create(Request $request): JsonResponse
    {
        return $this->manualPipelineEntryMutationService()->create($request);
    }

    public function update(Request $request): JsonResponse
    {
        return $this->manualPipelineEntryMutationService()->update($request);
    }

    public function delete(Request $request): JsonResponse
    {
        return $this->manualPipelineEntryMutationService()->delete($request);
    }

    public function list(Request $request, ?string $start, ?string $end, array $staffFilter): array
    {
        return $this->manualPipelineEntryQueryService()->list($request, $start, $end, $staffFilter);
    }

    public function find(Request $request, int $id): ?array
    {
        return $this->manualPipelineEntryQueryService()->find($request, $id);
    }

    public function entriesTableReady(): bool
    {
        return $this->manualPipelineEntryQueryService()->entriesTableReady();
    }

    public function viewPhoto(Request $request)
    {
        return $this->manualPipelineEntryPhotoService()->viewPhoto($request);
    }

}
