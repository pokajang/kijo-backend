<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Stats\StatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    private function statsService(): StatsService
    {
        return app(StatsService::class);
    }

    public function inquiryStats(Request $request): JsonResponse
    {
        return $this->statsService()->inquiryStats($request);
    }

    public function inquiryStatsByValues(Request $request): JsonResponse
    {
        return $this->statsService()->inquiryStatsByValues($request);
    }

    public function quoteValueByPerson(Request $request): JsonResponse
    {
        return $this->statsService()->quoteValueByPerson($request);
    }

    public function quoteValueByService(Request $request): JsonResponse
    {
        return $this->statsService()->quoteValueByService($request);
    }

    public function quoteCountByPerson(Request $request): JsonResponse
    {
        return $this->statsService()->quoteCountByPerson($request);
    }

    public function monthlyQuoteValueByService(Request $request): JsonResponse
    {
        return $this->statsService()->monthlyQuoteValueByService($request);
    }

    public function monthlyQuoteValue(Request $request): JsonResponse
    {
        return $this->statsService()->monthlyQuoteValue($request);
    }

    public function monthlyQuoteCount(Request $request): JsonResponse
    {
        return $this->statsService()->monthlyQuoteCount($request);
    }

    public function awardedValueByPerson(Request $request): JsonResponse
    {
        return $this->statsService()->awardedValueByPerson($request);
    }

    public function awardedValueBySource(Request $request): JsonResponse
    {
        return $this->statsService()->awardedValueBySource($request);
    }

    public function awardedValueByService(Request $request): JsonResponse
    {
        return $this->statsService()->awardedValueByService($request);
    }

    public function monthlySales(Request $request): JsonResponse
    {
        return $this->statsService()->monthlySales($request);
    }

    public function conversionRateBySource(Request $request): JsonResponse
    {
        return $this->statsService()->conversionRateBySource($request);
    }

    public function conversionRateByService(Request $request): JsonResponse
    {
        return $this->statsService()->conversionRateByService($request);
    }

    public function conversionRateByStaff(Request $request): JsonResponse
    {
        return $this->statsService()->conversionRateByStaff($request);
    }

    public function monthlyIncomeStatement(Request $request): JsonResponse
    {
        return $this->statsService()->monthlyIncomeStatement($request);
    }

    public function monthlyInvoicedReceivedTrend(Request $request): JsonResponse
    {
        return $this->statsService()->monthlyInvoicedReceivedTrend($request);
    }

    public function allDebtors(Request $request): JsonResponse
    {
        return $this->statsService()->allDebtors($request);
    }

    public function workload(Request $request): JsonResponse
    {
        return $this->statsService()->workload($request);
    }

    public function workloadHistory(Request $request): JsonResponse
    {
        return $this->statsService()->workloadHistory($request);
    }

    public function workloadSnapshotHealth(Request $request): JsonResponse
    {
        return $this->statsService()->workloadSnapshotHealth($request);
    }

    public function workloadPdf(Request $request)
    {
        return $this->statsService()->workloadPdf($request);
    }

    public function createWorkloadShare(Request $request): JsonResponse
    {
        return $this->statsService()->createWorkloadShare($request);
    }

    public function workloadShare(string $token): JsonResponse
    {
        return $this->statsService()->workloadShare($token);
    }

    public function monthlyDashboardReportPdf(Request $request)
    {
        return $this->statsService()->monthlyDashboardReportPdf($request);
    }

    public function publicMonthlyDashboardReport(string $token)
    {
        return $this->statsService()->publicMonthlyDashboardReport($token);
    }

    public function monitoringPipelineTools(Request $request): JsonResponse
    {
        return $this->statsService()->monitoringPipelineTools($request);
    }

    public function monitoringPipelineStatus(Request $request): JsonResponse
    {
        return $this->statsService()->monitoringPipelineStatus($request);
    }

    public function monitoringStaffOptions(Request $request): JsonResponse
    {
        return $this->statsService()->monitoringStaffOptions($request);
    }

    public function monitoringStaffPipelineMatrix(Request $request): JsonResponse
    {
        return $this->statsService()->monitoringStaffPipelineMatrix($request);
    }

    public function monitoringTrends(Request $request): JsonResponse
    {
        return $this->statsService()->monitoringTrends($request);
    }

    public function createMonitoringManualPipelineEntry(Request $request): JsonResponse
    {
        return $this->statsService()->createMonitoringManualPipelineEntry($request);
    }

    public function updateMonitoringManualPipelineEntry(Request $request): JsonResponse
    {
        return $this->statsService()->updateMonitoringManualPipelineEntry($request);
    }

    public function monitoringManualPipelineEntries(Request $request): JsonResponse
    {
        return $this->statsService()->monitoringManualPipelineEntries($request);
    }

    public function monitoringManualPipelineEntry(Request $request): JsonResponse
    {
        return $this->statsService()->monitoringManualPipelineEntry($request);
    }

    public function deleteMonitoringManualPipelineEntry(Request $request): JsonResponse
    {
        return $this->statsService()->deleteMonitoringManualPipelineEntry($request);
    }

    public function viewMonitoringManualPipelineEntryPhoto(Request $request)
    {
        return $this->statsService()->viewMonitoringManualPipelineEntryPhoto($request);
    }
}
