<?php

namespace App\Services\Stats;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InquiryDashboardStatsService
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

    public function inquiryStats(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $query = $this->baseQuoteFactsQuery()
                ->selectRaw("COALESCE(NULLIF(inquiry_source, ''), 'Unattributed') AS source, COUNT(*) AS count")
                ->groupByRaw("COALESCE(NULLIF(inquiry_source, ''), 'Unattributed')")
                ->orderByDesc('count');
            if ($start && $end) {
                $query->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            }
            $rows = $query->get()->map(fn ($r) => [
                'source' => $r->source,
                'count' => (int) $r->count,
            ]);

            return response()->json(['status' => 'success', 'inquiryStats' => $rows]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function inquiryStatsByValues(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $query = $this->baseQuoteFactsQuery()
                ->selectRaw("COALESCE(NULLIF(inquiry_source, ''), 'Unattributed') AS source, SUM(value) AS total_value")
                ->groupByRaw("COALESCE(NULLIF(inquiry_source, ''), 'Unattributed')")
                ->orderByDesc('total_value');
            if ($start && $end) {
                $query->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            }
            $rows = $query->get()->map(fn ($r) => [
                'source' => $r->source,
                'totalValue' => (float) $r->total_value,
            ]);

            return response()->json(['status' => 'success', 'inquiryStatsByValues' => $rows]);
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
