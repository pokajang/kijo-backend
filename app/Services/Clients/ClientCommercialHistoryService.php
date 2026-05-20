<?php

namespace App\Services\Clients;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientCommercialHistoryService extends ClientBaseService
{
    private const QUOTE_SOURCES = [
        'training' => [
            'table' => 'quotes_training',
            'service_type' => 'Training',
            'project_type' => 'Training',
        ],
        'equipment' => [
            'table' => 'quotes_equipment',
            'service_type' => 'Equipment Supply',
            'project_type' => 'Equipment Supply',
        ],
        'manpower' => [
            'table' => 'quotes_manpower',
            'service_type' => 'Manpower Supply',
            'project_type' => 'Manpower Supply',
        ],
        'ih' => [
            'table' => 'quotes_ih',
            'service_type' => 'Industrial Hygiene',
            'project_type' => 'Industrial Hygiene',
        ],
        'special' => [
            'table' => 'quotes_special',
            'service_type' => 'Special Service',
            'project_type' => 'Special Service',
        ],
    ];

    public function show(Request $request, int $companyId): JsonResponse
    {
        [$start, $end, $error] = $this->dateRange($request);
        if ($error !== null) {
            return $this->error($error, 422);
        }

        if ($companyId <= 0) {
            return $this->error('Invalid client company ID.', 422);
        }

        try {
            $client = $this->client($companyId);
            if (!$client) {
                return $this->error('Client company not found.', 404);
            }

            return $this->success([
                'client' => $client,
                'summary' => $this->summary($companyId, $start, $end),
                'payments' => $this->payments($companyId, $start, $end),
                'invoices' => $this->invoices($companyId, $start, $end),
                'quotes' => $this->quotes($companyId, $start, $end),
                'start' => $start,
                'end' => $end,
            ]);
        } catch (\Throwable $e) {
            report($e);
            return $this->error('Server error', 500);
        }
    }

    private function dateRange(Request $request): array
    {
        $start = trim((string) $request->query('start', ''));
        $end = trim((string) $request->query('end', ''));

        if ($start !== '' && !$this->isValidDate($start)) {
            return [null, null, 'Invalid start date. Use YYYY-MM-DD.'];
        }

        if ($end !== '' && !$this->isValidDate($end)) {
            return [null, null, 'Invalid end date. Use YYYY-MM-DD.'];
        }

        if ($start !== '' && $end !== '' && $start > $end) {
            return [null, null, 'Start date must be before end date.'];
        }

        return [$start !== '' ? $start : null, $end !== '' ? $end : null, null];
    }

