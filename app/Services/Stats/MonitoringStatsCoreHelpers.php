<?php

namespace App\Services\Stats;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait MonitoringStatsCoreHelpers
{
    private function realizedSalesProjectQuery(): RealizedSalesProjectQuery
    {
        return app(RealizedSalesProjectQuery::class);
    }

    private function baseQuoteLifecycleQuery(): Builder
    {
        $nullText = $this->monitoringQuoteLifecycleNullTextSql();
        $training = DB::table('quotes_training')->selectRaw($this->monitoringQuoteLifecycleSelectSql(
            'Training',
            $this->monitoringQuoteLifecycleTextSql('training_title'),
            $this->monitoringQuoteLifecycleTextSql('remarks'),
            $nullText
        ));

        $ih = DB::table('quotes_ih')->selectRaw($this->monitoringQuoteLifecycleSelectSql(
            'Industrial Hygiene',
            $this->monitoringQuoteLifecycleTextSql('service_title'),
            $nullText,
            $this->monitoringQuoteLifecycleTextSql('inquiry_remarks')
        ));

        $manpower = DB::table('quotes_manpower')->selectRaw($this->monitoringQuoteLifecycleSelectSql(
            'Manpower Supply',
            $this->monitoringQuoteLifecycleTextSql('service_title'),
            $nullText,
            $this->monitoringQuoteLifecycleTextSql('inquiry_remarks')
        ));

        $special = DB::table('quotes_special')->selectRaw($this->monitoringQuoteLifecycleSelectSql(
            'Special Service',
            $this->monitoringQuoteLifecycleTextSql('service_title'),
            $this->monitoringQuoteLifecycleTextSql('general_remarks'),
            $this->monitoringQuoteLifecycleTextSql('inquiry_remarks')
        ));

        $equipment = DB::table('quotes_equipment')->selectRaw($this->monitoringQuoteLifecycleSelectSql(
            'Equipment Supply',
            $this->monitoringQuoteLifecycleLiteralSql('Equipment Supply'),
            $nullText,
            $this->monitoringQuoteLifecycleTextSql('inquiry_remarks')
        ));

        $base = $training
            ->unionAll($ih)
            ->unionAll($manpower)
            ->unionAll($special)
            ->unionAll($equipment);

        return DB::query()->fromSub($base, 'quote_lifecycle');
    }

    private function monitoringQuoteLifecycleSelectSql(
        string $serviceGroup,
        string $serviceTitleSql,
        string $remarksSql,
        string $inquiryRemarksSql
    ): string {
        return sprintf(
            '
            id AS quote_id,
            %s AS quote_ref_no,
            %s AS service_group,
            %s AS service_title,
            created_by_id AS staff_id,
            %s AS staff_name,
            %s AS staff_code,
            %s AS client_name,
            created_at,
            updated_at,
            award_date,
            %s AS quote_status,
            grand_total AS value,
            %s AS attach_proposal,
            %s AS remarks,
            %s AS inquiry_remarks,
            %s AS status_remarks
        ',
            $this->monitoringQuoteLifecycleTextSql('quote_ref_no'),
            $this->monitoringQuoteLifecycleLiteralSql($serviceGroup),
            $serviceTitleSql,
            $this->monitoringQuoteLifecycleTextSql('created_by_name'),
            $this->monitoringQuoteLifecycleTextSql('created_by_code'),
            $this->monitoringQuoteLifecycleTextSql('client_name'),
            $this->monitoringQuoteLifecycleTextSql('status'),
            $this->monitoringQuoteLifecycleTextSql('attach_proposal'),
            $remarksSql,
            $inquiryRemarksSql,
            $this->monitoringQuoteLifecycleTextSql('status_remarks')
        );
    }

