<?php

namespace App\Services\Stats;

use App\Services\Monitoring\ManualPipelineEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuoteDashboardStatsService
{
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

    public function quoteValueByPerson(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $query = $this->baseQuoteFactsQuery()
                ->selectRaw('staff_code, staff_name, SUM(value) AS total_value')
                ->groupBy('staff_code', 'staff_name')
                ->orderByDesc('total_value');
            if ($start && $end) {
                $query->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            }
            $rows = $query->get()->map(fn($r) => [
                'staffCode'  => $r->staff_code,
                'staffName'  => $r->staff_name,
                'totalValue' => (float) $r->total_value,
            ]);
            return response()->json(['status' => 'success', 'quoteValueByPerson' => $rows]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function quoteValueByService(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $query = $this->baseQuoteFactsQuery()
                ->selectRaw('service_group, SUM(value) AS total_value')
                ->groupBy('service_group')
                ->orderByDesc('total_value');
            if ($start && $end) {
                $query->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            }
            $rows = $query->get()->map(fn($r) => [
                'serviceGroup' => $r->service_group,
                'totalValue'   => (float) $r->total_value,
            ]);
            return response()->json(['status' => 'success', 'quoteValueByService' => $rows]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function quoteCountByPerson(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $query = $this->baseQuoteFactsQuery()
                ->selectRaw('staff_code, staff_name, COUNT(*) AS quote_count')
                ->groupBy('staff_code', 'staff_name')
                ->orderByDesc('quote_count');
            if ($start && $end) {
                $query->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            }
            $rows = $query->get()->map(fn($r) => [
                'staffCode'  => $r->staff_code,
                'staffName'  => $r->staff_name,
                'quoteCount' => (int) $r->quote_count,
            ]);
            return response()->json(['status' => 'success', 'quoteCountByPerson' => $rows]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function monthlyQuoteValueByService(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $query = $this->baseQuoteFactsQuery()
                ->selectRaw("service_group, DATE_FORMAT(created_at, '%Y-%m') AS month_key, SUM(value) AS total_value")
                ->groupByRaw("service_group, month_key")
                ->orderByRaw("month_key, service_group");
            if ($start && $end) {
                $query->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            }

            $grouped = [];
            $monthKeys = [];
            foreach ($query->get() as $r) {
                $grouped[$r->service_group][$r->month_key] = (float) $r->total_value;
                $monthKeys[$r->month_key] = true;
            }

            $months = array_keys($monthKeys);
            sort($months);

            $monthlyStats = [];
            foreach ($grouped as $grp => $serviceMonths) {
                $monthlyValues = [];
                $totalValue = 0;
                foreach ($months as $monthKey) {
                    $v = $serviceMonths[$monthKey] ?? 0;
                    $monthlyValues[] = $v;
                    $totalValue += $v;
                }
                $monthlyStats[] = [
                    'serviceGroup'  => $grp,
                    'monthlyValues' => $monthlyValues,
                    'totalValue'    => $totalValue,
                ];
            }

            return response()->json([
                'status' => 'success',
                'months' => $months,
                'monthlyStats' => $monthlyStats,
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function monthlyQuoteValue(Request $request): JsonResponse
    {
        [$start, $end] = $this->periodDates($request);
        try {
            $query = $this->baseQuoteFactsQuery()
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, SUM(value) AS amount")
                ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
                ->orderByRaw("DATE_FORMAT(created_at, '%Y-%m') ASC");
            if ($start && $end) {
                $query->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            }
            $rows = $query->get()->map(fn($r) => [
                'month'  => $r->month,
                'amount' => (float) $r->amount,
            ]);
            return response()->json(['status' => 'success', 'monthlyQuoteValue' => $rows]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function monthlyQuoteCount(Request $request): JsonResponse
    {
        [$start, $end] = $this->periodDates($request);
        try {
            $query = $this->baseQuoteFactsQuery()
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS count")
                ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
                ->orderByRaw("DATE_FORMAT(created_at, '%Y-%m') ASC");
            if ($start && $end) {
                $query->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            }
            $rows = $query->get()->map(fn($r) => [
                'month' => $r->month,
                'count' => (int) $r->count,
            ]);
            return response()->json(['status' => 'success', 'monthlyQuoteCount' => $rows]);
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

    private function periodDates(Request $request): array
    {
        [$start, $end] = $this->parseDates($request);
        if ($start && $end) return [$start, $end];

        $period = (string) $request->input('period', 'currentYear');

        return match ($period) {
            'previousMonth' => [
                now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'),
                now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d'),
            ],
            'currentMonth'  => [now()->format('Y-m-01'), now()->format('Y-m-d')],
            '3months'       => [now()->subMonths(2)->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
            '6months'       => [now()->subMonths(5)->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
            '5years'        => [now()->subYears(5)->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d')],
            'allTime'       => [null, null],
            default         => [now()->format('Y-01-01'), now()->format('Y-m-d')], // currentYear
        };
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
}
