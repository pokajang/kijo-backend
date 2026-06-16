<?php

namespace App\Services\Stats;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MonitoringPipelineStatusService
{
    use MonitoringStatsCoreHelpers;
    use MonitoringStatsDetailHelpers;
    use MonitoringStatsEventHelpers;
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

    public function monitoringPipelineStatus(Request $request): JsonResponse
    {
        try {
            $context = $this->monitoringMonthContext($request);
            $staffFilter = $this->monitoringStaffFilter($request);
            if (! empty($staffFilter['forbidden'])) {
                return $this->monitoringStaffForbiddenResponse();
            }
            $companyProjectsQuery = $this->monitoringRealizedSalesProjectQuery(
                $context['rangeStart'],
                $context['rangeEnd'],
                ['code' => null]
            );
            $companyTotalRm = (float) $companyProjectsQuery->sum('value')
                + $this->monitoringManualClosedRevenueTotal(
                    $context['rangeStart'],
                    $context['rangeEnd'],
                    ['code' => null]
                );
            $yearToDateTotals = $this->monitoringYearToDateTotals($context, $staffFilter);
            $projects = $this->monitoringRealizedSalesProjectQuery(
                $context['rangeStart'],
                $context['rangeEnd'],
                $staffFilter
            )->get();

            $rowsByLabel = [];
            foreach (self::MONITORING_STATUS_ROWS as $label) {
                $tracksIndividualQuoteRevenue = $this->monitoringStatusLabelHasDirectIndividualSource($label);
                $rowsByLabel[$label] = [
                    'label' => $label,
                    'weekly' => $this->monitoringPeriodicMetricSeed($context['periodColumns']),
                    'periodic' => $this->monitoringPeriodicMetricSeed($context['periodColumns']),
                    'totalQty' => 0,
                    'totalRm' => 0.0,
                    'individualQty' => $tracksIndividualQuoteRevenue ? 0 : null,
                    'individualRm' => $tracksIndividualQuoteRevenue ? 0.0 : null,
                    'specialProjectQty' => 0,
                    'specialProjectRm' => 0.0,
                    'tenderQty' => 0,
                    'tenderRm' => 0.0,
                    'details' => $this->monitoringStatusDetailSeed($context['periodColumns']),
                ];
            }

            foreach ($projects as $project) {
                $eventDate = ! empty($project->award_date) ? Carbon::parse($project->award_date)->format('Y-m-d') : null;
                $periodKey = $this->monitoringResolvePeriodColumnKey($eventDate, $context['periodColumns']);
                if ($periodKey === null) {
                    continue;
                }

                $label = $this->mapQuoteToMonitoringStatusLabel((string) $project->service_group, (string) ($project->service_title ?? ''));
                if (! isset($rowsByLabel[$label])) {
                    continue;
                }

                $value = (float) ($project->value ?? 0);
                $contributor = $this->monitoringProjectContributor($project, $eventDate, 'closed-project');
                $rowsByLabel[$label]['periodic'][$periodKey]['qty'] += 1;
                $rowsByLabel[$label]['periodic'][$periodKey]['rm'] += $value;
                $rowsByLabel[$label]['weekly'][$periodKey] = $rowsByLabel[$label]['periodic'][$periodKey];
                $rowsByLabel[$label]['totalQty'] += 1;
                $rowsByLabel[$label]['totalRm'] += $value;

                $segment = $this->monitoringQuoteHasDirectIndividualStatusSource((string) $project->service_group)
                    ? 'individual'
                    : null;
                if ($segment !== null) {
                    $rowsByLabel[$label]['individualQty'] = (int) ($rowsByLabel[$label]['individualQty'] ?? 0) + 1;
                    $rowsByLabel[$label]['individualRm'] = (float) ($rowsByLabel[$label]['individualRm'] ?? 0) + $value;
                }
                $this->monitoringAppendStatusContributor(
                    $rowsByLabel[$label],
                    $periodKey,
                    $segment,
                    $contributor
                );
            }

            $manualEntries = $this->monitoringManualEntries($context, $staffFilter);
            foreach ($manualEntries['serviceEvents'] ?? [] as $label => $events) {
                if (! isset($rowsByLabel[$label])) {
                    continue;
                }

                foreach ($events as $event) {
                    if (($event['contributor']['eventType'] ?? '') !== 'CLOSED') {
                        continue;
                    }

                    $periodKey = $this->monitoringResolvePeriodColumnKey($event['date'] ?? null, $context['periodColumns']);
                    if ($periodKey === null) {
                        continue;
                    }

                    $value = isset($event['value']) && is_numeric($event['value']) ? (float) $event['value'] : 0.0;
                    $segment = (string) ($event['segment'] ?? 'individual');
                    $contributor = $this->monitoringContributorFromEvent($event);

                    $rowsByLabel[$label]['periodic'][$periodKey]['qty'] += 1;
                    $rowsByLabel[$label]['periodic'][$periodKey]['rm'] += $value;
                    $rowsByLabel[$label]['weekly'][$periodKey] = $rowsByLabel[$label]['periodic'][$periodKey];
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
                        $periodKey,
                        $segment,
                        $contributor
                    );
                }
            }

            $rows = array_values($rowsByLabel);
            $totals = [
                'label' => 'TOTAL',
                'weekly' => $this->monitoringPeriodicMetricSeed($context['periodColumns']),
                'periodic' => $this->monitoringPeriodicMetricSeed($context['periodColumns']),
                'totalQty' => 0,
                'totalRm' => 0.0,
                'individualQty' => 0,
                'individualRm' => 0.0,
                'specialProjectQty' => 0,
                'specialProjectRm' => 0.0,
                'tenderQty' => 0,
                'tenderRm' => 0.0,
                'details' => $this->monitoringStatusDetailSeed($context['periodColumns']),
            ];

            foreach ($rows as &$row) {
                foreach ($context['periodColumns'] as $column) {
                    $key = $column['key'];
                    $totals['periodic'][$key]['qty'] += $row['periodic'][$key]['qty'];
                    $totals['periodic'][$key]['rm'] += $row['periodic'][$key]['rm'];
                    $totals['weekly'][$key] = $totals['periodic'][$key];
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
                'yearToDateCompanyTotalRm' => $yearToDateTotals['companyTotalRm'],
                'yearToDateTotalRm' => $yearToDateTotals['selectedTotalRm'],
                'achievementPeriodLabel' => 'YTD to '.$context['monthLabel'],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }
}
