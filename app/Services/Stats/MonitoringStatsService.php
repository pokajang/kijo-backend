<?php

namespace App\Services\Stats;

use App\Services\Monitoring\ManualPipelineEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MonitoringStatsService
{
    private function monitoringPipelineToolsService(): MonitoringPipelineToolsService
    {
        return app(MonitoringPipelineToolsService::class);
    }

    private function monitoringPipelineStatusService(): MonitoringPipelineStatusService
    {
        return app(MonitoringPipelineStatusService::class);
    }

    private function monitoringStaffOptionsService(): MonitoringStaffOptionsService
    {
        return app(MonitoringStaffOptionsService::class);
    }

    private function monitoringStaffPipelineMatrixService(): MonitoringStaffPipelineMatrixService
    {
        return app(MonitoringStaffPipelineMatrixService::class);
    }

    private function monitoringTrendsService(): MonitoringTrendsService
    {
        return app(MonitoringTrendsService::class);
    }

    public function monitoringPipelineTools(Request $request): JsonResponse
    {
        return $this->monitoringPipelineToolsService()->monitoringPipelineTools($request);
    }

    public function monitoringPipelineStatus(Request $request): JsonResponse
    {
        return $this->monitoringPipelineStatusService()->monitoringPipelineStatus($request);
    }

    public function monitoringStaffOptions(Request $request): JsonResponse
    {
        return $this->monitoringStaffOptionsService()->monitoringStaffOptions($request);
    }

    public function monitoringStaffPipelineMatrix(Request $request): JsonResponse
    {
        return $this->monitoringStaffPipelineMatrixService()->monitoringStaffPipelineMatrix($request);
    }

    public function monitoringTrends(Request $request): JsonResponse
    {
        return $this->monitoringTrendsService()->monitoringTrends($request);
    }
}
