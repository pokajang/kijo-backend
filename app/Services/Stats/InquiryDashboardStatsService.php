<?php

namespace App\Services\Stats;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InquiryDashboardStatsService
{
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

    private function quoteFactsQuery(): QuoteFactsQuery
    {
        return app(QuoteFactsQuery::class);
    }

    public function inquiryStats(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $rows = $this->quoteFactsQuery()
                ->facts($start, $end)
                ->groupBy('inquiry_source')
                ->map(fn ($sourceRows) => [
                    'source' => (string) $sourceRows->first()->inquiry_source,
                    'count' => (int) $sourceRows->count(),
                ])
                ->sortByDesc('count')
                ->values();

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
            $rows = $this->quoteFactsQuery()
                ->facts($start, $end)
                ->groupBy('inquiry_source')
                ->map(fn ($sourceRows) => [
                    'source' => (string) $sourceRows->first()->inquiry_source,
                    'totalValue' => (float) $sourceRows->sum('value'),
                ])
                ->sortByDesc('totalValue')
                ->values();

            return response()->json(['status' => 'success', 'inquiryStatsByValues' => $rows]);
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
