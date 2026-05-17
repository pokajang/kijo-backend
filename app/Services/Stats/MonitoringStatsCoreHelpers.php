<?php

namespace App\Services\Stats;

use App\Services\Monitoring\ManualPipelineEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait MonitoringStatsCoreHelpers
{
    private function baseQuoteLifecycleQuery(): Builder
    {
        $training = DB::table('quotes_training')->selectRaw("
            id AS quote_id,
            quote_ref_no,
            'Training' AS service_group,
            training_title AS service_title,
            created_by_id AS staff_id,
            created_by_name AS staff_name,
            created_by_code AS staff_code,
            client_name,
            created_at,
            updated_at,
            award_date,
            status AS quote_status,
            grand_total AS value,
            attach_proposal,
            remarks,
            NULL AS inquiry_remarks,
            status_remarks
        ");

        $ih = DB::table('quotes_ih')->selectRaw("
            id AS quote_id,
            quote_ref_no,
            'Industrial Hygiene' AS service_group,
            service_title AS service_title,
            created_by_id AS staff_id,
            created_by_name AS staff_name,
            created_by_code AS staff_code,
            client_name,
            created_at,
            updated_at,
            award_date,
            status AS quote_status,
            grand_total AS value,
            attach_proposal,
            NULL AS remarks,
            inquiry_remarks,
            status_remarks
        ");

        $manpower = DB::table('quotes_manpower')->selectRaw("
            id AS quote_id,
            quote_ref_no,
            'Manpower Supply' AS service_group,
            service_title AS service_title,
            created_by_id AS staff_id,
            created_by_name AS staff_name,
            created_by_code AS staff_code,
            client_name,
            created_at,
            updated_at,
            award_date,
            status AS quote_status,
            grand_total AS value,
            attach_proposal,
            NULL AS remarks,
            inquiry_remarks,
            status_remarks
        ");

        $special = DB::table('quotes_special')->selectRaw("
            id AS quote_id,
            quote_ref_no,
            'Special Service' AS service_group,
            service_title AS service_title,
            created_by_id AS staff_id,
            created_by_name AS staff_name,
            created_by_code AS staff_code,
            client_name,
            created_at,
            updated_at,
            award_date,
            status AS quote_status,
            grand_total AS value,
            attach_proposal,
            general_remarks AS remarks,
            inquiry_remarks,
            status_remarks
        ");

        $equipment = DB::table('quotes_equipment')->selectRaw("
            id AS quote_id,
            quote_ref_no,
            'Equipment Supply' AS service_group,
            'Equipment Supply' AS service_title,
            created_by_id AS staff_id,
            created_by_name AS staff_name,
            created_by_code AS staff_code,
            client_name,
            created_at,
            updated_at,
            award_date,
            status AS quote_status,
            grand_total AS value,
            attach_proposal,
            NULL AS remarks,
            inquiry_remarks,
            status_remarks
        ");

        $base = $training
            ->unionAll($ih)
            ->unionAll($manpower)
            ->unionAll($special)
            ->unionAll($equipment);

        return DB::query()->fromSub($base, 'quote_lifecycle');
    }

    private function monitoringMonthContext(Request $request): array
    {
        [$start, $end] = $this->parseDates($request);
        $anchorDate = Carbon::parse($end ?: $start ?: now()->format('Y-m-d'));
        $monthStart = $anchorDate->copy()->startOfMonth();
        $monthEnd = $anchorDate->copy()->endOfMonth();

        $weeks = [];
        $cursor = $monthStart->copy();
        for ($index = 1; $index <= 5; $index++) {
            $weekStart = $cursor->copy();
            $weekEnd = $index < 5 ? $weekStart->copy()->addDays(6) : $monthEnd->copy();
            if ($weekEnd->gt($monthEnd)) {
                $weekEnd = $monthEnd->copy();
            }

            $weeks[] = [
                'key' => 'W' . $index,
                'label' => 'W' . $index,
                'rangeLabel' => $weekStart->format('j M') . ' - ' . $weekEnd->format('j M'),
                'start' => $weekStart->format('Y-m-d'),
                'end' => $weekEnd->format('Y-m-d'),
            ];

            $cursor = $weekEnd->copy()->addDay();
        }

        return [
            'monthLabel' => strtoupper($monthStart->format('F Y')),
            'monthStart' => $monthStart->format('Y-m-d'),
            'monthEnd' => $monthEnd->format('Y-m-d'),
            'yearStart' => $monthStart->copy()->startOfYear()->format('Y-m-d'),
            'weeks' => $weeks,
        ];
    }

    private function monitoringYearToDateTotals(array $context, array $staffFilter): array
    {
        $companyQuery = $this->baseQuoteLifecycleQuery()
            ->whereIn(DB::raw('UPPER(quote_status)'), ['AWARDED', 'WON'])
            ->whereBetween(DB::raw('DATE(award_date)'), [$context['yearStart'], $context['monthEnd']]);
        $selectedQuery = $this->baseQuoteLifecycleQuery()
            ->whereIn(DB::raw('UPPER(quote_status)'), ['AWARDED', 'WON'])
            ->whereBetween(DB::raw('DATE(award_date)'), [$context['yearStart'], $context['monthEnd']]);

        if (!empty($staffFilter['code'])) {
            $selectedQuery->whereRaw('UPPER(staff_code) = ?', [$staffFilter['code']]);
        }

        return [
            'companyTotalRm' => (float) $companyQuery->sum('value'),
            'selectedTotalRm' => (float) $selectedQuery->sum('value'),
        ];
    }

    private function baseQuoteFactsQuery(): Builder
    {
        // The SQL view can return more than one row per logical quote if bad legacy
        // source rows exist. Normalize first so every downstream KPI aggregates on
        // one quote fact instead of raw joined rows.
        $base = DB::table('all_quotes')
            ->selectRaw("
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
            ")
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
        if (!is_array($roles)) {
            $roles = $roles ? [$roles] : [];
        }

        return array_values(array_filter(array_map(
            static fn($role) => trim((string) $role),
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
        $period = (string) $request->input('trend_period', 'last6');
        $anchorDate = $request->input('end_date')
            ? Carbon::parse((string) $request->input('end_date'))
            : now();
        $anchorMonth = $anchorDate->copy()->endOfMonth();

        if ($period === 'last12') {
            $startMonth = $anchorMonth->copy()->subMonths(11)->startOfMonth();
            $periodLabel = 'Last 12 months';
        } elseif ($period === 'ytd') {
            $startMonth = $anchorMonth->copy()->startOfYear()->startOfMonth();
            $periodLabel = 'Year to date';
        } else {
            $period = 'last6';
            $startMonth = $anchorMonth->copy()->subMonths(5)->startOfMonth();
            $periodLabel = 'Last 6 months';
        }

        $months = [];
        $cursor = $startMonth->copy();
        while ($cursor->lte($anchorMonth)) {
            $months[] = [
                'key' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'start' => $cursor->copy()->startOfMonth()->format('Y-m-d'),
                'end' => $cursor->copy()->endOfMonth()->format('Y-m-d'),
            ];
            $cursor->addMonth();
        }

        return [
            'period' => $period,
            'periodLabel' => $periodLabel,
            'months' => $months,
        ];
    }
}
