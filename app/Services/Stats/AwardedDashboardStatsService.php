<?php

namespace App\Services\Stats;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AwardedDashboardStatsService
{
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

    private function realizedSalesProjectQuery(): RealizedSalesProjectQuery
    {
        return app(RealizedSalesProjectQuery::class);
    }

    public function awardedValueByPerson(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $realizedQuery = $this->realizedSalesProjectQuery();
            $systemRows = $realizedQuery->projectFacts()
                ->selectRaw('staff_code, staff_name, SUM(value) AS total_awarded')
                ->whereRaw($realizedQuery->realizedStatusPredicate())
                ->whereNotNull('award_date')
                ->groupBy('staff_code', 'staff_name')
                ->orderByDesc('total_awarded');
            if ($start && $end) {
                $systemRows->whereBetween(DB::raw('DATE(award_date)'), [$start, $end]);
            }
            $rows = [];
            foreach ($systemRows->get() as $r) {
                $key = strtoupper((string) ($r->staff_code ?: 'UNASSIGNED'));
                $rows[$key] = [
                    'staffCode' => $r->staff_code ?: 'UNASSIGNED',
                    'staffName' => $r->staff_name ?: 'Unassigned',
                    'systemAwarded' => (float) $r->total_awarded,
                    'manualAwarded' => 0.0,
                ];
            }
            foreach ($this->manualClosedSalesByPerson($start, $end) as $r) {
                $key = strtoupper((string) ($r->staff_code ?: 'UNASSIGNED'));
                if (! isset($rows[$key])) {
                    $rows[$key] = [
                        'staffCode' => $r->staff_code ?: 'UNASSIGNED',
                        'staffName' => $r->staff_name ?: 'Unassigned',
                        'systemAwarded' => 0.0,
                        'manualAwarded' => 0.0,
                    ];
                }
                $rows[$key]['manualAwarded'] += (float) $r->manual_awarded;
            }
            $rows = collect(array_values($rows))
                ->map(fn ($row) => array_merge($row, [
                    'totalAwarded' => (float) $row['systemAwarded'] + (float) $row['manualAwarded'],
                ]))
                ->sortByDesc('totalAwarded')
                ->values();

            return response()->json(['status' => 'success', 'awardValueByPerson' => $rows]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function awardedValueBySource(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $realizedQuery = $this->realizedSalesProjectQuery();
            $systemRows = $realizedQuery->projectFacts()
                ->selectRaw("COALESCE(NULLIF(inquiry_source, ''), 'Unattributed') AS inquiry_source, SUM(value) AS total_awarded")
                ->whereRaw($realizedQuery->realizedStatusPredicate())
                ->whereNotNull('award_date')
                ->groupByRaw("COALESCE(NULLIF(inquiry_source, ''), 'Unattributed')")
                ->orderByDesc('total_awarded');
            if ($start && $end) {
                $systemRows->whereBetween(DB::raw('DATE(award_date)'), [$start, $end]);
            }
            $rows = [];
            foreach ($systemRows->get() as $r) {
                $key = (string) ($r->inquiry_source ?: 'Unattributed');
                $rows[$key] = [
                    'sourceName' => $key,
                    'systemAwarded' => (float) $r->total_awarded,
                    'manualAwarded' => 0.0,
                ];
            }
            foreach ($this->manualClosedSalesBySource($start, $end) as $r) {
                $key = (string) ($r->source ?: 'Unattributed');
                if (! isset($rows[$key])) {
                    $rows[$key] = [
                        'sourceName' => $key,
                        'systemAwarded' => 0.0,
                        'manualAwarded' => 0.0,
                    ];
                }
                $rows[$key]['manualAwarded'] += (float) $r->manual_awarded;
            }
            $rows = collect(array_values($rows))
                ->map(fn ($row) => array_merge($row, [
                    'awardedValue' => (float) $row['systemAwarded'] + (float) $row['manualAwarded'],
                ]))
                ->sortByDesc('awardedValue')
                ->values();

            return response()->json(['status' => 'success', 'awardValueBySource' => $rows]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function awardedValueByService(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $realizedQuery = $this->realizedSalesProjectQuery();
            $systemRows = $realizedQuery->projectFacts()
                ->selectRaw('service_group, SUM(value) AS total_awarded')
                ->whereRaw($realizedQuery->realizedStatusPredicate())
                ->whereNotNull('award_date')
                ->groupBy('service_group')
                ->orderByDesc('total_awarded');
            if ($start && $end) {
                $systemRows->whereBetween(DB::raw('DATE(award_date)'), [$start, $end]);
            }
            $rows = [];
            foreach ($systemRows->get() as $r) {
                $key = (string) ($r->service_group ?: 'Unclassified');
                $rows[$key] = [
                    'serviceGroup' => $key,
                    'systemAwarded' => (float) $r->total_awarded,
                    'manualAwarded' => 0.0,
                ];
            }
            foreach ($this->manualClosedSalesByService($start, $end) as $r) {
                $key = (string) ($r->service_group ?: 'Unclassified');
                if (! isset($rows[$key])) {
                    $rows[$key] = [
                        'serviceGroup' => $key,
                        'systemAwarded' => 0.0,
                        'manualAwarded' => 0.0,
                    ];
                }
                $rows[$key]['manualAwarded'] += (float) $r->manual_awarded;
            }
            $rows = collect(array_values($rows))
                ->map(fn ($row) => array_merge($row, [
                    'awardedValue' => (float) $row['systemAwarded'] + (float) $row['manualAwarded'],
                ]))
                ->sortByDesc('awardedValue')
                ->values();

            return response()->json(['status' => 'success', 'awardValueByService' => $rows]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function monthlySales(Request $request): JsonResponse
    {
        [$start, $end] = $this->periodDates($request);
        try {
            $realizedQuery = $this->realizedSalesProjectQuery();
            $realizedPredicate = $realizedQuery->realizedStatusPredicate();
            $systemRows = $realizedQuery->projectFacts()
                ->selectRaw("
                    DATE_FORMAT(award_date, '%Y-%m') AS month,
                    SUM(CASE WHEN {$realizedPredicate} THEN value ELSE 0 END) AS amount,
                    SUM(CASE WHEN {$realizedPredicate} THEN 1 ELSE 0 END) AS realized_jobs,
                    SUM(CASE WHEN LOWER(project_status) = 'terminated' THEN value ELSE 0 END) AS terminated_amount,
                    SUM(CASE WHEN LOWER(project_status) = 'terminated' THEN 1 ELSE 0 END) AS terminated_jobs
                ")
                ->whereNotNull('award_date')
                ->groupByRaw("DATE_FORMAT(award_date, '%Y-%m')")
                ->orderByRaw("DATE_FORMAT(award_date, '%Y-%m') ASC");
            if ($start && $end) {
                $systemRows->whereBetween(DB::raw('DATE(award_date)'), [$start, $end]);
            }
            $rows = [];
            foreach ($systemRows->get() as $r) {
                $month = (string) $r->month;
                $rows[$month] = [
                    'month' => $month,
                    'systemAmount' => (float) $r->amount,
                    'manualAmount' => 0.0,
                    'systemCount' => (int) $r->realized_jobs,
                    'manualCount' => 0,
                    'terminatedAmount' => (float) $r->terminated_amount,
                    'terminatedCount' => (int) $r->terminated_jobs,
                ];
            }
            foreach ($this->manualClosedSalesByMonth($start, $end) as $r) {
                $month = (string) $r->month;
                if (! isset($rows[$month])) {
                    $rows[$month] = [
                        'month' => $month,
                        'systemAmount' => 0.0,
                        'manualAmount' => 0.0,
                        'systemCount' => 0,
                        'manualCount' => 0,
                        'terminatedAmount' => 0.0,
                        'terminatedCount' => 0,
                    ];
                }
                $rows[$month]['manualAmount'] += (float) $r->manual_amount;
                $rows[$month]['manualCount'] += (int) $r->manual_count;
            }
            $rows = collect(array_values($rows))
                ->map(fn ($row) => array_merge($row, [
                    'amount' => (float) $row['systemAmount'] + (float) $row['manualAmount'],
                    'count' => (int) $row['systemCount'] + (int) $row['manualCount'],
                    'terminatedAmount' => (float) $row['terminatedAmount'],
                    'terminatedCount' => (int) $row['terminatedCount'],
                ]))
                ->sortBy('month')
                ->values();

            return response()->json(['status' => 'success', 'monthlySales' => $rows]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    private function baseQuoteFactsQuery(): Builder
    {
        // The SQL view can return more than one row per logical quote if bad legacy
        // source rows exist. Normalize first so every downstream KPI aggregates on
        // one quote fact instead of raw joined rows.
        $base = DB::table('all_quotes')
            ->selectRaw('
                service_group,
                quote_id,
                MAX(created_at) AS created_at,
                MAX(award_date) AS award_date,
                MAX(staff_id) AS staff_id,
                MAX(staff_name) AS staff_name,
                MAX(staff_code) AS staff_code,
                MAX(client_id) AS client_id,
                MAX(client_name) AS client_name,
                MAX(quote_status) AS quote_status,
                MAX(value) AS value,
                MAX(inquiry_source) AS inquiry_source
            ')
            ->groupBy('service_group', 'quote_id');

        return DB::query()->fromSub($base, 'quote_facts');
    }

    private function manualClosedSalesByPerson(?string $start, ?string $end)
    {
        $query = $this->manualClosedSalesBaseQuery($start, $end);
        if ($query === null) {
            return collect();
        }

        return $query
            ->selectRaw("
                COALESCE(NULLIF(owner_staff_code, ''), 'UNASSIGNED') AS staff_code,
                COALESCE(NULLIF(owner_staff_name, ''), 'Unassigned') AS staff_name,
                SUM(COALESCE(estimated_rm, 0)) AS manual_awarded
            ")
            ->groupByRaw("COALESCE(NULLIF(owner_staff_code, ''), 'UNASSIGNED'), COALESCE(NULLIF(owner_staff_name, ''), 'Unassigned')")
            ->get();
    }

    private function parseDates(Request $request): array
    {
        $start = $this->normalizeStatsDate($request->input('start_date'));
        $end = $this->normalizeStatsDate($request->input('end_date'));
        if ($start && $end && $start > $end) {
            return [$end, $start];
        }

        return [$start ?: null, $end ?: null];
    }

    private function manualClosedSalesBySource(?string $start, ?string $end)
    {
        $query = $this->manualClosedSalesBaseQuery($start, $end);
        if ($query === null) {
            return collect();
        }

        return $query
            ->selectRaw("COALESCE(NULLIF(source, ''), 'Unattributed') AS source, SUM(COALESCE(estimated_rm, 0)) AS manual_awarded")
            ->groupByRaw("COALESCE(NULLIF(source, ''), 'Unattributed')")
            ->get();
    }

    private function manualClosedSalesByService(?string $start, ?string $end)
    {
        $query = $this->manualClosedSalesBaseQuery($start, $end);
        if ($query === null) {
            return collect();
        }

        return $query
            ->selectRaw('service_category, SUM(COALESCE(estimated_rm, 0)) AS manual_awarded')
            ->groupBy('service_category')
            ->get()
            ->map(fn ($row) => (object) [
                'service_group' => $this->monitoringManualServiceCategoryToStatusLabel($row->service_category) ?: 'Unclassified',
                'manual_awarded' => (float) $row->manual_awarded,
            ]);
    }

    private function manualClosedSalesByMonth(?string $start, ?string $end)
    {
        $query = $this->manualClosedSalesBaseQuery($start, $end);
        if ($query === null) {
            return collect();
        }

        return $query
            ->selectRaw("DATE_FORMAT(entry_date, '%Y-%m') AS month, SUM(COALESCE(estimated_rm, 0)) AS manual_amount, COUNT(*) AS manual_count")
            ->groupByRaw("DATE_FORMAT(entry_date, '%Y-%m')")
            ->orderByRaw("DATE_FORMAT(entry_date, '%Y-%m') ASC")
            ->get();
    }

    private function periodDates(Request $request): array
    {
        [$start, $end] = $this->parseDates($request);
        if ($start && $end) {
            return [$start, $end];
        }

        $period = (string) $request->input('period', 'currentYear');

        return match ($period) {
            'previousMonth' => [
                now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'),
                now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d'),
            ],
            'currentMonth' => [now()->format('Y-m-01'), now()->format('Y-m-d')],
            '3months' => [now()->subMonths(2)->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
            '6months' => [now()->subMonths(5)->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
            '5years' => [now()->subYears(5)->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
            'allTime' => [null, null],
            default => [now()->format('Y-01-01'), now()->format('Y-m-d')], // currentYear
        };
    }

    private function manualClosedSalesBaseQuery(?string $start, ?string $end): ?Builder
    {
        if (! $this->monitoringManualPipelineEntriesReady()) {
            return null;
        }

        $query = DB::table('monitoring_manual_pipeline_entries')
            ->where('entry_type', 'closed')
            ->whereNotNull('service_category')
            ->whereIn('service_category', array_keys(self::MONITORING_MANUAL_SERVICE_CATEGORIES))
            ->whereNotNull('estimated_rm')
            ->where('estimated_rm', '>', 0);

        if ($start && $end) {
            $query->whereBetween('entry_date', [$start, $end]);
        }

        return $query;
    }

    private function normalizeStatsDate($value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function monitoringManualServiceCategoryToStatusLabel($serviceCategory): ?string
    {
        $serviceCategory = $this->normalizeMonitoringManualServiceCategory($serviceCategory);

        return $serviceCategory !== null
            ? self::MONITORING_MANUAL_SERVICE_CATEGORIES[$serviceCategory]
            : null;
    }

    private function monitoringManualPipelineEntriesReady(): bool
    {
        if (! Schema::hasTable('monitoring_manual_pipeline_entries')) {
            return false;
        }

        $requiredColumns = [
            'entry_type',
            'prospect_name',
            'entry_date',
            'source',
            'segment_type',
            'service_category',
            'estimated_rm',
            'notes',
            'photo_path',
            'photo_original_name',
            'photo_mime_type',
            'owner_staff_id',
            'owner_staff_code',
            'owner_staff_name',
            'created_by',
            'created_by_code',
        ];

        foreach ($requiredColumns as $column) {
            if (! Schema::hasColumn('monitoring_manual_pipeline_entries', $column)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeMonitoringManualServiceCategory($serviceCategory): ?string
    {
        $serviceCategory = trim((string) ($serviceCategory ?? ''));

        return array_key_exists($serviceCategory, self::MONITORING_MANUAL_SERVICE_CATEGORIES)
            ? $serviceCategory
            : null;
    }
}