    private function monitoringQuoteLifecycleTextSql(string $expression): string
    {
        if (! $this->monitoringQuoteLifecycleUsesMySqlCollation()) {
            return $expression;
        }

        return "CONVERT({$expression} USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    }

    private function monitoringQuoteLifecycleLiteralSql(string $value): string
    {
        $escaped = str_replace("'", "''", $value);

        if (! $this->monitoringQuoteLifecycleUsesMySqlCollation()) {
            return "'{$escaped}'";
        }

        return "_utf8mb4'{$escaped}' COLLATE utf8mb4_unicode_ci";
    }

    private function monitoringQuoteLifecycleNullTextSql(): string
    {
        if (! $this->monitoringQuoteLifecycleUsesMySqlCollation()) {
            return 'NULL';
        }

        return 'CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci';
    }

    private function monitoringQuoteLifecycleUsesMySqlCollation(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function monitoringMonthContext(Request $request): array
    {
        [$start, $end] = $this->parseDates($request);
        $period = trim((string) $request->input('period', ''));

        if ($period === 'allTime' && ! $start && ! $end) {
            [$start, $end] = $this->monitoringAvailableDateRange();
        }

        if (! $start && ! $end) {
            $end = now()->format('Y-m-d');
            $start = Carbon::parse($end)->startOfYear()->format('Y-m-d');
        } elseif (! $start) {
            $start = Carbon::parse($end)->startOfMonth()->format('Y-m-d');
        } elseif (! $end) {
            $end = Carbon::parse($start)->endOfMonth()->format('Y-m-d');
        }

        $rangeStart = Carbon::parse($start)->startOfDay();
        $rangeEnd = Carbon::parse($end)->startOfDay();
        if ($rangeStart->gt($rangeEnd)) {
            [$rangeStart, $rangeEnd] = [$rangeEnd, $rangeStart];
        }

        $anchorDate = $rangeEnd->copy();
        $monthStart = $anchorDate->copy()->startOfMonth();
        $monthEnd = $anchorDate->copy()->endOfMonth();
        $periodColumns = $this->monitoringPeriodColumns($rangeStart, $rangeEnd);
        $weeks = array_values(array_filter(
            $periodColumns,
            static fn (array $column) => ($column['type'] ?? null) === 'week'
        ));

        $today = Carbon::today();
        $yearToDateEnd = $monthStart->isSameMonth($today)
            ? $today->min($monthEnd)->format('Y-m-d')
            : $monthEnd->format('Y-m-d');

        return [
            'monthLabel' => strtoupper($monthStart->format('F Y')),
            'monthStart' => $rangeStart->format('Y-m-d'),
            'monthEnd' => $rangeEnd->format('Y-m-d'),
            'anchorMonth' => $monthStart->format('Y-m'),
            'anchorMonthStart' => $monthStart->format('Y-m-d'),
            'anchorMonthEnd' => $monthEnd->format('Y-m-d'),
            'rangeStart' => $rangeStart->format('Y-m-d'),
            'rangeEnd' => $rangeEnd->format('Y-m-d'),
            'rangeLabel' => $this->monitoringRangeLabel($rangeStart, $rangeEnd, $period),
            'yearToDateEnd' => $yearToDateEnd,
            'yearStart' => $monthStart->copy()->startOfYear()->format('Y-m-d'),
            'weeks' => $weeks,
            'periodColumns' => $periodColumns,
        ];
    }

    private function monitoringRangeLabel(Carbon $start, Carbon $end, string $period): string
    {
        return match ($period) {
            'previousMonth' => 'Previous Month',
            'currentMonth' => 'Current Month',
            'currentYear' => 'Current Year',
            '3months' => 'Last 3 Months',
            '6months' => 'Last 6 Months',
            'allTime' => 'All Time',
            default => $start->isSameDay($end)
                ? $start->format('j M Y')
                : $start->format('j M Y').' - '.$end->format('j M Y'),
        };
    }

    private function monitoringPeriodColumns(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $columns = [];
        $firstMonth = $rangeStart->copy()->startOfMonth();
        $anchorMonth = $rangeEnd->copy()->startOfMonth();
        $monthCount = (int) $firstMonth->diffInMonths($anchorMonth) + 1;
        $cursor = $firstMonth->copy();

        while ($cursor->lte($anchorMonth)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth();
            $columnStart = $monthStart->copy()->max($rangeStart);
            $columnEnd = $monthEnd->copy()->min($rangeEnd);

            if ($monthCount <= 2 || $cursor->isSameMonth($anchorMonth)) {
                $columns = array_merge(
                    $columns,
                    $this->monitoringWeekColumnsForMonth(
                        $monthStart,
                        $monthEnd,
                        $rangeStart,
                        $rangeEnd,
                        $monthCount === 1
                    )
                );
            } elseif ($columnStart->lte($columnEnd)) {
                $columns[] = [
                    'key' => 'month-'.$cursor->format('Y-m'),
                    'type' => 'month',
                    'label' => $cursor->format('M Y'),
                    'groupLabel' => 'Monthly',
                    'rangeLabel' => $this->monitoringColumnRangeLabel($columnStart, $columnEnd),
                    'start' => $columnStart->format('Y-m-d'),
                    'end' => $columnEnd->format('Y-m-d'),
                    'monthKey' => $cursor->format('Y-m'),
                ];
            }

            $cursor->addMonth();
        }

        return $columns;
    }

    private function monitoringWeekColumnsForMonth(
        Carbon $monthStart,
        Carbon $monthEnd,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        bool $singleMonth
    ): array {
        $columns = [];
        $cursor = $monthStart->copy();

        for ($index = 1; $index <= 5; $index++) {
            $weekStart = $cursor->copy();
            $weekEnd = $index < 5 ? $weekStart->copy()->addDays(6) : $monthEnd->copy();
            if ($weekEnd->gt($monthEnd)) {
                $weekEnd = $monthEnd->copy();
            }

            $columnStart = $weekStart->copy()->max($rangeStart);
            $columnEnd = $weekEnd->copy()->min($rangeEnd);
            if ($columnStart->lte($columnEnd)) {
                $columns[] = [
                    'key' => $singleMonth
                        ? 'W'.$index
                        : $monthStart->format('Y-m').'-W'.$index,
                    'type' => 'week',
                    'label' => 'W'.$index,
                    'groupLabel' => $monthStart->format('M Y'),
                    'rangeLabel' => $this->monitoringColumnRangeLabel($columnStart, $columnEnd),
                    'start' => $columnStart->format('Y-m-d'),
                    'end' => $columnEnd->format('Y-m-d'),
                    'monthKey' => $monthStart->format('Y-m'),
                ];
            }

            $cursor = $weekEnd->copy()->addDay();
        }

        return $columns;
    }

    private function monitoringColumnRangeLabel(Carbon $start, Carbon $end): string
    {
        return $start->format('j M').' - '.$end->format('j M');
    }

    private function monitoringAvailableDateRange(): array
    {
        $dates = [];
        $this->monitoringAppendDateBoundaries($dates, 'all_quotes', 'created_at');
        $this->monitoringAppendDateBoundaries($dates, 'projects_main', 'award_date');
        $this->monitoringAppendDateBoundaries($dates, 'monitoring_manual_pipeline_entries', 'entry_date');
        $this->monitoringAppendDateBoundaries($dates, 'google_call_records', 'called_at');
        $this->monitoringAppendDateBoundaries($dates, 'google_call_records', 'created_at');
        $this->monitoringAppendDateBoundaries($dates, 'quote_price_exception_requests', 'created_at');
        $this->monitoringAppendDateBoundaries($dates, 'legal_compliance_assessments', 'assessment_date');
        $this->monitoringAppendDateBoundaries($dates, 'legal_compliance_assessments', 'submitted_at');

        if (empty($dates)) {
            $today = now()->format('Y-m-d');

            return [$today, $today];
        }

        sort($dates);

        return [reset($dates), end($dates)];
    }

    private function monitoringAppendDateBoundaries(array &$dates, string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $min = DB::table($table)->whereNotNull($column)->min($column);
        $max = DB::table($table)->whereNotNull($column)->max($column);

        foreach ([$min, $max] as $value) {
            if (! $value) {
                continue;
            }

            try {
                $dates[] = Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function monitoringYearToDateTotals(array $context, array $staffFilter): array
    {
        $yearEnd = $context['yearToDateEnd'] ?? $context['monthEnd'];
        $companyQuery = $this->monitoringRealizedSalesProjectQuery(
            $context['yearStart'],
            $yearEnd,
            ['code' => null]
        );
        $selectedQuery = $this->monitoringRealizedSalesProjectQuery(
            $context['yearStart'],
            $yearEnd,
            $staffFilter
        );

        return [
            'companyTotalRm' => (float) $companyQuery->sum('value')
                + $this->monitoringManualClosedRevenueTotal($context['yearStart'], $yearEnd, ['code' => null]),
            'selectedTotalRm' => (float) $selectedQuery->sum('value')
                + $this->monitoringManualClosedRevenueTotal($context['yearStart'], $yearEnd, $staffFilter),
        ];
    }

    private function monitoringRealizedSalesProjectQuery(
        string $start,
        string $end,
        array $staffFilter
    ): Builder {
        $realizedQuery = $this->realizedSalesProjectQuery();
        $query = $realizedQuery->projectFacts()
            ->whereRaw($realizedQuery->realizedStatusPredicate())
            ->whereNotNull('award_date')
            ->whereBetween(DB::raw('DATE(award_date)'), [$start, $end]);

        if (! empty($staffFilter['code'])) {
            $query->whereRaw('UPPER(staff_code) = ?', [$staffFilter['code']]);
        }

        return $query;
    }

    private function monitoringManualClosedRevenueQuery(
        string $start,
        string $end,
        array $staffFilter
    ): ?Builder {
        if (! $this->monitoringManualPipelineEntriesReady()) {
            return null;
        }

        $query = DB::table('monitoring_manual_pipeline_entries')
            ->where('entry_type', 'closed')
            ->whereNotNull('service_category')
            ->whereIn('service_category', array_keys(self::MONITORING_MANUAL_SERVICE_CATEGORIES))
            ->whereNotNull('estimated_rm')
            ->where('estimated_rm', '>', 0)
            ->whereBetween('entry_date', [$start, $end]);

        if (! empty($staffFilter['code'])) {
            $query->whereRaw('UPPER(owner_staff_code) = ?', [$staffFilter['code']]);
        }

        return $query;
    }

    private function monitoringManualClosedRevenueTotal(
        string $start,
        string $end,
        array $staffFilter
    ): float {
        $query = $this->monitoringManualClosedRevenueQuery($start, $end, $staffFilter);

        return $query === null ? 0.0 : (float) $query->sum('estimated_rm');
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

    private function parseDates(Request $request): array
    {
        $start = $this->normalizeStatsDate($request->input('start_date'));
        $end = $this->normalizeStatsDate($request->input('end_date'));
        if ($start && $end && $start > $end) {
            return [$end, $start];
        }

        return [$start ?: null, $end ?: null];
    }

    private function monitoringSessionRoles(Request $request): array
    {
        $roles = $request->session()->get('roles', []);
        if (! is_array($roles)) {
            $roles = $roles ? [$roles] : [];
        }

        return array_values(array_filter(array_map(
            static fn ($role) => trim((string) $role),
            $roles
        )));
    }

    private function monitoringCleanText($value): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) ($value ?? '')) ?: '');
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

    private function monitoringFirstText(...$values): string
    {
        foreach ($values as $value) {
            $text = $this->monitoringCleanText($value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function monitoringTrendContext(Request $request): array
    {
        $context = $this->monitoringMonthContext($request);
        $start = Carbon::parse($context['rangeStart'])->startOfMonth();
        $end = Carbon::parse($context['rangeEnd'])->endOfMonth();

        $months = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $monthStart = $cursor->copy()->startOfMonth()->max(Carbon::parse($context['rangeStart']));
            $monthEnd = $cursor->copy()->endOfMonth()->min(Carbon::parse($context['rangeEnd']));
            $months[] = [
                'key' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'start' => $monthStart->format('Y-m-d'),
                'end' => $monthEnd->format('Y-m-d'),
            ];
            $cursor->addMonth();
        }

        return [
            'period' => (string) $request->input('period', ''),
            'periodLabel' => $context['rangeLabel'],
            'rangeStart' => $context['rangeStart'],
            'rangeEnd' => $context['rangeEnd'],
            'months' => $months,
        ];
    }
}
