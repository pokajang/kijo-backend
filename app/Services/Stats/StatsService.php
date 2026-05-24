<?php

namespace App\Services\Stats;

use App\Services\Monitoring\ManualPipelineEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StatsService
{
    private function dashboardStatsService(): DashboardStatsService
    {
        return app(DashboardStatsService::class);
    }

    private function monitoringStatsService(): MonitoringStatsService
    {
        return app(MonitoringStatsService::class);
    }

    private function monitoringManualStatsService(): MonitoringManualStatsService
    {
        return app(MonitoringManualStatsService::class);
    }

    public function inquiryStats(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->inquiryStats($request);
    }

    public function inquiryStatsByValues(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->inquiryStatsByValues($request);
    }

    public function quoteValueByPerson(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->quoteValueByPerson($request);
    }

    public function quoteValueByService(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->quoteValueByService($request);
    }

    public function quoteCountByPerson(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->quoteCountByPerson($request);
    }

    public function monthlyQuoteValueByService(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->monthlyQuoteValueByService($request);
    }

    public function monthlyQuoteValue(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->monthlyQuoteValue($request);
    }

    public function monthlyQuoteCount(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->monthlyQuoteCount($request);
    }

    public function awardedValueByPerson(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->awardedValueByPerson($request);
    }

    public function awardedValueBySource(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->awardedValueBySource($request);
    }

    public function awardedValueByService(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->awardedValueByService($request);
    }

    public function monthlySales(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->monthlySales($request);
    }

    public function conversionRateBySource(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->conversionRateBySource($request);
    }

    public function conversionRateByService(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->conversionRateByService($request);
    }

    public function conversionRateByStaff(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->conversionRateByStaff($request);
    }

    public function monthlyIncomeStatement(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->monthlyIncomeStatement($request);
    }

    public function monthlyInvoicedReceivedTrend(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->monthlyInvoicedReceivedTrend($request);
    }

    public function allDebtors(Request $request): JsonResponse
    {
        return $this->dashboardStatsService()->allDebtors($request);
    }

    public function monitoringPipelineTools(Request $request): JsonResponse
    {
        return $this->monitoringStatsService()->monitoringPipelineTools($request);
    }

    public function monitoringPipelineStatus(Request $request): JsonResponse
    {
        return $this->monitoringStatsService()->monitoringPipelineStatus($request);
    }

    public function monitoringStaffOptions(Request $request): JsonResponse
    {
        return $this->monitoringStatsService()->monitoringStaffOptions($request);
    }

    public function monitoringStaffPipelineMatrix(Request $request): JsonResponse
    {
        return $this->monitoringStatsService()->monitoringStaffPipelineMatrix($request);
    }

    public function monitoringTrends(Request $request): JsonResponse
    {
        return $this->monitoringStatsService()->monitoringTrends($request);
    }

    public function createMonitoringManualPipelineEntry(Request $request): JsonResponse
    {
        return $this->monitoringManualStatsService()->createMonitoringManualPipelineEntry($request);
    }

    public function updateMonitoringManualPipelineEntry(Request $request): JsonResponse
    {
        return $this->monitoringManualStatsService()->updateMonitoringManualPipelineEntry($request);
    }

    public function monitoringManualPipelineEntries(Request $request): JsonResponse
    {
        return $this->monitoringManualStatsService()->monitoringManualPipelineEntries($request);
    }

    public function monitoringManualPipelineEntry(Request $request): JsonResponse
    {
        return $this->monitoringManualStatsService()->monitoringManualPipelineEntry($request);
    }

    public function deleteMonitoringManualPipelineEntry(Request $request): JsonResponse
    {
        return $this->monitoringManualStatsService()->deleteMonitoringManualPipelineEntry($request);
    }

    public function viewMonitoringManualPipelineEntryPhoto(Request $request)
    {
        return $this->monitoringManualStatsService()->viewMonitoringManualPipelineEntryPhoto($request);
    }
}
