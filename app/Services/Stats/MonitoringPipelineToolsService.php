<?php

namespace App\Services\Stats;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonitoringPipelineToolsService
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

    public function monitoringPipelineTools(Request $request): JsonResponse
    {
        try {
            $context = $this->monitoringMonthContext($request);
            $staffFilter = $this->monitoringStaffFilter($request);
            if (! empty($staffFilter['forbidden'])) {
                return $this->monitoringStaffForbiddenResponse();
            }
            $quotesQuery = $this->baseQuoteLifecycleQuery()
                ->whereBetween(DB::raw('DATE(created_at)'), [$context['rangeStart'], $context['rangeEnd']]);
            $companyTotalRm = (float) $this->monitoringRealizedSalesProjectQuery(
                $context['rangeStart'],
                $context['rangeEnd'],
                ['code' => null]
            )->sum('value')
                + $this->monitoringManualClosedRevenueTotal(
                    $context['rangeStart'],
                    $context['rangeEnd'],
                    ['code' => null]
                );
            $yearToDateTotals = $this->monitoringYearToDateTotals($context, $staffFilter);
            if (! empty($staffFilter['code'])) {
                $quotesQuery->whereRaw('UPPER(staff_code) = ?', [$staffFilter['code']]);
            }
            $quotes = $quotesQuery->get();

            $rows = [];

            $manualEntries = $this->monitoringManualEntries($context, $staffFilter);
            $legalComplianceEvents = $this->monitoringLegalComplianceAssessmentEvents($context, $staffFilter);
            $quoteIssuedEvents = $this->monitoringQuoteActivityEvents($quotes, 'proposal-quote', 'individual', true);
            $rows[] = $this->monitoringToolsDistinctRow(
                'LEADS',
                array_merge(
                    $this->monitoringSystemLeadEvents($context, $staffFilter),
                    $manualEntries['events']['LEADS'] ?? []
                ),
                $context['periodColumns']
            );

            $rows[] = $this->monitoringToolsDistinctRow(
                'QUALIFIED',
                array_merge(
                    $this->monitoringSystemQualifiedEvents($quotes),
                    $manualEntries['events']['QUALIFIED'] ?? []
                ),
                $context['periodColumns']
            );

            $rows[] = $this->monitoringToolsDistinctRow(
                'MEETING/ PITCHING',
                array_merge(
                    $manualEntries['events']['MEETING/ PITCHING'] ?? [],
                    $legalComplianceEvents
                ),
                $context['periodColumns']
            );

            $rows[] = $this->monitoringToolsDistinctRow(
                'PROPOSAL',
                array_merge(
                    $quoteIssuedEvents,
                    $manualEntries['events']['PROPOSAL'] ?? []
                ),
                $context['periodColumns']
            );

            $rows[] = $this->monitoringToolsDistinctRow(
                'NEGOTIATION',
                array_merge(
                    $this->monitoringQuoteNegotiationEvents($context, $staffFilter),
                    $manualEntries['events']['NEGOTIATION'] ?? []
                ),
                $context['periodColumns']
            );

            $rows[] = $this->monitoringToolsDistinctRow(
                'CLOSED',
                array_merge(
                    $this->monitoringSystemClosedEvents($context, $staffFilter),
                    $manualEntries['events']['CLOSED'] ?? []
                ),
                $context['periodColumns']
            );

            $totals = $this->monitoringToolsTotalRow($rows, $context['periodColumns']);
            $currentTotalRm = (float) $this->monitoringRealizedSalesProjectQuery(
                $context['rangeStart'],
                $context['rangeEnd'],
                $staffFilter
            )->sum('value')
                + $this->monitoringManualClosedRevenueTotal(
                    $context['rangeStart'],
                    $context['rangeEnd'],
                    $staffFilter
                );

            return response()->json([
                'status' => 'success',
                'monthLabel' => $context['monthLabel'],
                'rangeStart' => $context['rangeStart'],
                'rangeEnd' => $context['rangeEnd'],
                'rangeLabel' => $context['rangeLabel'],
                'targets' => [
                    'yearly' => self::MONITORING_YEARLY_TARGET,
                    'individual' => self::MONITORING_INDIVIDUAL_TARGET,
                ],
                'staffOptions' => $this->buildMonitoringStaffOptions($request),
                'selectedStaffCode' => $staffFilter['code'],
                'weeks' => $context['weeks'],
                'periodColumns' => $context['periodColumns'],
                'rows' => $rows,
                'totals' => $totals,
                'companyTotalRm' => $companyTotalRm,
                'currentTotalRm' => $currentTotalRm,
                'yearToDateCompanyTotalRm' => $yearToDateTotals['companyTotalRm'],
                'yearToDateTotalRm' => $yearToDateTotals['selectedTotalRm'],
                'achievementPeriodLabel' => 'YTD to '.$context['monthLabel'],
                'manualEntries' => $manualEntries['rows'],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }
}
