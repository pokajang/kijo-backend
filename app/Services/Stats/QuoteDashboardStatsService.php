<?php

namespace App\Services\Stats;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteDashboardStatsService
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

    private function quoteFactsQuery(): QuoteFactsQuery
    {
        return app(QuoteFactsQuery::class);
    }

    public function quoteValueByPerson(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $rows = $this->quoteFactsQuery()
                ->facts($start, $end)
                ->groupBy('staff_code')
                ->map(fn ($staffRows) => [
                    'staffCode' => (string) $staffRows->first()->staff_code,
                    'staffName' => (string) $staffRows->first()->staff_name,
                    'totalValue' => (float) $staffRows->sum('value'),
                ])
                ->sortByDesc('totalValue')
                ->values();

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
            $rows = $this->quoteFactsQuery()
                ->facts($start, $end)
                ->groupBy('service_group')
                ->map(fn ($serviceRows) => [
                    'serviceGroup' => (string) $serviceRows->first()->service_group,
                    'totalValue' => (float) $serviceRows->sum('value'),
                ])
                ->sortByDesc('totalValue')
                ->values();

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
            $rows = $this->quoteFactsQuery()
                ->facts($start, $end)
                ->groupBy('staff_code')
                ->map(fn ($staffRows) => [
                    'staffCode' => (string) $staffRows->first()->staff_code,
                    'staffName' => (string) $staffRows->first()->staff_name,
                    'quoteCount' => (int) $staffRows->count(),
                ])
                ->sortByDesc('quoteCount')
                ->values();

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
            $grouped = [];
            $monthKeys = [];
            foreach ($this->quoteFactsQuery()->facts($start, $end) as $r) {
                $monthKey = $this->monthKey($r->created_at);
                if ($monthKey === null) {
                    continue;
                }
                $grouped[$r->service_group][$monthKey] = ($grouped[$r->service_group][$monthKey] ?? 0) + (float) $r->value;
                $monthKeys[$monthKey] = true;
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
                    'serviceGroup' => $grp,
                    'monthlyValues' => $monthlyValues,
                    'totalValue' => $totalValue,
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
            $rows = $this->quoteFactsQuery()
                ->facts($start, $end)
                ->mapToGroups(fn ($row) => [$this->monthKey($row->created_at) => $row])
                ->filter(fn ($monthRows, $month) => $month !== null && $month !== '')
                ->map(fn ($monthRows, $month) => [
                    'month' => $month,
                    'amount' => (float) $monthRows->sum('value'),
                ])
                ->sortBy('month')
                ->values();

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
            $rows = $this->quoteFactsQuery()
                ->facts($start, $end)
                ->mapToGroups(fn ($row) => [$this->monthKey($row->created_at) => $row])
                ->filter(fn ($monthRows, $month) => $month !== null && $month !== '')
                ->map(fn ($monthRows, $month) => [
                    'month' => $month,
                    'count' => (int) $monthRows->count(),
                ])
                ->sortBy('month')
                ->values();

            return response()->json(['status' => 'success', 'monthlyQuoteCount' => $rows]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
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

    private function monthKey($date): ?string
    {
        $timestamp = strtotime((string) $date);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m', $timestamp);
    }
}
