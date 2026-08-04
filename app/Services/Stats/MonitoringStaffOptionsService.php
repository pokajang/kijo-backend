<?php

namespace App\Services\Stats;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringStaffOptionsService
{
    use MonitoringStatsCoreHelpers;
    use MonitoringStatsDetailHelpers;
    use MonitoringStatsEventHelpers;
    use MonitoringStatsLegalComplianceHelpers;
    use MonitoringStatsManualHelpers;
    use MonitoringStatsStaffHelpers;

    /**
     * Dashboard metric contract:
     * - Sales uses active/completed resolved project value by project award_date plus valid manual closed entries.
     * - CRM uses quote created_at for quotation and inquiry-source facts.
     * - Financial uses invoice_date for invoiced/open receivables and paid_date for received cash.
     * - Monitoring uses selected-month activity dates; revenue status uses award_date/manual closed entry_date.
     */
    private const MONITORING_YEARLY_TARGET = 3400000.0;

    private const MONITORING_INDIVIDUAL_TARGET = 860000.0;

    private const MONITORING_DETAIL_LIMIT = 1000;

    private const MONITORING_PIPELINE_TOOL_ROWS = [
        'LEADS',
        'QUALIFIED',
        'MEETING/ PITCHING',
        'PROPOSAL',
        'NEGOTIATION',
        'CLOSED',
    ];

    public function monitoringStaffOptions(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'status' => 'success',
                'staffOptions' => $this->buildMonitoringStaffOptions($request),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }
}
