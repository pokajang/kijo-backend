<?php

namespace App\Services\Stats;

use App\Services\Monitoring\ManualPipelineEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MonitoringStaffPipelineMatrixService
{
    use MonitoringStatsCoreHelpers;
    use MonitoringStatsManualHelpers;
    use MonitoringStatsStaffHelpers;
    use MonitoringStatsEventHelpers;
    use MonitoringStatsDetailHelpers;

    /**
     * Dashboard metric contract:
     * - Sales uses award_date for system AWARDED/WON quote facts plus revenue-complete manual closed entries.
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

    public function monitoringStaffPipelineMatrix(Request $request): JsonResponse
    {
        try {
            if (!$this->canViewOtherMonitoringStaff($request)) {
                return $this->monitoringStaffForbiddenResponse();
            }

            $context = $this->monitoringMonthContext($request);
            $staffFilter = ['code' => null, 'forbidden' => false];
            $quotes = $this->baseQuoteLifecycleQuery()
                ->whereBetween(DB::raw('DATE(created_at)'), [$context['monthStart'], $context['monthEnd']])
                ->get();
            $manualEntries = $this->monitoringManualEntries($context, $staffFilter);
            $quoteIssuedEvents = $this->monitoringQuoteActivityEvents($quotes, 'proposal-quote', 'individual', true);
            $stageEvents = [
                'LEADS' => array_merge(
                    $this->monitoringSystemLeadEvents($context, $staffFilter),
                    $manualEntries['events']['LEADS'] ?? []
                ),
                'QUALIFIED' => array_merge(
                    $this->monitoringSystemQualifiedEvents($quotes),
                    $manualEntries['events']['QUALIFIED'] ?? []
                ),
                'MEETING/ PITCHING' => $manualEntries['events']['MEETING/ PITCHING'] ?? [],
                'PROPOSAL' => array_merge(
                    $quoteIssuedEvents,
                    $manualEntries['events']['PROPOSAL'] ?? []
                ),
                'NEGOTIATION' => array_merge(
                    $this->monitoringQuoteNegotiationEvents($context, $staffFilter),
                    $manualEntries['events']['NEGOTIATION'] ?? []
                ),
                'CLOSED' => array_merge(
                    $this->monitoringSystemClosedEvents($context, $staffFilter),
                    $manualEntries['events']['CLOSED'] ?? []
                ),
            ];

            $rowsByStaff = [];
            $totals = $this->monitoringStaffMatrixEmptyRow('TOTAL', 'Total', 'Total');

            foreach ($stageEvents as $stage => $events) {
                foreach ($events as $event) {
                    $contributor = $event['contributor'] ?? null;
                    if (!is_array($contributor)) {
                        continue;
                    }

                    $staffCode = $this->monitoringContributorStaffCode($contributor);
                    if (!isset($rowsByStaff[$staffCode])) {
                        $rowsByStaff[$staffCode] = $this->monitoringStaffMatrixEmptyRow(
                            $staffCode,
                            $this->monitoringContributorStaffName($contributor),
                            $this->monitoringContributorStaffLabel($contributor, $staffCode)
                        );
                    }

                    $eventKey = (string) ($event['key'] ?? $this->monitoringDetailItemKey($contributor, $stage));
                    $rowsByStaff[$staffCode]['stageItems'][$stage][$eventKey] = $contributor;
                    $totals['stageItems'][$stage][$staffCode . '|' . $eventKey] = $contributor;
                }
            }

            foreach (array_merge($quoteIssuedEvents, $manualEntries['events']['PROPOSAL'] ?? []) as $event) {
                $contributor = $event['contributor'] ?? null;
                if (!is_array($contributor)) {
                    continue;
                }

                $staffCode = $this->monitoringContributorStaffCode($contributor);
                if (!isset($rowsByStaff[$staffCode])) {
                    $rowsByStaff[$staffCode] = $this->monitoringStaffMatrixEmptyRow(
                        $staffCode,
                        $this->monitoringContributorStaffName($contributor),
                        $this->monitoringContributorStaffLabel($contributor, $staffCode)
                    );
                }

                $segmentKey = $this->monitoringSegmentDetailKey((string) ($event['segment'] ?? 'individual'));
                $eventKey = (string) ($event['key'] ?? $this->monitoringDetailItemKey($contributor, $segmentKey));
                $value = isset($event['value']) && is_numeric($event['value']) ? (float) $event['value'] : 0.0;

                $rowsByStaff[$staffCode]['segmentItems'][$segmentKey]['qty'][$eventKey] = $contributor;
                $rowsByStaff[$staffCode]['segmentItems'][$segmentKey]['rm'][$eventKey] = $contributor;
                $rowsByStaff[$staffCode]['segmentValues'][$segmentKey][$eventKey] = $value;

                $totalKey = $staffCode . '|' . $eventKey;
                $totals['segmentItems'][$segmentKey]['qty'][$totalKey] = $contributor;
                $totals['segmentItems'][$segmentKey]['rm'][$totalKey] = $contributor;
                $totals['segmentValues'][$segmentKey][$totalKey] = $value;
            }

            $rows = array_values(array_map(
                fn(array $row) => $this->monitoringFinalizeStaffMatrixRow($row),
                $rowsByStaff
            ));

            usort($rows, fn($left, $right) => strcasecmp($left['staffLabel'], $right['staffLabel']));

            return response()->json([
                'status' => 'success',
                'monthLabel' => $context['monthLabel'],
                'rows' => $rows,
                'totals' => $this->monitoringFinalizeStaffMatrixRow($totals),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

}
