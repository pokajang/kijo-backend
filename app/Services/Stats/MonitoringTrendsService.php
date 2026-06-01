<?php

namespace App\Services\Stats;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonitoringTrendsService
{
    use MonitoringStatsCoreHelpers;
    use MonitoringStatsDetailHelpers;
    use MonitoringStatsEventHelpers;
    use MonitoringStatsLegalComplianceHelpers;
    use MonitoringStatsManualHelpers;
    use MonitoringStatsStaffHelpers;

    /**
     * Dashboard metric contract:
     * - Sales uses active/completed project quote_value by project award_date plus valid manual closed entries.
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

    private const MONITORING_STATUS_ROWS = [
        'TRAINING',
        'CONSULTANCY -ISO',
        'CONSULTANCY - IHOH',
        'MAN POWER',
        'EQUIPMENT SUPPLY',
        'ENGINEERING',
        'INFRASTRUCTURE',
    ];

    private const MONITORING_MANUAL_SERVICE_CATEGORIES = [
        'training' => 'TRAINING',
        'consultancy_iso' => 'CONSULTANCY -ISO',
        'consultancy_ihoh' => 'CONSULTANCY - IHOH',
        'man_power' => 'MAN POWER',
        'equipment_supply' => 'EQUIPMENT SUPPLY',
        'engineering' => 'ENGINEERING',
        'infrastructure' => 'INFRASTRUCTURE',
    ];

    public function monitoringTrends(Request $request): JsonResponse
    {
        try {
            $trendContext = $this->monitoringTrendContext($request);
            $staffFilter = $this->monitoringStaffFilter($request);
            if (! empty($staffFilter['forbidden'])) {
                return $this->monitoringStaffForbiddenResponse();
            }

            $series = [];
            foreach ($trendContext['months'] as $month) {
                $monthContext = [
                    'monthStart' => $month['start'],
                    'monthEnd' => $month['end'],
                ];
                $quotesQuery = $this->baseQuoteLifecycleQuery()
                    ->whereBetween(DB::raw('DATE(created_at)'), [$month['start'], $month['end']]);
                if (! empty($staffFilter['code'])) {
                    $quotesQuery->whereRaw('UPPER(staff_code) = ?', [$staffFilter['code']]);
                }

                $quotes = $quotesQuery->get();
                $manualEntries = $this->monitoringManualEntries($monthContext, $staffFilter);
                $legalComplianceEvents = $this->monitoringLegalComplianceAssessmentEvents($monthContext, $staffFilter);
                $quoteIssuedEvents = $this->monitoringQuoteActivityEvents($quotes, 'proposal-quote', 'individual', true);
                $proposalEvents = array_merge(
                    $quoteIssuedEvents,
                    $manualEntries['events']['PROPOSAL'] ?? []
                );
                $closedEvents = array_merge(
                    $this->monitoringSystemClosedEvents($monthContext, $staffFilter),
                    $manualEntries['events']['CLOSED'] ?? []
                );
                $stageEvents = [
                    'LEADS' => array_merge(
                        $this->monitoringSystemLeadEvents($monthContext, $staffFilter),
                        $manualEntries['events']['LEADS'] ?? []
                    ),
                    'QUALIFIED' => array_merge(
                        $this->monitoringSystemQualifiedEvents($quotes),
                        $manualEntries['events']['QUALIFIED'] ?? []
                    ),
                    'MEETING/ PITCHING' => array_merge(
                        $manualEntries['events']['MEETING/ PITCHING'] ?? [],
                        $legalComplianceEvents
                    ),
                    'PROPOSAL' => $proposalEvents,
                    'NEGOTIATION' => $manualEntries['events']['NEGOTIATION'] ?? [],
                    'CLOSED' => $closedEvents,
                ];

                $stages = [];
                foreach (self::MONITORING_PIPELINE_TOOL_ROWS as $stage) {
                    $stages[$stage] = $this->monitoringDistinctEventCount($stageEvents[$stage] ?? []);
                }

                $proposalValue = $this->monitoringEventValueTotal($proposalEvents);
                $revenueValue = $this->monitoringEventValueTotal($closedEvents);
                $proposalQty = $this->monitoringDistinctEventCount($proposalEvents);
                $revenueQty = $this->monitoringDistinctEventCount($closedEvents);

                $series[] = [
                    'month' => $month['key'],
                    'monthLabel' => $month['label'],
                    'stages' => $stages,
                    'proposalQty' => $proposalQty,
                    'proposalRm' => $proposalValue,
                    'revenueQty' => $revenueQty,
                    'revenueRm' => $revenueValue,
                    'winRate' => $proposalQty > 0 ? round(($revenueQty / $proposalQty) * 100, 1) : 0.0,
                ];
            }

            return response()->json([
                'status' => 'success',
                'period' => $trendContext['period'],
                'periodLabel' => $trendContext['periodLabel'],
                'rangeStart' => $trendContext['rangeStart'],
                'rangeEnd' => $trendContext['rangeEnd'],
                'selectedStaffCode' => $staffFilter['code'],
                'series' => $series,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }
}
