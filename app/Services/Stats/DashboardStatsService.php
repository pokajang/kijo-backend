<?php

namespace App\Services\Stats;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardStatsService
{
    private function inquiryDashboardStatsService(): InquiryDashboardStatsService
    {
        return app(InquiryDashboardStatsService::class);
    }

    private function quoteDashboardStatsService(): QuoteDashboardStatsService
    {
        return app(QuoteDashboardStatsService::class);
    }

    private function awardedDashboardStatsService(): AwardedDashboardStatsService
    {
        return app(AwardedDashboardStatsService::class);
    }

    private function conversionDashboardStatsService(): ConversionDashboardStatsService
    {
        return app(ConversionDashboardStatsService::class);
    }

    private function financeDashboardStatsService(): FinanceDashboardStatsService
    {
        return app(FinanceDashboardStatsService::class);
    }

    private function workloadDashboardStatsService(): WorkloadDashboardStatsService
    {
        return app(WorkloadDashboardStatsService::class);
    }

    public function inquiryStats(Request $request): JsonResponse
    {
        return $this->inquiryDashboardStatsService()->inquiryStats($request);
    }

    public function inquiryStatsByValues(Request $request): JsonResponse
    {
        return $this->inquiryDashboardStatsService()->inquiryStatsByValues($request);
    }

    public function quoteValueByPerson(Request $request): JsonResponse
    {
        return $this->quoteDashboardStatsService()->quoteValueByPerson($request);
    }

    public function quoteValueByService(Request $request): JsonResponse
    {
        return $this->quoteDashboardStatsService()->quoteValueByService($request);
    }

    public function quoteCountByPerson(Request $request): JsonResponse
    {
        return $this->quoteDashboardStatsService()->quoteCountByPerson($request);
    }

    public function monthlyQuoteValueByService(Request $request): JsonResponse
    {
        return $this->quoteDashboardStatsService()->monthlyQuoteValueByService($request);
    }

    public function monthlyQuoteValue(Request $request): JsonResponse
    {
        return $this->quoteDashboardStatsService()->monthlyQuoteValue($request);
    }

    public function monthlyQuoteCount(Request $request): JsonResponse
    {
        return $this->quoteDashboardStatsService()->monthlyQuoteCount($request);
    }

    public function awardedValueByPerson(Request $request): JsonResponse
    {
        return $this->awardedDashboardStatsService()->awardedValueByPerson($request);
    }

    public function awardedValueBySource(Request $request): JsonResponse
    {
        return $this->awardedDashboardStatsService()->awardedValueBySource($request);
    }

    public function awardedValueByService(Request $request): JsonResponse
    {
        return $this->awardedDashboardStatsService()->awardedValueByService($request);
    }

    public function monthlySales(Request $request): JsonResponse
    {
        return $this->awardedDashboardStatsService()->monthlySales($request);
    }

    public function conversionRateBySource(Request $request): JsonResponse
    {
        return $this->conversionDashboardStatsService()->conversionRateBySource($request);
    }

    public function conversionRateByService(Request $request): JsonResponse
    {
        return $this->conversionDashboardStatsService()->conversionRateByService($request);
    }

    public function conversionRateByStaff(Request $request): JsonResponse
    {
        return $this->conversionDashboardStatsService()->conversionRateByStaff($request);
    }

    public function monthlyIncomeStatement(Request $request): JsonResponse
    {
        return $this->financeDashboardStatsService()->monthlyIncomeStatement($request);
    }

    public function monthlyInvoicedReceivedTrend(Request $request): JsonResponse
    {
        return $this->financeDashboardStatsService()->monthlyInvoicedReceivedTrend($request);
    }

    public function allDebtors(Request $request): JsonResponse
    {
        return $this->financeDashboardStatsService()->allDebtors($request);
    }

    public function workload(Request $request): JsonResponse
    {
        return $this->workloadDashboardStatsService()->workload($request);
    }

    public function workloadHistory(Request $request): JsonResponse
    {
        return app(WorkloadDailySnapshotService::class)->history($request);
    }

    public function workloadSnapshotHealth(Request $request): JsonResponse
    {
        return app(WorkloadSnapshotHealthService::class)->healthPayload();
    }

    public function workloadPdf(Request $request)
    {
        return app(WorkloadDashboardPdfService::class)->export($request);
    }

    public function createWorkloadShare(Request $request): JsonResponse
    {
        return app(WorkloadDashboardShareService::class)->create($request);
    }

    public function workloadShare(string $token): JsonResponse
    {
        return app(WorkloadDashboardShareService::class)->show($token);
    }
}