    private function isValidDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    private function client(int $companyId): ?array
    {
        if (!Schema::hasTable('client_company')) {
            return null;
        }

        $columns = $this->selectColumns('client_company', [
            'company_id',
            'company_name',
            'client_status',
            'payment_terms_days',
            'payment_terms_source',
        ]);

        $query = DB::table('client_company')
            ->select($columns)
            ->where('company_id', $companyId);

        if (Schema::hasColumn('client_company', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $row = $query->first();
        if (!$row) {
            return null;
        }

        $data = (array) $row;
        $data['effective_payment_terms_days'] = $this->effectiveClientPaymentTermsDays($data['payment_terms_days'] ?? null);
        if (($data['payment_terms_source'] ?? null) === null || $data['payment_terms_source'] === '') {
            $data['payment_terms_source'] = $this->clientPaymentTermsSource($data['payment_terms_days'] ?? null);
        }

        return $data;
    }

    private function summary(int $companyId, ?string $start, ?string $end): array
    {
        $row = app(ClientRoiReportService::class)->rowForClient($companyId, $start, $end);

        return $row ?? [
            'company_id' => $companyId,
            'awarded_project_count' => 0,
            'awarded_value' => 0.0,
            'invoice_count' => 0,
            'invoiced_total' => 0.0,
            'received_count' => 0,
            'received_total' => 0.0,
            'vendor_cost' => 0.0,
            'expense_cost' => 0.0,
            'total_cost' => 0.0,
            'actual_profit' => 0.0,
            'projected_profit' => 0.0,
            'actual_roi_percent' => null,
            'projected_roi_percent' => null,
            'actual_margin_percent' => null,
            'projected_margin_percent' => null,
            'average_payment_days' => null,
            'last_paid_date' => null,
        ];
    }

    private function payments(int $companyId, ?string $start, ?string $end): array
    {
        $rows = [];

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'client_id')) {
            $query = DB::table('invoices as i')
                ->where('i.client_id', $companyId)
                ->whereRaw("LOWER(TRIM(COALESCE(i.status, ''))) = 'paid'")
                ->whereNotNull('i.paid_date')
                ->select($this->aliasedColumns('invoices', 'i', [
                    'id',
                    'invoice_ref_no',
                    'project_id',
                    'invoice_date',
                    'grand_total',
                    'status',
                    'paid_date',
                    'paid_amount',
                    'payment_method',
                ]))
                ->addSelect(DB::raw("'system_invoice' as source_type"));

            if (Schema::hasTable('projects_main')) {
                $query
                    ->leftJoin('projects_main as p', 'p.id', '=', 'i.project_id')
                    ->addSelect($this->aliasedColumn('projects_main', 'p', 'project_name', 'project_name'));
            } else {
                $query->addSelect(DB::raw('NULL as project_name'));
            }

            $this->applyDateRange($query, 'i.paid_date', $start, $end);

            foreach ($query->get() as $row) {
                $rows[] = $this->paymentRow((array) $row);
            }
        }

        if ($this->manualDebtorsReady()) {
            $query = DB::table('manual_debtors as md')
                ->where('md.client_id', $companyId)
                ->whereRaw("LOWER(TRIM(COALESCE(md.status, ''))) = 'paid'")
                ->whereNotNull('md.paid_date')
                ->select($this->aliasedColumns('manual_debtors', 'md', [
                    'id',
                    'invoice_ref_no',
                    'invoice_date',
                    'grand_total',
                    'status',
                    'paid_date',
                    'paid_amount',
                    'payment_method',
                ]))
                ->addSelect(DB::raw("'manual_debtor' as source_type"))
                ->addSelect(DB::raw('NULL as project_id'))
                ->addSelect(DB::raw('NULL as project_name'));

            $this->applyDateRange($query, 'md.paid_date', $start, $end);

            foreach ($query->get() as $row) {
                $rows[] = $this->paymentRow((array) $row);
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['paid_date'] ?? ''), (string) ($a['paid_date'] ?? '')));

