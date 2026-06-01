<?php

namespace App\Services\Stats;

use Illuminate\Database\Query\Builder;
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

    public function conversionRateBySource(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $convertedPredicate = $this->realizedSalesProjectQuery()->quoteHasRealizedProjectPredicate();
            $query = $this->baseQuoteFactsQuery()
                ->selectRaw("
                    COALESCE(NULLIF(inquiry_source, ''), 'Unattributed') AS inquiry_source,
                    COUNT(*) AS total_quotes,
                    SUM(CASE WHEN {$convertedPredicate} THEN 1 ELSE 0 END) AS awarded_count,
                    ROUND(
                        SUM(CASE WHEN {$convertedPredicate} THEN 1 ELSE 0 END)
                        * 100.0 / NULLIF(COUNT(*), 0), 1
                    ) AS conversion_rate
                ")
                ->groupByRaw("COALESCE(NULLIF(inquiry_source, ''), 'Unattributed')")
                ->orderByDesc('conversion_rate');
            if ($start && $end) {
                $query->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            }
            $rows = $query->get()->map(fn ($r) => [
                'sourceName' => $r->inquiry_source,
                'convertedCount' => (int) $r->awarded_count,
                'totalQuotes' => (int) $r->total_quotes,
                'conversionRate' => (float) $r->conversion_rate,
            ]);

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
            $convertedPredicate = $this->realizedSalesProjectQuery()->quoteHasRealizedProjectPredicate();
            $query = $this->baseQuoteFactsQuery()
                ->selectRaw("
                    service_group,
                    COUNT(*) AS total_quotes,
                    SUM(CASE WHEN {$convertedPredicate} THEN 1 ELSE 0 END) AS awarded_count,
                    ROUND(
                        SUM(CASE WHEN {$convertedPredicate} THEN 1 ELSE 0 END)
                        * 100.0 / NULLIF(COUNT(*), 0), 1
                    ) AS conversion_rate
                ")
                ->groupBy('service_group')
                ->orderByDesc('conversion_rate');
            if ($start && $end) {
                $query->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            }
            $rows = $query->get()->map(fn ($r) => [
                'serviceGroup' => $r->service_group,
                'convertedCount' => (int) $r->awarded_count,
                'totalQuotes' => (int) $r->total_quotes,
                'conversionRate' => (float) $r->conversion_rate,
            ]);

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
            $convertedPredicate = $this->realizedSalesProjectQuery()->quoteHasRealizedProjectPredicate();
            $query = $this->baseQuoteFactsQuery()
                ->selectRaw("
                    COALESCE(NULLIF(staff_code, ''), 'UNASSIGNED') AS staff_code,
                    COALESCE(NULLIF(staff_name, ''), 'Unassigned') AS staff_name,
                    COUNT(*) AS total_quotes,
                    SUM(CASE WHEN {$convertedPredicate} THEN 1 ELSE 0 END) AS awarded_count,
                    ROUND(
                        SUM(CASE WHEN {$convertedPredicate} THEN 1 ELSE 0 END)
                        * 100.0 / NULLIF(COUNT(*), 0), 1
                    ) AS conversion_rate
                ")
                ->groupByRaw("COALESCE(NULLIF(staff_code, ''), 'UNASSIGNED'), COALESCE(NULLIF(staff_name, ''), 'Unassigned')")
                ->orderByDesc('conversion_rate')
                ->orderByDesc('total_quotes');
            if ($start && $end) {
                $query->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            }
            $rows = $query->get()->map(fn ($r) => [
                'staffCode' => $r->staff_code,
                'staffName' => $r->staff_name,
                'convertedCount' => (int) $r->awarded_count,
                'totalQuotes' => (int) $r->total_quotes,
                'conversionRate' => (float) $r->conversion_rate,
            ]);

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
}
