<?php

namespace App\Services\Stats;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConversionDashboardStatsService
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

    private function quoteFactsQuery(): QuoteFactsQuery
    {
        return app(QuoteFactsQuery::class);
    }

    public function conversionRateBySource(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $convertedQuoteKeys = $this->realizedSalesProjectQuery()->convertedQuoteKeysByCutoff($end);
            $quoteFactsQuery = $this->quoteFactsQuery();
            $rows = $this->sortConversionRows($quoteFactsQuery
                ->facts($start, $end)
                ->groupBy('inquiry_source')
                ->map(fn ($sourceRows) => $this->conversionRow(
                    [
                        'sourceName' => (string) $sourceRows->first()->inquiry_source,
                    ],
                    $sourceRows,
                    $convertedQuoteKeys,
                    $quoteFactsQuery,
                )));

            return response()->json(['status' => 'success', 'conversionRateBySource' => $rows]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function conversionRateByService(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $convertedQuoteKeys = $this->realizedSalesProjectQuery()->convertedQuoteKeysByCutoff($end);
            $quoteFactsQuery = $this->quoteFactsQuery();
            $rows = $this->sortConversionRows($quoteFactsQuery
                ->facts($start, $end)
                ->groupBy('service_group')
                ->map(fn ($serviceRows) => $this->conversionRow(
                    [
                        'serviceGroup' => (string) $serviceRows->first()->service_group,
                    ],
                    $serviceRows,
                    $convertedQuoteKeys,
                    $quoteFactsQuery,
                )));

            return response()->json(['status' => 'success', 'conversionRateByService' => $rows]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function conversionRateByStaff(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $activeStaffCount = $this->activeStaffCount();
            $convertedQuoteKeys = $this->realizedSalesProjectQuery()->convertedQuoteKeysByCutoff($end);
            $quoteFactsQuery = $this->quoteFactsQuery();
            $rows = $this->sortConversionRows($quoteFactsQuery
                ->facts($start, $end)
                ->groupBy('staff_code')
                ->map(fn ($staffRows) => $this->conversionRow(
                    [
                        'staffCode' => (string) $staffRows->first()->staff_code,
                        'staffName' => (string) $staffRows->first()->staff_name,
                    ],
                    $staffRows,
                    $convertedQuoteKeys,
                    $quoteFactsQuery,
                )));

            return response()->json([
                'status' => 'success',
                'conversionRateByStaff' => $rows,
                'activeStaffCount' => $activeStaffCount,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    private function activeStaffCount(): int
    {
        if (! Schema::hasTable('staff_general') || ! Schema::hasColumn('staff_general', 'status')) {
            return 0;
        }

        return (int) DB::table('staff_general')
            ->where('status', 'Active')
            ->count();
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

    private function conversionRow(
        array $labels,
        $quoteRows,
        array $convertedQuoteKeys,
        QuoteFactsQuery $quoteFactsQuery,
    ): array {
        $totalQuotes = (int) $quoteRows->count();
        $convertedCount = (int) $quoteRows
            ->filter(fn ($quote) => isset($convertedQuoteKeys[$quoteFactsQuery->quoteServiceKey($quote->quote_id, $quote->service_group)]))
            ->count();

        return array_merge($labels, [
            'convertedCount' => $convertedCount,
            'totalQuotes' => $totalQuotes,
            'conversionRate' => $totalQuotes > 0 ? round($convertedCount * 100.0 / $totalQuotes, 1) : 0.0,
        ]);
    }

    private function sortConversionRows($rows)
    {
        return $rows
            ->sort(function (array $a, array $b): int {
                $rateCompare = ((float) $b['conversionRate']) <=> ((float) $a['conversionRate']);
                if ($rateCompare !== 0) {
                    return $rateCompare;
                }

                return ((int) $b['totalQuotes']) <=> ((int) $a['totalQuotes']);
            })
            ->values();
    }
}
