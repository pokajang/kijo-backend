<?php

namespace App\Services\Stats;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceDashboardStatsService
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

    public function monthlyIncomeStatement(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);
        try {
            $invQ = DB::table('invoices')->where('status', '!=', 'Cancelled');
            if ($start && $end) {
                $invQ->whereBetween(DB::raw('DATE(invoice_date)'), [$start, $end]);
            }
            $totalInvoiced = (float) ($invQ->sum('grand_total') ?? 0);
            if ($this->manualDebtorsTableReady()) {
                $manualInvoicedQ = DB::table('manual_debtors')
                    ->whereRaw("LOWER(TRIM(COALESCE(status, 'open'))) NOT IN ('cancelled', 'canceled', 'void')");
                if ($start && $end) {
                    $manualInvoicedQ->whereBetween(DB::raw('DATE(invoice_date)'), [$start, $end]);
                }
                $totalInvoiced += (float) ($manualInvoicedQ->sum('grand_total') ?? 0);
            }

            $paidQ = DB::table('invoices')->where('status', 'Paid')->whereNotNull('paid_date');
            if ($start && $end) {
                $paidQ->whereBetween(DB::raw('DATE(paid_date)'), [$start, $end]);
            }
            $totalReceived = (float) ($paidQ->sum('paid_amount') ?? 0);
            if ($this->manualDebtorsTableReady()) {
                $manualPaidQ = DB::table('manual_debtors')
                    ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'paid'")
                    ->whereNotNull('paid_date');
                if ($start && $end) {
                    $manualPaidQ->whereBetween(DB::raw('DATE(paid_date)'), [$start, $end]);
                }
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

            $uninvoicedAwarded = $this->uninvoicedAwardedProjectTotals($asOfDate);

            return response()->json([
                'status' => 'success',
                'totalInvoiced' => $totalInvoiced,
                'totalReceived' => $totalReceived,
                'outstandingAmount' => $outstandingAmount,
                'outstandingCount' => $outstandingCount,
                'uninvoicedAwardedAmount' => $uninvoicedAwarded['amount'],
                'uninvoicedAwardedCount' => $uninvoicedAwarded['count'],
                'asOfDate' => $asOfDate,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function monthlyInvoicedReceivedTrend(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);

        try {
            $rows = [];

            $systemInvoicedRows = DB::table('invoices')
                ->selectRaw("DATE_FORMAT(invoice_date, '%Y-%m') AS month, SUM(COALESCE(grand_total, 0)) AS amount, COUNT(*) AS count")
                ->where('status', '!=', 'Cancelled')
                ->whereNotNull('invoice_date')
                ->when($start && $end, fn ($query) => $query->whereBetween(DB::raw('DATE(invoice_date)'), [$start, $end]))
                ->groupByRaw("DATE_FORMAT(invoice_date, '%Y-%m')")
                ->get();

            foreach ($systemInvoicedRows as $row) {
                $month = (string) $row->month;
                $rows[$month] = $this->monthlyTrendRow($month, $rows[$month] ?? null);
                $rows[$month]['systemInvoiced'] += (float) $row->amount;
                $rows[$month]['systemInvoiceCount'] += (int) $row->count;
            }

            $systemReceivedRows = DB::table('invoices')
                ->selectRaw("DATE_FORMAT(paid_date, '%Y-%m') AS month, SUM(COALESCE(paid_amount, 0)) AS amount, COUNT(*) AS count")
                ->where('status', 'Paid')
                ->whereNotNull('paid_date')
                ->when($start && $end, fn ($query) => $query->whereBetween(DB::raw('DATE(paid_date)'), [$start, $end]))
                ->groupByRaw("DATE_FORMAT(paid_date, '%Y-%m')")
                ->get();

            foreach ($systemReceivedRows as $row) {
                $month = (string) $row->month;
                $rows[$month] = $this->monthlyTrendRow($month, $rows[$month] ?? null);
                $rows[$month]['systemReceived'] += (float) $row->amount;
                $rows[$month]['systemReceivedCount'] += (int) $row->count;
            }

            if ($this->manualDebtorsTableReady()) {
                $manualInvoicedRows = DB::table('manual_debtors')
                    ->selectRaw("DATE_FORMAT(invoice_date, '%Y-%m') AS month, SUM(COALESCE(grand_total, 0)) AS amount, COUNT(*) AS count")
                    ->whereRaw("LOWER(TRIM(COALESCE(status, 'open'))) NOT IN ('cancelled', 'canceled', 'void')")
                    ->whereNotNull('invoice_date')
                    ->when($start && $end, fn ($query) => $query->whereBetween(DB::raw('DATE(invoice_date)'), [$start, $end]))
                    ->groupByRaw("DATE_FORMAT(invoice_date, '%Y-%m')")
                    ->get();

                foreach ($manualInvoicedRows as $row) {
                    $month = (string) $row->month;
                    $rows[$month] = $this->monthlyTrendRow($month, $rows[$month] ?? null);
                    $rows[$month]['manualInvoiced'] += (float) $row->amount;
                    $rows[$month]['manualInvoiceCount'] += (int) $row->count;
                }

                $manualReceivedRows = DB::table('manual_debtors')
                    ->selectRaw("DATE_FORMAT(paid_date, '%Y-%m') AS month, SUM(COALESCE(paid_amount, 0)) AS amount, COUNT(*) AS count")
                    ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'paid'")
                    ->whereNotNull('paid_date')
                    ->when($start && $end, fn ($query) => $query->whereBetween(DB::raw('DATE(paid_date)'), [$start, $end]))
                    ->groupByRaw("DATE_FORMAT(paid_date, '%Y-%m')")
                    ->get();

                foreach ($manualReceivedRows as $row) {
                    $month = (string) $row->month;
                    $rows[$month] = $this->monthlyTrendRow($month, $rows[$month] ?? null);
                    $rows[$month]['manualReceived'] += (float) $row->amount;
                    $rows[$month]['manualReceivedCount'] += (int) $row->count;
                }
            }

            $trend = collect(array_values($rows))
                ->map(fn ($row) => array_merge($row, [
                    'invoiced' => (float) $row['systemInvoiced'] + (float) $row['manualInvoiced'],
                    'received' => (float) $row['systemReceived'] + (float) $row['manualReceived'],
                    'invoiceCount' => (int) $row['systemInvoiceCount'] + (int) $row['manualInvoiceCount'],
                    'receivedCount' => (int) $row['systemReceivedCount'] + (int) $row['manualReceivedCount'],
                    'netMovement' => ((float) $row['systemInvoiced'] + (float) $row['manualInvoiced'])
                        - ((float) $row['systemReceived'] + (float) $row['manualReceived']),
                ]))
                ->sortBy('month')
                ->values();

            return response()->json([
                'status' => 'success',
                'monthlyInvoicedReceivedTrend' => $trend,
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
            $invoiceTermsExpression = Schema::hasColumn('invoices', 'payment_terms_days')
                ? 'i.payment_terms_days'
                : 'NULL';
            $invoiceSourceExpression = Schema::hasColumn('invoices', 'payment_terms_source')
                ? "NULLIF(i.payment_terms_source, '')"
                : 'NULL';
            $clientTermsExpression = Schema::hasColumn('client_company', 'payment_terms_days')
                ? 'cc.payment_terms_days'
                : 'NULL';
            $paymentTermsExpression = "COALESCE({$invoiceTermsExpression}, {$clientTermsExpression}, 30)";
            $paymentTermsSourceExpression = "COALESCE({$invoiceSourceExpression}, CASE WHEN {$clientTermsExpression} IS NULL THEN 'system_default' ELSE 'client' END)";

            $query = DB::table('invoices as i')
                ->leftJoin('client_company as cc', 'i.client_id', '=', 'cc.company_id')
                ->leftJoin('projects_main as pm', 'i.project_id', '=', 'pm.id')
                ->leftJoin('staff_general as sg', 'pm.created_by', '=', 'sg.staff_id')
                ->selectRaw("i.id, i.client_id, i.project_id, i.invoice_ref_no, i.invoice_date, i.grand_total, i.status,
                    {$paymentTermsExpression} AS payment_terms_days,
                    {$paymentTermsSourceExpression} AS payment_terms_source,
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
                    : 'Client #'.(string) ($row->client_id ?? '');
                $row->project_name = (string) ($row->project_name ?? '') !== ''
                    ? $row->project_name
                    : 'Project #'.(string) ($row->project_id ?? '');
                $row->source_type = 'invoice';
                $row->sourceType = 'invoice';
                $row->source_id = (int) $row->id;
                $row->sourceId = (int) $row->id;

                return $row;
            });

            $manualRows = collect();
            if ($this->manualDebtorsTableReady()) {
                $manualPaymentTermsSelect = Schema::hasColumn('manual_debtors', 'payment_terms_days')
                    ? 'payment_terms_days'
                    : 'NULL AS payment_terms_days';
                $manualPaymentTermsSourceSelect = Schema::hasColumn('manual_debtors', 'payment_terms_source')
                    ? 'payment_terms_source'
                    : "'legacy' AS payment_terms_source";
                $manualDueDateSelect = Schema::hasColumn('manual_debtors', 'due_date')
                    ? 'due_date'
                    : 'NULL AS due_date';

                $manualRows = DB::table('manual_debtors')
                    ->selectRaw("id, invoice_ref_no, invoice_date, grand_total, status,
                        {$manualPaymentTermsSelect},
                        {$manualPaymentTermsSourceSelect},
                        {$manualDueDateSelect},
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
                'status' => 'success',
                'asOfDate' => $asOfDate,
                'debtors' => $systemRows
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

    private function monthlyTrendRow(string $month, ?array $existing = null): array
    {
        return $existing ?? [
            'month' => $month,
            'systemInvoiced' => 0.0,
            'manualInvoiced' => 0.0,
            'systemReceived' => 0.0,
            'manualReceived' => 0.0,
            'systemInvoiceCount' => 0,
            'manualInvoiceCount' => 0,
            'systemReceivedCount' => 0,
            'manualReceivedCount' => 0,
        ];
    }

    private function realizedSalesProjectQuery(): RealizedSalesProjectQuery
    {
        return app(RealizedSalesProjectQuery::class);
    }

    private function uninvoicedAwardedProjectTotals(string $asOfDate): array
    {
        $realizedQuery = $this->realizedSalesProjectQuery();
        $invoiceTotals = DB::table('invoices')
            ->selectRaw('project_id, SUM(COALESCE(grand_total, 0)) AS invoiced_total')
            ->whereNotNull('project_id')
            ->whereDate('invoice_date', '<=', $asOfDate)
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) NOT IN ('cancelled', 'canceled', 'void')")
            ->groupBy('project_id');

        $remainingProjects = $realizedQuery->projectFacts()
            ->leftJoinSub($invoiceTotals, 'project_invoices', 'project_facts.project_id', '=', 'project_invoices.project_id')
            ->selectRaw('
                CASE
                    WHEN COALESCE(project_facts.value, 0) - COALESCE(project_invoices.invoiced_total, 0) > 0
                    THEN COALESCE(project_facts.value, 0) - COALESCE(project_invoices.invoiced_total, 0)
                    ELSE 0
                END AS remaining_value
            ')
            ->whereRaw($realizedQuery->realizedStatusPredicate('project_facts.project_status'))
            ->whereNotNull('project_facts.award_date')
            ->whereDate('project_facts.award_date', '<=', $asOfDate);

        $totals = DB::query()
            ->fromSub($remainingProjects, 'remaining_projects')
            ->selectRaw('COALESCE(SUM(remaining_value), 0) AS amount, COUNT(*) AS count')
            ->where('remaining_value', '>', 0)
            ->first();

        return [
            'amount' => (float) ($totals->amount ?? 0),
            'count' => (int) ($totals->count ?? 0),
        ];
    }

    private function manualDebtorsTableReady(): bool
    {
        return Schema::hasTable('manual_debtors');
    }
}
