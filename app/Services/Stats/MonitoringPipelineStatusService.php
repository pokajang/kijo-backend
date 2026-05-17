<?php

namespace App\Services\Stats;

use App\Services\Monitoring\ManualPipelineEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MonitoringPipelineStatusService
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

    public function monitoringPipelineStatus(Request $request): JsonResponse
    {
        try {
            $context = $this->monitoringMonthContext($request);
            $staffFilter = $this->monitoringStaffFilter($request);
            if (!empty($staffFilter['forbidden'])) {
                return $this->monitoringStaffForbiddenResponse();
            }
            $quotesQuery = $this->baseQuoteLifecycleQuery()
                ->whereIn(DB::raw('UPPER(quote_status)'), ['AWARDED', 'WON'])
                ->whereBetween(DB::raw('DATE(award_date)'), [$context['monthStart'], $context['monthEnd']]);
            $companyTotalRm = (float) (clone $quotesQuery)->sum('value');
            $yearToDateTotals = $this->monitoringYearToDateTotals($context, $staffFilter);
            if (!empty($staffFilter['code'])) {
                $quotesQuery->whereRaw('UPPER(staff_code) = ?', [$staffFilter['code']]);
            }
            $quotes = $quotesQuery->get();

            $rowsByLabel = [];
            foreach (self::MONITORING_STATUS_ROWS as $label) {
                $tracksIndividualQuoteRevenue = $this->monitoringStatusLabelHasDirectIndividualSource($label);
                $rowsByLabel[$label] = [
                    'label' => $label,
                    'weekly' => $this->monitoringWeeklyMetricSeed($context['weeks']),
                    'totalQty' => 0,
                    'totalRm' => 0.0,
                    'individualQty' => $tracksIndividualQuoteRevenue ? 0 : null,
                    'individualRm' => $tracksIndividualQuoteRevenue ? 0.0 : null,
                    'specialProjectQty' => 0,
                    'specialProjectRm' => 0.0,
                    'tenderQty' => 0,
                    'tenderRm' => 0.0,
                    'details' => $this->monitoringStatusDetailSeed($context['weeks']),
                ];
            }

            foreach ($quotes as $quote) {
                $eventDate = !empty($quote->award_date) ? Carbon::parse($quote->award_date)->format('Y-m-d') : null;
                $weekKey = $this->monitoringResolveWeekKey($eventDate, $context['weeks']);
                if ($weekKey === null) {
                    continue;
                }

                $label = $this->mapQuoteToMonitoringStatusLabel((string) $quote->service_group, (string) ($quote->service_title ?? ''));
                if (!isset($rowsByLabel[$label])) {
                    continue;
                }

                $value = (float) ($quote->value ?? 0);
                $contributor = $this->monitoringQuoteContributor($quote, $eventDate, 'awarded-quote');
                $rowsByLabel[$label]['weekly'][$weekKey]['qty'] += 1;
                $rowsByLabel[$label]['weekly'][$weekKey]['rm'] += $value;
                $rowsByLabel[$label]['totalQty'] += 1;
                $rowsByLabel[$label]['totalRm'] += $value;

                $segment = $this->monitoringQuoteHasDirectIndividualStatusSource((string) $quote->service_group)
                    ? 'individual'
                    : null;
                if ($segment !== null) {
                    $rowsByLabel[$label]['individualQty'] = (int) ($rowsByLabel[$label]['individualQty'] ?? 0) + 1;
                    $rowsByLabel[$label]['individualRm'] = (float) ($rowsByLabel[$label]['individualRm'] ?? 0) + $value;
                }
                $this->monitoringAppendStatusContributor(
                    $rowsByLabel[$label],
                    $weekKey,
                    $segment,
                    $contributor
                );
            }

            $manualEntries = $this->monitoringManualEntries($context, $staffFilter);
            foreach ($manualEntries['serviceEvents'] ?? [] as $label => $events) {
                if (!isset($rowsByLabel[$label])) {
                    continue;
                }

                foreach ($events as $event) {
                    if (($event['contributor']['eventType'] ?? '') !== 'CLOSED') {
                        continue;
                    }

                    $weekKey = $this->monitoringResolveWeekKey($event['date'] ?? null, $context['weeks']);
                    if ($weekKey === null) {
                        continue;
                    }

                    $value = isset($event['value']) && is_numeric($event['value']) ? (float) $event['value'] : 0.0;
                    $segment = (string) ($event['segment'] ?? 'individual');
                    $contributor = $this->monitoringContributorFromEvent($event);

                    $rowsByLabel[$label]['weekly'][$weekKey]['qty'] += 1;
                    $rowsByLabel[$label]['weekly'][$weekKey]['rm'] += $value;
                    $rowsByLabel[$label]['totalQty'] += 1;
                    $rowsByLabel[$label]['totalRm'] += $value;

                    if ($segment === 'special_project') {
                    $rowsByLabel[$label]['specialProjectQty'] += 1;
                    $rowsByLabel[$label]['specialProjectRm'] += $value;
                } elseif ($segment === 'tender') {
                    $rowsByLabel[$label]['tenderQty'] += 1;
                    $rowsByLabel[$label]['tenderRm'] += $value;
                } else {
                    $rowsByLabel[$label]['individualQty'] = (int) ($rowsByLabel[$label]['individualQty'] ?? 0) + 1;
                    $rowsByLabel[$label]['individualRm'] = (float) ($rowsByLabel[$label]['individualRm'] ?? 0) + $value;
                }
                    $this->monitoringAppendStatusContributor(
                        $rowsByLabel[$label],
                        $weekKey,
                        $segment,
                        $contributor
                    );
                }
            }

            $rows = array_values($rowsByLabel);
            $totals = [
                'label' => 'TOTAL',
                'weekly' => $this->monitoringWeeklyMetricSeed($context['weeks']),
                'totalQty' => 0,
                'totalRm' => 0.0,
                'individualQty' => 0,
                'individualRm' => 0.0,
                'specialProjectQty' => 0,
                'specialProjectRm' => 0.0,
                'tenderQty' => 0,
                'tenderRm' => 0.0,
                'details' => $this->monitoringStatusDetailSeed($context['weeks']),
            ];

            foreach ($rows as &$row) {
                foreach ($context['weeks'] as $week) {
                    $key = $week['key'];
                    $totals['weekly'][$key]['qty'] += $row['weekly'][$key]['qty'];
                    $totals['weekly'][$key]['rm'] += $row['weekly'][$key]['rm'];
                }
                $totals['totalQty'] += $row['totalQty'];
                $totals['totalRm'] += $row['totalRm'];
                $this->monitoringMergeStatusDetails($totals['details'], $row['details']);
                $this->monitoringFinalizeStatusDetails($row);
            }
            unset($row);
            $totals['individualQty'] = $this->monitoringSumNullable($rows, 'individualQty');
            $totals['individualRm'] = $this->monitoringSumNullableValue($rows, 'individualRm');
            $this->monitoringFinalizeStatusDetails($totals);

            return response()->json([
                'status' => 'success',
                'monthLabel' => $context['monthLabel'],
                'targets' => [
                    'yearly' => self::MONITORING_YEARLY_TARGET,
                    'individual' => self::MONITORING_INDIVIDUAL_TARGET,
                ],
                'staffOptions' => $this->buildMonitoringStaffOptions($request),
                'selectedStaffCode' => $staffFilter['code'],
                'weeks' => $context['weeks'],
                'rows' => $rows,
                'totals' => $totals,
                'companyTotalRm' => $companyTotalRm,
                'yearToDateCompanyTotalRm' => $yearToDateTotals['companyTotalRm'],
                'yearToDateTotalRm' => $yearToDateTotals['selectedTotalRm'],
                'achievementPeriodLabel' => 'YTD to ' . $context['monthLabel'],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

}