        return $rows;
    }

    private function invoices(int $companyId, ?string $start, ?string $end): array
    {
        $rows = [];

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'client_id')) {
            $query = DB::table('invoices as i')
                ->where('i.client_id', $companyId)
                ->select($this->aliasedColumns('invoices', 'i', [
                    'id',
                    'invoice_ref_no',
                    'project_id',
                    'invoice_date',
                    'grand_total',
                    'status',
                    'paid_date',
                    'paid_amount',
                    'due_date',
                    'payment_terms_days',
                ]))
                ->addSelect(DB::raw("'system_invoice' as source_type"));

            if (Schema::hasTable('projects_main')) {
                $query
                    ->leftJoin('projects_main as p', 'p.id', '=', 'i.project_id')
                    ->addSelect($this->aliasedColumn('projects_main', 'p', 'project_name', 'project_name'));
            } else {
                $query->addSelect(DB::raw('NULL as project_name'));
            }

            $this->applyDateRange($query, 'i.invoice_date', $start, $end);

            foreach ($query->get() as $row) {
                $rows[] = $this->invoiceRow((array) $row);
            }
        }

        if ($this->manualDebtorsReady()) {
            $query = DB::table('manual_debtors as md')
                ->where('md.client_id', $companyId)
                ->select($this->aliasedColumns('manual_debtors', 'md', [
                    'id',
                    'invoice_ref_no',
                    'invoice_date',
                    'grand_total',
                    'status',
                    'paid_date',
                    'paid_amount',
                    'due_date',
                    'payment_terms_days',
                ]))
                ->addSelect(DB::raw("'manual_debtor' as source_type"))
                ->addSelect(DB::raw('NULL as project_id'))
                ->addSelect(DB::raw('NULL as project_name'));

            $this->applyDateRange($query, 'md.invoice_date', $start, $end);

            foreach ($query->get() as $row) {
                $rows[] = $this->invoiceRow((array) $row);
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['invoice_date'] ?? ''), (string) ($a['invoice_date'] ?? '')));

        return $rows;
    }

    private function quotes(int $companyId, ?string $start, ?string $end): array
    {
        $rows = [];

        foreach (self::QUOTE_SOURCES as $sourceType => $config) {
            $table = $config['table'];
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'client_id')) {
                continue;
            }

            $query = DB::table("{$table} as q")
                ->where('q.client_id', $companyId)
                ->select($this->aliasedColumns($table, 'q', [
                    'id',
                    'quote_ref_no',
                    'status',
                    'grand_total',
                    'created_at',
                    'updated_at',
                ]))
                ->addSelect(DB::raw("'{$sourceType}' as source_type"))
                ->addSelect(DB::raw("'" . str_replace("'", "''", $config['service_type']) . "' as service_type"));

            $quoteRows = $query->get();
            $linkedProjects = $this->linkedProjectsByQuoteId(
                $companyId,
                $config,
                $quoteRows->pluck('id')->all()
            );

            foreach ($quoteRows as $row) {
                $data = (array) $row;
                $linkedProject = $linkedProjects[(int) ($data['id'] ?? 0)] ?? null;
                $data['project_id'] = $linkedProject['project_id'] ?? null;
                $data['project_name'] = $linkedProject['project_name'] ?? null;
                $data['award_date'] = $linkedProject['award_date'] ?? null;
                $data['project_count'] = $linkedProject['project_count'] ?? 0;

                $quoteRow = $this->quoteRow($data);
                if (!$this->dateInRange($quoteRow['quote_date'] ?? null, $start, $end)) {
                    continue;
                }
                $rows[] = $quoteRow;
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['quote_date'] ?? ''), (string) ($a['quote_date'] ?? '')));

        return $rows;
    }

    private function linkedProjectsByQuoteId(int $companyId, array $config, array $quoteIds): array
    {
        $quoteIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $quoteIds),
            static fn (int $id): bool => $id > 0
        )));

        if (
            !$quoteIds
            || !Schema::hasTable('projects_main')
            || !Schema::hasColumn('projects_main', 'quote_id')
            || !Schema::hasColumn('projects_main', 'client_id')
        ) {
            return [];
        }

        $query = DB::table('projects_main')
            ->where('client_id', $companyId)
            ->whereIn('quote_id', $quoteIds)
            ->select($this->selectColumns('projects_main', [
                'id',
                'quote_id',
                'project_name',
                'award_date',
            ]));

        if (Schema::hasColumn('projects_main', 'project_type')) {
            $query->where('project_type', $config['project_type']);
        }

        $projects = [];
        foreach ($query->get() as $row) {
            $project = (array) $row;
            $quoteId = (int) ($project['quote_id'] ?? 0);
            if ($quoteId <= 0) {
                continue;
            }

            if (!isset($projects[$quoteId])) {
                $projects[$quoteId] = [
                    'project_id' => null,
                    'project_name' => null,
                    'award_date' => null,
                    'project_count' => 0,
                ];
            }

            $projects[$quoteId]['project_count']++;
            if ($this->projectSortsAfter($project, $projects[$quoteId])) {
                $projects[$quoteId]['project_id'] = $project['id'] ?? null;
                $projects[$quoteId]['project_name'] = $project['project_name'] ?? null;
                $projects[$quoteId]['award_date'] = $this->dateOnly($project['award_date'] ?? null);
            }
        }

        return $projects;
    }

    private function projectSortsAfter(array $candidate, array $current): bool
    {
        if (($current['project_id'] ?? null) === null) {
            return true;
        }

        $candidateAwardDate = $this->dateOnly($candidate['award_date'] ?? null) ?? '';
        $currentAwardDate = $this->dateOnly($current['award_date'] ?? null) ?? '';

        if ($candidateAwardDate !== $currentAwardDate) {
            return $candidateAwardDate > $currentAwardDate;
        }

        return (int) ($candidate['id'] ?? 0) > (int) ($current['project_id'] ?? 0);
    }

    private function paymentRow(array $row): array
    {
        return [
            'source_type' => $row['source_type'] ?? '',
            'id' => (int) ($row['id'] ?? 0),
            'invoice_ref_no' => $row['invoice_ref_no'] ?? null,
            'project_id' => $row['project_id'] ?? null,
            'project_name' => $row['project_name'] ?? null,
            'invoice_date' => $this->dateOnly($row['invoice_date'] ?? null),
            'grand_total' => $this->money($row['grand_total'] ?? 0),
            'status' => $row['status'] ?? null,
            'paid_date' => $this->dateOnly($row['paid_date'] ?? null),
            'paid_amount' => $this->money($row['paid_amount'] ?? 0),
            'payment_method' => $row['payment_method'] ?? null,
            'payment_days' => $this->paymentDays($row['invoice_date'] ?? null, $row['paid_date'] ?? null),
        ];
    }

    private function invoiceRow(array $row): array
    {
        return [
            'source_type' => $row['source_type'] ?? '',
            'id' => (int) ($row['id'] ?? 0),
            'invoice_ref_no' => $row['invoice_ref_no'] ?? null,
            'project_id' => $row['project_id'] ?? null,
            'project_name' => $row['project_name'] ?? null,
            'invoice_date' => $this->dateOnly($row['invoice_date'] ?? null),
            'grand_total' => $this->money($row['grand_total'] ?? 0),
            'status' => $row['status'] ?? null,
            'paid_date' => $this->dateOnly($row['paid_date'] ?? null),
            'paid_amount' => $this->money($row['paid_amount'] ?? 0),
            'due_date' => $this->dateOnly($row['due_date'] ?? null),
            'payment_terms_days' => $row['payment_terms_days'] ?? null,
            'payment_days' => $this->paymentDays($row['invoice_date'] ?? null, $row['paid_date'] ?? null),
        ];
    }

    private function quoteRow(array $row): array
    {
        $quoteDate = $this->dateOnly($row['award_date'] ?? null)
            ?: $this->dateOnly($row['created_at'] ?? null)
            ?: $this->dateOnly($row['updated_at'] ?? null);

        return [
            'source_type' => $row['source_type'] ?? '',
            'id' => (int) ($row['id'] ?? 0),
            'quote_ref_no' => $row['quote_ref_no'] ?? null,
            'service_type' => $row['service_type'] ?? null,
            'status' => $row['status'] ?? null,
            'grand_total' => $this->money($row['grand_total'] ?? 0),
            'created_at' => $this->dateOnly($row['created_at'] ?? null),
            'updated_at' => $this->dateOnly($row['updated_at'] ?? null),
            'project_id' => $row['project_id'] ?? null,
            'project_name' => $row['project_name'] ?? null,
            'award_date' => $this->dateOnly($row['award_date'] ?? null),
            'quote_date' => $quoteDate,
            'project_count' => (int) ($row['project_count'] ?? 0),
        ];
    }

    private function applyDateRange($query, string $column, ?string $start, ?string $end): void
    {
        if ($start !== null) {
            $query->whereDate($column, '>=', $start);
        }

        if ($end !== null) {
            $query->whereDate($column, '<=', $end);
        }
    }

    private function dateInRange(?string $date, ?string $start, ?string $end): bool
    {
        if (!$date) {
            return $start === null && $end === null;
        }

        if ($start !== null && $date < $start) {
            return false;
        }

        if ($end !== null && $date > $end) {
            return false;
        }

        return true;
    }

    private function manualDebtorsReady(): bool
    {
        foreach (['client_id', 'status', 'invoice_date', 'grand_total', 'paid_date', 'paid_amount'] as $column) {
            if (!Schema::hasTable('manual_debtors') || !Schema::hasColumn('manual_debtors', $column)) {
                return false;
            }
        }

        return true;
    }

    private function selectColumns(string $table, array $columns): array
    {
        return array_map(
            static fn (string $column) => Schema::hasColumn($table, $column)
                ? $column
                : DB::raw("NULL as {$column}"),
            $columns
        );
    }

    private function aliasedColumns(string $table, string $alias, array $columns): array
    {
        return array_map(
            fn (string $column) => $this->aliasedColumn($table, $alias, $column, $column),
            $columns
        );
    }

    private function aliasedColumn(string $table, string $alias, string $column, string $as)
    {
        return Schema::hasColumn($table, $column)
            ? DB::raw("{$alias}.{$column} as {$as}")
            : DB::raw("NULL as {$as}");
    }

    private function dateOnly($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function paymentDays($invoiceDate, $paidDate): ?int
    {
        if (!$invoiceDate || !$paidDate) {
            return null;
        }

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

    private function money($value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
