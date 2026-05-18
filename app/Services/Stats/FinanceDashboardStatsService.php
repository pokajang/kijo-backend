<?php

namespace App\Services\Stats;

use App\Services\Monitoring\ManualPipelineEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceDashboardStatsService
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

    public function monthlyIncomeStatement(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $invQ = DB::table('invoices')->where('status', '!=', 'Cancelled');
            if ($start && $end) $invQ->whereBetween(DB::raw('DATE(invoice_date)'), [$start, $end]);
            $totalInvoiced = (float) ($invQ->sum('grand_total') ?? 0);
            if ($this->manualDebtorsTableReady()) {
                $manualInvoicedQ = DB::table('manual_debtors')
                    ->whereRaw("LOWER(TRIM(COALESCE(status, 'open'))) NOT IN ('cancelled', 'canceled', 'void')");
                if ($start && $end) $manualInvoicedQ->whereBetween(DB::raw('DATE(invoice_date)'), [$start, $end]);
                $totalInvoiced += (float) ($manualInvoicedQ->sum('grand_total') ?? 0);
            }

            $paidQ = DB::table('invoices')->where('status', 'Paid')->whereNotNull('paid_date');
            if ($start && $end) $paidQ->whereBetween(DB::raw('DATE(paid_date)'), [$start, $end]);
            $totalReceived = (float) ($paidQ->sum('paid_amount') ?? 0);
            if ($this->manualDebtorsTableReady()) {
                $manualPaidQ = DB::table('manual_debtors')
                    ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'paid'")
                    ->whereNotNull('paid_date');
                if ($start && $end) $manualPaidQ->whereBetween(DB::raw('DATE(paid_date)'), [$start, $end]);
                $totalReceived += (float) ($manualPaidQ->sum('paid_amount') ?? 0);
            }

            $asOfDate = $end ?: now()->format('Y-m-d');
            $openReceivablesQ = DB::table('invoices')
                ->whereNotIn('status', ['Paid', 'Cancelled'])
                ->whereDate('invoice_date', '<=', $asOfDate);
            $outstandingAmount = (float) ($openReceivablesQ->sum('grand_total') ?? 0);

            $openInvoiceCountQ = DB::table('invoices')
                ->whereNotIn('status', ['Paid', 'Cancelled'])
                ->whereDate('invoice_date', '<=', $asOfDate);
            $outstandingCount = (int) ($openInvoiceCountQ->count() ?? 0);
            if ($this->manualDebtorsTableReady()) {
                $manualOpenReceivablesQ = DB::table('manual_debtors')
                    ->whereRaw("LOWER(TRIM(COALESCE(status, 'open'))) NOT IN ('paid', 'cancelled', 'canceled', 'void')")
                    ->whereDate('invoice_date', '<=', $asOfDate);
                $outstandingAmount += (float) ($manualOpenReceivablesQ->sum('grand_total') ?? 0);

                $manualOpenInvoiceCountQ = DB::table('manual_debtors')
                    ->whereRaw("LOWER(TRIM(COALESCE(status, 'open'))) NOT IN ('paid', 'cancelled', 'canceled', 'void')")
                    ->whereDate('invoice_date', '<=', $asOfDate);
                $outstandingCount += (int) ($manualOpenInvoiceCountQ->count() ?? 0);
            }

            return response()->json([
                'status'            => 'success',
                'totalInvoiced'     => $totalInvoiced,
                'totalReceived'     => $totalReceived,
                'outstandingAmount' => $outstandingAmount,
                'outstandingCount'  => $outstandingCount,
                'asOfDate'          => $asOfDate,
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function allDebtors(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $asOfDate = $end ?: now()->format('Y-m-d');
            $query = DB::table('invoices as i')
                ->leftJoin('client_company as cc', 'i.client_id', '=', 'cc.company_id')
                ->leftJoin('projects_main as pm', 'i.project_id', '=', 'pm.id')
                ->leftJoin('staff_general as sg', 'pm.created_by', '=', 'sg.staff_id')
                ->selectRaw("i.id, i.client_id, i.project_id, i.invoice_ref_no, i.invoice_date, i.grand_total, i.status,
                    COALESCE(NULLIF(i.invoice_client_name, ''), cc.company_name) AS client_name,
                    COALESCE(NULLIF(pm.project_name, ''), NULLIF(i.invoice_purpose, '')) AS project_name,
                    COALESCE(NULLIF(i.invoice_pic_name, ''), '-') AS pic_name,
                    COALESCE(NULLIF(i.invoice_pic_phone, ''), '') AS pic_phone,
                    COALESCE(NULLIF(i.invoice_pic_email, ''), '') AS pic_email,
                    COALESCE(NULLIF(sg.name_code, ''), '-') AS internal_pic_code")
                ->whereNotIn('i.status', ['Paid', 'Cancelled'])
                ->whereDate('i.invoice_date', '<=', $asOfDate)
                ->orderBy('i.invoice_date');
            $systemRows = $query->get()->map(function ($row) {
                $row->client_name = (string) ($row->client_name ?? '') !== ''
                    ? $row->client_name
                    : 'Client #' . (string) ($row->client_id ?? '');
                $row->project_name = (string) ($row->project_name ?? '') !== ''
                    ? $row->project_name
                    : 'Project #' . (string) ($row->project_id ?? '');
                $row->source_type = 'invoice';
                $row->sourceType = 'invoice';
                $row->source_id = (int) $row->id;
                $row->sourceId = (int) $row->id;
                return $row;
            });

            $manualRows = collect();
            if ($this->manualDebtorsTableReady()) {
                $manualRows = DB::table('manual_debtors')
                    ->selectRaw("id, invoice_ref_no, invoice_date, grand_total, status,
                        client_name,
                        COALESCE(NULLIF(purpose, ''), '-') AS project_name,
                        COALESCE(NULLIF(pic_name, ''), '-') AS pic_name,
                        COALESCE(NULLIF(pic_phone, ''), '') AS pic_phone,
                        COALESCE(NULLIF(pic_email, ''), '') AS pic_email,
                        COALESCE(NULLIF(created_by_code, ''), '-') AS internal_pic_code")
                    ->whereRaw("LOWER(TRIM(COALESCE(status, 'open'))) NOT IN ('paid', 'cancelled', 'canceled', 'void')")
                    ->whereDate('invoice_date', '<=', $asOfDate)
                    ->get()
                    ->map(function ($row) {
                        $row->source_type = 'manual';
                        $row->sourceType = 'manual';
                        $row->source_id = (int) $row->id;
                        $row->sourceId = (int) $row->id;
                        return $row;
                    });
            }

            return response()->json([
                'status'   => 'success',
                'asOfDate' => $asOfDate,
                'debtors'  => $systemRows
                    ->merge($manualRows)
                    ->sortBy(fn ($row) => (string) ($row->invoice_date ?? ''))
                    ->values(),
            ]);
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

    private function manualDebtorsTableReady(): bool
    {
        return Schema::hasTable('manual_debtors');
    }
}
