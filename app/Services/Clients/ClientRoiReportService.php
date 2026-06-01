<?php

namespace App\Services\Clients;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientRoiReportService extends ClientBaseService
{
    private const APPROVED_COST_STATUSES = ['approved', 'paid', 'completed', 'transferred'];

    public function index(Request $request): JsonResponse
    {
        [$start, $end, $error] = $this->dateRange($request);
        if ($error !== null) {
            return $this->error($error, 422);
        }

        try {
            return $this->success([
                'rows' => $this->reportRows($start, $end),
                'start' => $start,
                'end' => $end,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Server error', 500);
        }
    }

    public function rowForClient(int $clientId, ?string $start, ?string $end): ?array
    {
        foreach ($this->reportRows($start, $end) as $row) {
            if ((int) ($row['company_id'] ?? 0) === $clientId) {
                return $row;
            }
        }

        return null;
    }

    public function reportRows(?string $start, ?string $end): array
    {
        $metrics = [];

        $this->mergeAwardedProjects($metrics, $start, $end);
        $this->mergeSystemInvoices($metrics, $start, $end);
        $this->mergeManualDebtors($metrics, $start, $end);
        $this->mergeProjectCosts($metrics, $start, $end);

        if (empty($metrics)) {
            return [];
        }

        $clients = $this->clientRows(array_keys($metrics));
        $rows = [];

        foreach ($metrics as $clientId => $metric) {
            $client = $clients[$clientId] ?? null;
            if (! $client) {
                continue;
            }

            $awardedValue = $this->money($metric['awarded_value'] ?? 0);
            $invoicedTotal = $this->money($metric['invoiced_total'] ?? 0);
            $receivedTotal = $this->money($metric['received_total'] ?? 0);
            $vendorCost = $this->money($metric['vendor_cost'] ?? 0);
            $expenseCost = $this->money($metric['expense_cost'] ?? 0);
            $totalCost = $this->money($vendorCost + $expenseCost);
            $actualProfit = $this->money($receivedTotal - $totalCost);
            $projectedProfit = $this->money($awardedValue - $totalCost);
            $paymentDaysCount = (int) ($metric['payment_days_count'] ?? 0);

            $rows[] = [
                'company_id' => $clientId,
                'company_name' => $client->company_name,
                'client_status' => $client->client_status ?? null,
                'awarded_project_count' => (int) ($metric['awarded_project_count'] ?? 0),
                'awarded_value' => $awardedValue,
                'invoice_count' => (int) ($metric['invoice_count'] ?? 0),
                'invoiced_total' => $invoicedTotal,
                'received_count' => (int) ($metric['received_count'] ?? 0),
                'received_total' => $receivedTotal,
                'vendor_cost' => $vendorCost,
                'expense_cost' => $expenseCost,
                'total_cost' => $totalCost,
                'actual_profit' => $actualProfit,
                'projected_profit' => $projectedProfit,
                'actual_roi_percent' => $totalCost > 0 ? $this->percent($actualProfit, $totalCost) : null,
                'projected_roi_percent' => $totalCost > 0 ? $this->percent($projectedProfit, $totalCost) : null,
                'actual_margin_percent' => $receivedTotal > 0 ? $this->percent($actualProfit, $receivedTotal) : null,
                'projected_margin_percent' => $awardedValue > 0 ? $this->percent($projectedProfit, $awardedValue) : null,
                'average_payment_days' => $paymentDaysCount > 0
                    ? round(((float) ($metric['payment_days_total'] ?? 0)) / $paymentDaysCount, 1)
                    : null,
                'last_paid_date' => $metric['last_paid_date'] ?? null,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $awarded = $b['awarded_value'] <=> $a['awarded_value'];
            if ($awarded !== 0) {
                return $awarded;
            }

            return $b['actual_profit'] <=> $a['actual_profit'];
        });

        return array_values($rows);
    }

    private function dateRange(Request $request): array
    {
        $start = trim((string) $request->query('start', ''));
        $end = trim((string) $request->query('end', ''));

        if ($start !== '' && ! $this->isValidDate($start)) {
            return [null, null, 'Invalid start date. Use YYYY-MM-DD.'];
        }

        if ($end !== '' && ! $this->isValidDate($end)) {
            return [null, null, 'Invalid end date. Use YYYY-MM-DD.'];
        }

        if ($start !== '' && $end !== '' && $start > $end) {
            return [null, null, 'Start date must be before end date.'];
        }

        return [$start !== '' ? $start : null, $end !== '' ? $end : null, null];
    }

    private function isValidDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    private function mergeAwardedProjects(array &$metrics, ?string $start, ?string $end): void
    {
        if (! Schema::hasTable('projects_main') || ! Schema::hasColumn('projects_main', 'client_id')) {
            return;
        }

        $query = DB::table('projects_main')
            ->selectRaw('client_id, COUNT(*) AS awarded_project_count, COALESCE(SUM(quote_value), 0) AS awarded_value')
            ->whereNotNull('client_id')
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) <> 'terminated'")
            ->groupBy('client_id');

        $this->applyDateRange($query, 'award_date', $start, $end);

        foreach ($query->get() as $row) {
            $this->addMetric($metrics, (int) $row->client_id, [
                'awarded_project_count' => (int) $row->awarded_project_count,
                'awarded_value' => (float) $row->awarded_value,
            ]);
        }
    }

    private function mergeSystemInvoices(array &$metrics, ?string $start, ?string $end): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'client_id')) {
            return;
        }

        $invoiceQuery = DB::table('invoices')
            ->selectRaw('client_id, COUNT(*) AS invoice_count, COALESCE(SUM(grand_total), 0) AS invoiced_total')
            ->whereNotNull('client_id')
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) NOT IN ('cancelled', 'canceled', 'void')")
            ->groupBy('client_id');

        $this->applyDateRange($invoiceQuery, 'invoice_date', $start, $end);

        foreach ($invoiceQuery->get() as $row) {
            $this->addMetric($metrics, (int) $row->client_id, [
                'invoice_count' => (int) $row->invoice_count,
                'invoiced_total' => (float) $row->invoiced_total,
            ]);
        }

        $paidQuery = DB::table('invoices')
            ->selectRaw('client_id, COUNT(*) AS received_count, COALESCE(SUM(paid_amount), 0) AS received_total, MAX(paid_date) AS last_paid_date')
            ->whereNotNull('client_id')
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'paid'")
            ->whereNotNull('paid_date')
            ->groupBy('client_id');

        $this->applyDateRange($paidQuery, 'paid_date', $start, $end);

        foreach ($paidQuery->get() as $row) {
            $this->addMetric($metrics, (int) $row->client_id, [
                'received_count' => (int) $row->received_count,
                'received_total' => (float) $row->received_total,
                'last_paid_date' => $row->last_paid_date,
            ]);
        }

        $this->mergePaymentDays($metrics, 'invoices', $start, $end);
    }

    private function mergeManualDebtors(array &$metrics, ?string $start, ?string $end): void
    {
        if (! $this->manualDebtorsReady()) {
            return;
        }

        $invoiceQuery = DB::table('manual_debtors')
            ->selectRaw('client_id, COUNT(*) AS invoice_count, COALESCE(SUM(grand_total), 0) AS invoiced_total')
            ->whereNotNull('client_id')
            ->whereRaw("LOWER(TRIM(COALESCE(status, 'open'))) NOT IN ('cancelled', 'canceled', 'void')")
            ->groupBy('client_id');

        $this->applyDateRange($invoiceQuery, 'invoice_date', $start, $end);

        foreach ($invoiceQuery->get() as $row) {
            $this->addMetric($metrics, (int) $row->client_id, [
                'invoice_count' => (int) $row->invoice_count,
                'invoiced_total' => (float) $row->invoiced_total,
            ]);
        }

        $paidQuery = DB::table('manual_debtors')
            ->selectRaw('client_id, COUNT(*) AS received_count, COALESCE(SUM(paid_amount), 0) AS received_total, MAX(paid_date) AS last_paid_date')
            ->whereNotNull('client_id')
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'paid'")
            ->whereNotNull('paid_date')
            ->groupBy('client_id');

        $this->applyDateRange($paidQuery, 'paid_date', $start, $end);

        foreach ($paidQuery->get() as $row) {
            $this->addMetric($metrics, (int) $row->client_id, [
                'received_count' => (int) $row->received_count,
                'received_total' => (float) $row->received_total,
                'last_paid_date' => $row->last_paid_date,
            ]);
        }

        $this->mergePaymentDays($metrics, 'manual_debtors', $start, $end);
    }

    private function mergePaymentDays(array &$metrics, string $table, ?string $start, ?string $end): void
    {
        foreach (['client_id', 'status', 'invoice_date', 'paid_date'] as $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        $query = DB::table($table)
            ->select('client_id', 'invoice_date', 'paid_date')
            ->whereNotNull('client_id')
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'paid'")
            ->whereNotNull('invoice_date')
            ->whereNotNull('paid_date');

        $this->applyDateRange($query, 'paid_date', $start, $end);

        foreach ($query->get() as $row) {
            $days = $this->paymentDays($row->invoice_date, $row->paid_date);
            if ($days === null) {
                continue;
            }

            $this->addMetric($metrics, (int) $row->client_id, [
                'payment_days_total' => $days,
                'payment_days_count' => 1,
            ]);
        }
    }

    private function mergeProjectCosts(array &$metrics, ?string $start, ?string $end): void
    {
        if (! Schema::hasTable('projects_main') || ! Schema::hasColumn('projects_main', 'client_id')) {
            return;
        }

        if (Schema::hasTable('vendor_payments')) {
            $vendorQuery = DB::table('projects_main as p')
                ->join('vendor_payments as vp', 'vp.project_id', '=', 'p.id')
                ->selectRaw('p.client_id, COALESCE(SUM(vp.amount), 0) AS vendor_cost')
                ->whereNotNull('p.client_id')
                ->whereIn(DB::raw('LOWER(TRIM(vp.status))'), self::APPROVED_COST_STATUSES)
                ->groupBy('p.client_id');

            if (Schema::hasColumn('vendor_payments', 'deleted_at')) {
                $vendorQuery->whereNull('vp.deleted_at');
            }

            $this->applyDateRange($vendorQuery, $this->vendorPaymentDateExpression(), $start, $end);

            foreach ($vendorQuery->get() as $row) {
                $this->addMetric($metrics, (int) $row->client_id, [
                    'vendor_cost' => (float) $row->vendor_cost,
                ]);
            }
        }

        if (Schema::hasTable('project_expenses')) {
            $expenseQuery = DB::table('projects_main as p')
                ->join('project_expenses as pe', 'pe.project_id', '=', 'p.id')
                ->selectRaw('p.client_id, COALESCE(SUM(pe.amount), 0) AS expense_cost')
                ->whereNotNull('p.client_id')
                ->groupBy('p.client_id');

            $this->applyDateRange($expenseQuery, $this->projectExpenseDateExpression(), $start, $end);

            foreach ($expenseQuery->get() as $row) {
                $this->addMetric($metrics, (int) $row->client_id, [
                    'expense_cost' => (float) $row->expense_cost,
                ]);
            }
        }
    }

    private function clientRows(array $clientIds): array
    {
        $columns = ['company_id', 'company_name'];
        if (Schema::hasColumn('client_company', 'client_status')) {
            $columns[] = 'client_status';
        }

        $query = DB::table('client_company')
            ->select($columns)
            ->whereIn('company_id', $clientIds);

        if (Schema::hasColumn('client_company', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->get()->keyBy(static fn ($row) => (int) $row->company_id)->all();
    }

    private function addMetric(array &$metrics, int $clientId, array $values): void
    {
        if ($clientId <= 0) {
            return;
        }

        $metrics[$clientId] ??= [
            'awarded_project_count' => 0,
            'awarded_value' => 0,
            'invoice_count' => 0,
            'invoiced_total' => 0,
            'received_count' => 0,
            'received_total' => 0,
            'vendor_cost' => 0,
            'expense_cost' => 0,
            'payment_days_total' => 0,
            'payment_days_count' => 0,
            'last_paid_date' => null,
        ];

        foreach ($values as $key => $value) {
            if ($key === 'last_paid_date') {
                if ($value !== null && ($metrics[$clientId][$key] === null || $value > $metrics[$clientId][$key])) {
                    $metrics[$clientId][$key] = $value;
                }

                continue;
            }

            $metrics[$clientId][$key] += $value;
        }
    }

    private function applyDateRange($query, ?string $column, ?string $start, ?string $end): void
    {
        if ($column === null) {
            return;
        }

        $dateColumn = str_contains($column, '(') ? DB::raw($column) : $column;

        if ($start !== null) {
            $query->whereDate($dateColumn, '>=', $start);
        }

        if ($end !== null) {
            $query->whereDate($dateColumn, '<=', $end);
        }
    }

    private function vendorPaymentDateExpression(): ?string
    {
        $columns = [];
        foreach (['paid_date', 'date_approved', 'created_at'] as $column) {
            if (Schema::hasColumn('vendor_payments', $column)) {
                $columns[] = "vp.{$column}";
            }
        }

        if (! $columns && Schema::hasColumn('projects_main', 'award_date')) {
            $columns[] = 'p.award_date';
        }

        return $this->dateExpression($columns);
    }

    private function projectExpenseDateExpression(): ?string
    {
        $columns = [];
        foreach (['date', 'created_at'] as $column) {
            if (Schema::hasColumn('project_expenses', $column)) {
                $columns[] = "pe.{$column}";
            }
        }

        if (! $columns && Schema::hasColumn('projects_main', 'award_date')) {
            $columns[] = 'p.award_date';
        }

        return $this->dateExpression($columns);
    }

    private function dateExpression(array $columns): ?string
    {
        if (count($columns) > 1) {
            return 'COALESCE('.implode(', ', $columns).')';
        }

        return $columns[0] ?? null;
    }

    private function manualDebtorsReady(): bool
    {
        foreach (['client_id', 'status', 'invoice_date', 'grand_total', 'paid_date', 'paid_amount'] as $column) {
            if (! Schema::hasTable('manual_debtors') || ! Schema::hasColumn('manual_debtors', $column)) {
                return false;
            }
        }

        return true;
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }

    private function percent(float $numerator, float $denominator): float
    {
        return round(($numerator / $denominator) * 100, 2);
    }

    private function paymentDays($invoiceDate, $paidDate): ?int
    {
        try {
            $invoice = Carbon::parse(substr((string) $invoiceDate, 0, 10))->startOfDay();
            $paid = Carbon::parse(substr((string) $paidDate, 0, 10))->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($paid->lt($invoice)) {
            return null;
        }

        return (int) $invoice->diffInDays($paid);
    }
}
