<?php

namespace App\Services\Assistant\CompanyAnalytics;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CompanyCommercialAnalyticsAnalyzer
{
    private const QUOTE_TABLES = [
        'quotes_training' => 'Training',
        'quotes_ih' => 'IH',
        'quotes_manpower' => 'Manpower',
        'quotes_special' => 'Special',
        'quotes_equipment' => 'Equipment',
    ];

    public function __construct(private readonly AssistantCompanyAnalyticsDateRangeResolver $dateRanges) {}

    public function analyze(string $domain, string $question, array $dateRange): ?CompanyAnalyticsResult
    {
        return match ($domain) {
            'sales' => $this->sales($question, $dateRange),
            'quotes' => $this->quotes($question, $dateRange),
            'clients' => $this->clients($question, $dateRange),
            'projects' => $this->projects($question, $dateRange),
            'invoices' => $this->invoices($question, $dateRange),
            'receivables', 'debtors' => $this->receivables($question, $dateRange),
            'crm_inquiries' => $this->crmInquiries($question, $dateRange),
            'vendor_costs' => $this->vendorCosts($question, $dateRange),
            'commercial_documents' => $this->commercialDocuments($question, $dateRange),
            default => null,
        };
    }

    public function sales(string $question, array $dateRange): CompanyAnalyticsResult
    {
        $projects = array_values(array_filter($this->projectRows($dateRange), fn (array $row): bool => $this->isAwardedProjectStatus($row['status'])));
        $staffNames = $this->staffNameMap();
        $missing = [];
        $definition = 'Sales means awarded or closed project value in the selected date range. If no project award rows exist, awarded quotations are used as a fallback.';

        $rows = [];
        if ($projects !== []) {
            foreach ($projects as $project) {
                $staffKey = $project['created_by'] !== null ? 'staff:'.$project['created_by'] : 'unknown';
                $rows[] = [
                    'staff_key' => $staffKey,
                    'staff_name' => $staffNames[(string) $project['created_by']] ?? 'Not recorded',
                    'client' => $project['client'] ?: 'Not recorded',
                    'service' => $project['service_type'] ?: 'Not recorded',
                    'status' => $project['status'] ?: 'Not recorded',
                    'month' => $this->monthKey($project['date']),
                    'value' => (float) $project['value'],
                    'ref' => $project['name'],
                ];
            }
        } else {
            $missing[] = 'projects_main awarded rows';
            foreach (array_filter($this->quoteRows($dateRange), fn (array $row): bool => $this->isAwardedStatus($row['status'])) as $quote) {
                $staffKey = $quote['created_by_id'] !== null ? 'staff:'.$quote['created_by_id'] : 'code:'.$quote['created_by_code'];
                $rows[] = [
                    'staff_key' => $staffKey,
                    'staff_name' => $staffNames[(string) $quote['created_by_id']] ?? $staffNames[(string) $quote['created_by_code']] ?? 'Not recorded',
                    'client' => $quote['client'] ?: 'Not recorded',
                    'service' => $quote['service_type'] ?: 'Not recorded',
                    'status' => $quote['status'] ?: 'Not recorded',
                    'month' => $this->monthKey($quote['date']),
                    'value' => (float) $quote['value'],
                    'ref' => $quote['ref'],
                ];
            }
        }

        return new CompanyAnalyticsResult(
            'company_analytics.sales',
            'Company sales analytics',
            $definition,
            $dateRange,
            [
                'sales_count' => count($rows),
                'sales_value' => $this->sum($rows, 'value'),
            ],
            [
                'top_staff' => $this->rankRows($rows, 'staff_name', 'value', 'sales_value', 'sales_count'),
                'by_client' => $this->sumBy($rows, 'client', 'value'),
                'by_service' => $this->sumBy($rows, 'service', 'value'),
                'by_month' => $this->sumBy($rows, 'month', 'value'),
            ],
            array_slice($rows, 0, 10),
            ['break sales down by client', 'show sales trend by month', 'which service brought most sales'],
            array_values(array_unique($missing)),
            $missing === [] ? 'high' : 'medium',
            'Company sales summary.',
            '/dashboard/sales',
            ['analyzer' => 'CompanyCommercialAnalyticsAnalyzer::sales'],
        );
    }

    public function quotes(string $question, array $dateRange): CompanyAnalyticsResult
    {
        $rows = array_values(array_filter($this->quoteRows($dateRange), fn (array $row): bool => ! $this->isCancelledStatus($row['status'])));
        $awarded = array_values(array_filter($rows, fn (array $row): bool => $this->isAwardedStatus($row['status'])));
        $failed = array_values(array_filter($rows, fn (array $row): bool => $this->isFailedStatus($row['status'])));
        $open = array_values(array_filter($rows, fn (array $row): bool => ! $this->isAwardedStatus($row['status']) && ! $this->isFailedStatus($row['status']) && ! $this->isCancelledStatus($row['status'])));
        $decisionCount = count($awarded) + count($failed);
        $staffNames = $this->staffNameMap();

        foreach ($rows as $index => $row) {
            $rows[$index]['staff_name'] = $staffNames[(string) $row['created_by_id']] ?? $staffNames[(string) $row['created_by_code']] ?? 'Not recorded';
        }

        return new CompanyAnalyticsResult(
            'company_analytics.quotes',
            'Company quotation analytics',
            'Quote analytics count saved quotation records once per latest reference, with awarded, failed, open, quoted value, and conversion summaries.',
            $dateRange,
            [
                'quote_count' => count($rows),
                'quoted_value' => $this->sum($rows, 'value'),
                'awarded_count' => count($awarded),
                'awarded_value' => $this->sum($awarded, 'value'),
                'failed_count' => count($failed),
                'open_count' => count($open),
                'win_rate' => $decisionCount > 0 ? count($awarded) / $decisionCount * 100 : 0,
            ],
            [
                'by_status' => $this->countBy($rows, 'status'),
                'by_service' => $this->sumBy($rows, 'service_type', 'value'),
                'by_staff' => $this->rankRows($rows, 'staff_name', 'value', 'quoted_value', 'quote_count'),
                'by_client' => $this->sumBy($rows, 'client', 'value'),
                'by_month' => $this->countBy($rows, 'month'),
            ],
            array_slice($rows, 0, 10),
            ['show quote conversion by service', 'show open pipeline by client', 'break quotes down by staff'],
            [],
            'high',
            'Company quotation summary.',
            '/crm/quotes',
            ['analyzer' => 'CompanyCommercialAnalyticsAnalyzer::quotes'],
        );
    }

    public function clients(string $question, array $dateRange): CompanyAnalyticsResult
    {
        $projects = array_values(array_filter($this->projectRows($dateRange), fn (array $row): bool => $this->isAwardedProjectStatus($row['status'])));
        $invoices = $this->invoiceRows($dateRange);
        $debtors = $this->manualDebtorRows($dateRange);
        $receivedMode = (bool) preg_match('/\b(received|collected|paid payment|payment)\b/i', $question);
        $outstandingMode = (bool) preg_match('/\b(outstanding|unpaid|overdue|receivable|debtor)\b/i', $question);

        $rows = [];
        if ($receivedMode) {
            foreach (array_merge($invoices, $debtors) as $row) {
                if ((float) $row['paid_amount'] > 0.01) {
                    $rows[] = ['client' => $row['client'], 'value' => (float) $row['paid_amount'], 'count' => 1];
                }
            }
            $valueKey = 'received_value';
            $definition = 'Client contribution is ranked by paid or received amount from invoice and debtor records in the selected date range.';
        } elseif ($outstandingMode) {
            foreach (array_merge($invoices, $debtors) as $row) {
                $outstanding = max(0, (float) $row['value'] - (float) $row['paid_amount']);
                if ($outstanding > 0.01) {
                    $rows[] = ['client' => $row['client'], 'value' => $outstanding, 'count' => 1];
                }
            }
            $valueKey = 'outstanding_value';
            $definition = 'Client contribution is ranked by unpaid or outstanding invoice and debtor balance in the selected date range.';
        } else {
            foreach ($projects as $row) {
                $rows[] = ['client' => $row['client'], 'value' => (float) $row['value'], 'count' => 1];
            }
            if ($rows === []) {
                foreach (array_filter($this->quoteRows($dateRange), fn (array $row): bool => $this->isAwardedStatus($row['status'])) as $row) {
                    $rows[] = ['client' => $row['client'], 'value' => (float) $row['value'], 'count' => 1];
                }
            }
            $valueKey = 'awarded_value';
            $definition = 'Client contribution is ranked by awarded sales value in the selected date range.';
        }

        return new CompanyAnalyticsResult(
            'company_analytics.clients',
            'Company client contribution analytics',
            $definition,
            $dateRange,
            [
                'client_count' => count(array_unique(array_map(fn (array $row): string => (string) $row['client'], $rows))),
                $valueKey => $this->sum($rows, 'value'),
            ],
            [
                'top_clients' => $this->rankRows($rows, 'client', 'value', $valueKey, 'record_count'),
            ],
            array_slice($rows, 0, 10),
            ['show top clients by received payment', 'show unpaid invoices by client', 'show client sales trend'],
            [],
            'high',
            'Company client contribution summary.',
            '/client',
            ['analyzer' => 'CompanyCommercialAnalyticsAnalyzer::clients'],
        );
    }

    public function projects(string $question, array $dateRange): CompanyAnalyticsResult
    {
        $rows = $this->projectRows($dateRange);

        return new CompanyAnalyticsResult(
            'company_analytics.projects',
            'Company project analytics',
            'Project analytics summarize active, closed, terminated, value, status, client, service, and month from commercial project records.',
            $dateRange,
            [
                'project_count' => count($rows),
                'project_value' => $this->sum($rows, 'value'),
                'active_count' => count(array_filter($rows, fn (array $row): bool => $this->isActiveProjectStatus($row['status']))),
                'closed_count' => count(array_filter($rows, fn (array $row): bool => $this->isClosedProjectStatus($row['status']))),
            ],
            [
                'by_status' => $this->countBy($rows, 'status'),
                'by_client' => $this->sumBy($rows, 'client', 'value'),
                'by_service' => $this->sumBy($rows, 'service_type', 'value'),
                'by_month' => $this->countBy($rows, 'month'),
            ],
            array_slice($rows, 0, 10),
            ['show active projects by client', 'show project value by service', 'show fully invoiced projects'],
            [],
            'high',
            'Company project summary.',
            '/project/manage',
            ['analyzer' => 'CompanyCommercialAnalyticsAnalyzer::projects'],
        );
    }

    public function invoices(string $question, array $dateRange): CompanyAnalyticsResult
    {
        $rows = $this->invoiceRows($dateRange);

        return new CompanyAnalyticsResult(
            'company_analytics.invoices',
            'Company invoice analytics',
            'Invoice analytics summarize billed value, paid value, outstanding value, status, client, service, and monthly invoice trends.',
            $dateRange,
            [
                'invoice_count' => count($rows),
                'billed_value' => $this->sum($rows, 'value'),
                'received_value' => $this->sum($rows, 'paid_amount'),
                'outstanding_value' => $this->outstandingSum($rows),
            ],
            [
                'by_status' => $this->countBy($rows, 'status'),
                'by_client' => $this->sumBy($rows, 'client', 'value'),
                'by_service' => $this->sumBy($rows, 'service_type', 'value'),
                'by_month' => $this->sumBy($rows, 'month', 'value'),
            ],
            array_slice($rows, 0, 10),
            ['show unpaid invoices by client', 'show invoice trend by month', 'show received payment by client'],
            [],
            'high',
            'Company invoice summary.',
            '/commercial/invoice',
            ['analyzer' => 'CompanyCommercialAnalyticsAnalyzer::invoices'],
        );
    }

    public function receivables(string $question, array $dateRange): CompanyAnalyticsResult
    {
        $rows = array_merge($this->invoiceRows($dateRange), $this->manualDebtorRows($dateRange));
        $unpaid = array_values(array_filter($rows, fn (array $row): bool => max(0, (float) $row['value'] - (float) $row['paid_amount']) > 0.01));
        foreach ($unpaid as $index => $row) {
            $unpaid[$index]['outstanding'] = max(0, (float) $row['value'] - (float) $row['paid_amount']);
            $unpaid[$index]['aging_bucket'] = $this->agingBucket($row['due_date'] ?? $row['date'] ?? null);
        }

        return new CompanyAnalyticsResult(
            'company_analytics.receivables',
            'Company receivable analytics',
            'Receivable analytics summarize unpaid, overdue, outstanding, aging, received, and collected balances from invoices and manual debtor records.',
            $dateRange,
            [
                'record_count' => count($rows),
                'unpaid_count' => count($unpaid),
                'outstanding_value' => $this->sum($unpaid, 'outstanding'),
                'received_value' => $this->sum($rows, 'paid_amount'),
            ],
            [
                'by_client' => $this->sumBy($unpaid, 'client', 'outstanding'),
                'by_status' => $this->countBy($unpaid, 'status'),
                'aging_buckets' => $this->sumBy($unpaid, 'aging_bucket', 'outstanding'),
                'by_month' => $this->sumBy($unpaid, 'month', 'outstanding'),
            ],
            array_slice($unpaid, 0, 10),
            ['show overdue receivables by client', 'show receivable aging buckets', 'show collected payment by month'],
            [],
            'high',
            'Company receivable summary.',
            '/commercial/debtors',
            ['analyzer' => 'CompanyCommercialAnalyticsAnalyzer::receivables'],
        );
    }

    public function crmInquiries(string $question, array $dateRange): CompanyAnalyticsResult
    {
        $rows = $this->salesInquiryRows($dateRange);
        $linked = count(array_filter($rows, fn (array $row): bool => trim((string) $row['quote_ref']) !== ''));
        $stale = count(array_filter($rows, fn (array $row): bool => $this->isStaleOpen($row['date'], $row['status'])));

        return new CompanyAnalyticsResult(
            'company_analytics.crm_inquiries',
            'Company CRM inquiry analytics',
            'CRM inquiry analytics summarize inquiry status, source, service, owner, linked quotes, and stale open inquiries.',
            $dateRange,
            [
                'inquiry_count' => count($rows),
                'linked_quote_count' => $linked,
                'stale_open_count' => $stale,
            ],
            [
                'by_status' => $this->countBy($rows, 'status'),
                'by_source' => $this->countBy($rows, 'source'),
                'by_service' => $this->countBy($rows, 'service_type'),
                'by_owner' => $this->countBy($rows, 'owner'),
                'by_month' => $this->countBy($rows, 'month'),
            ],
            array_slice($rows, 0, 10),
            ['show stale inquiries', 'show inquiry conversion by source', 'break inquiries down by service'],
            Schema::hasTable('sales_inquiries') ? [] : ['sales_inquiries table'],
            Schema::hasTable('sales_inquiries') ? 'high' : 'low',
            'Company CRM inquiry summary.',
            '/crm/inquiries',
            ['analyzer' => 'CompanyCommercialAnalyticsAnalyzer::crmInquiries'],
        );
    }

    public function vendorCosts(string $question, array $dateRange): CompanyAnalyticsResult
    {
        $rows = array_merge($this->vendorPaymentRows($dateRange), $this->projectExpenseRows($dateRange));

        return new CompanyAnalyticsResult(
            'company_analytics.vendor_costs',
            'Company vendor cost analytics',
            'Vendor cost analytics summarize approved, paid, outstanding vendor payments and project expenses by vendor, project, status, and month.',
            $dateRange,
            [
                'cost_record_count' => count($rows),
                'cost_value' => $this->sum($rows, 'value'),
                'paid_value' => $this->sum($rows, 'paid_amount'),
                'outstanding_value' => $this->outstandingSum($rows),
            ],
            [
                'by_status' => $this->countBy($rows, 'status'),
                'by_vendor' => $this->sumBy($rows, 'vendor', 'value'),
                'by_project' => $this->sumBy($rows, 'project', 'value'),
                'by_month' => $this->sumBy($rows, 'month', 'value'),
            ],
            array_slice($rows, 0, 10),
            ['show vendor costs by project', 'show outstanding vendor payments', 'show costs by month'],
            [],
            'high',
            'Company vendor cost summary.',
            '/vendor/payments',
            ['analyzer' => 'CompanyCommercialAnalyticsAnalyzer::vendorCosts'],
        );
    }

    public function commercialDocuments(string $question, array $dateRange): CompanyAnalyticsResult
    {
        $tables = [
            'do_details' => 'Delivery Order',
            'invoices_jd14form' => 'JD14',
            'supplier_purchase_orders' => 'Supplier PO',
            'vendor_loa' => 'Vendor LOA',
        ];
        $rows = [];
        $missing = [];

        foreach ($tables as $table => $label) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
                continue;
            }
            $query = DB::table($table);
            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            $dateColumn = $this->firstColumn($table, ['created_at', 'date', 'document_date']);
            foreach ($query->get()->all() as $row) {
                $date = $dateColumn ? data_get($row, $dateColumn) : null;
                if ($dateColumn && ! $this->dateRanges->contains($date ? (string) $date : null, $dateRange)) {
                    continue;
                }
                $statusColumn = $this->firstColumn($table, ['status', 'approval_status']);
                $rows[] = [
                    'document_type' => $label,
                    'status' => $this->cleanLabel($statusColumn ? data_get($row, $statusColumn) : null),
                    'date' => $date ? substr((string) $date, 0, 10) : null,
                    'month' => $this->monthKey($date ? (string) $date : null),
                    'value' => 0,
                ];
            }
        }

        return new CompanyAnalyticsResult(
            'company_analytics.commercial_documents',
            'Company commercial document analytics',
            'Commercial document analytics summarize delivery order, JD14, supplier PO, vendor LOA, and related commercial document counts where tables exist.',
            $dateRange,
            [
                'document_count' => count($rows),
            ],
            [
                'by_document_type' => $this->countBy($rows, 'document_type'),
                'by_status' => $this->countBy($rows, 'status'),
                'by_month' => $this->countBy($rows, 'month'),
            ],
            array_slice($rows, 0, 10),
            ['show commercial documents by status', 'show commercial documents by month'],
            $missing,
            $missing === [] ? 'high' : 'medium',
            'Company commercial document summary.',
            '/commercial',
            ['analyzer' => 'CompanyCommercialAnalyticsAnalyzer::commercialDocuments'],
        );
    }

    private function quoteRows(array $dateRange): array
    {
        $rows = [];
        foreach (self::QUOTE_TABLES as $table => $service) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $query = DB::table($table);
            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            if (Schema::hasColumn($table, 'is_deleted')) {
                $query->where(function ($builder): void {
                    $builder->whereNull('is_deleted')->orWhere('is_deleted', false);
                });
            }

            foreach ($query->get()->all() as $row) {
                $date = data_get($row, 'created_at') ? substr((string) data_get($row, 'created_at'), 0, 10) : null;
                if (! $this->dateRanges->contains($date, $dateRange)) {
                    continue;
                }
                $ref = trim((string) data_get($row, 'quote_ref_no'));
                $rows[] = [
                    'table' => $table,
                    'id' => data_get($row, 'id'),
                    'ref' => $ref !== '' ? $ref : $table.'#'.data_get($row, 'id'),
                    'client' => $this->cleanLabel(data_get($row, 'client_name')),
                    'service_type' => $this->cleanLabel(data_get($row, 'service_group') ?: data_get($row, 'service_title') ?: data_get($row, 'training_title') ?: $service),
                    'status' => $this->cleanLabel(data_get($row, 'status')),
                    'value' => (float) (data_get($row, 'grand_total') ?? data_get($row, 'quote_value') ?? 0),
                    'created_by_id' => data_get($row, 'created_by_id'),
                    'created_by_code' => data_get($row, 'created_by_code'),
                    'date' => $date,
                    'month' => $this->monthKey($date),
                ];
            }
        }

        return collect($rows)
            ->sortByDesc('id')
            ->unique('ref')
            ->values()
            ->all();
    }

    private function projectRows(array $dateRange): array
    {
        if (! Schema::hasTable('projects_main')) {
            return [];
        }

        $clientNames = $this->clientNameMap();
        $rows = [];
        foreach (DB::table('projects_main')->get()->all() as $row) {
            $date = data_get($row, 'award_date') ?: data_get($row, 'created_at');
            $date = $date ? substr((string) $date, 0, 10) : null;
            if (! $this->dateRanges->contains($date, $dateRange)) {
                continue;
            }
            $clientId = data_get($row, 'client_id');
            $rows[] = [
                'id' => data_get($row, 'id'),
                'name' => $this->cleanLabel(data_get($row, 'project_name')),
                'client' => $clientNames[(string) $clientId] ?? 'Not recorded',
                'client_id' => $clientId,
                'service_type' => $this->cleanLabel(data_get($row, 'project_type')),
                'status' => $this->cleanLabel(data_get($row, 'status')),
                'value' => (float) (data_get($row, 'quote_value') ?? 0),
                'created_by' => data_get($row, 'created_by'),
                'date' => $date,
                'month' => $this->monthKey($date),
            ];
        }

        return $rows;
    }

    private function invoiceRows(array $dateRange): array
    {
        if (! Schema::hasTable('invoices')) {
            return [];
        }

        $clientNames = $this->clientNameMap();
        $rows = [];
        $query = DB::table('invoices');
        if (Schema::hasColumn('invoices', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        foreach ($query->get()->all() as $row) {
            $date = data_get($row, 'invoice_date') ?: data_get($row, 'created_at');
            $date = $date ? substr((string) $date, 0, 10) : null;
            if (! $this->dateRanges->contains($date, $dateRange)) {
                continue;
            }
            $clientId = data_get($row, 'client_id');
            $value = (float) (data_get($row, 'grand_total') ?? 0);
            $paid = (float) (data_get($row, 'paid_amount') ?? 0);
            $status = $this->cleanLabel(data_get($row, 'status'));
            if ($this->isCancelledStatus($status)) {
                continue;
            }
            if ($paid <= 0 && $this->isPaidStatus($status)) {
                $paid = $value;
            }
            $rows[] = [
                'id' => data_get($row, 'id'),
                'ref' => $this->cleanLabel(data_get($row, 'invoice_ref_no')),
                'client' => $clientNames[(string) $clientId] ?? $this->cleanLabel(data_get($row, 'invoice_client_name')),
                'client_id' => $clientId,
                'service_type' => $this->cleanLabel(data_get($row, 'service_type')),
                'status' => $status,
                'value' => $value,
                'paid_amount' => $paid,
                'due_date' => data_get($row, 'due_date') ? substr((string) data_get($row, 'due_date'), 0, 10) : null,
                'date' => $date,
                'month' => $this->monthKey($date),
            ];
        }

        return $rows;
    }

    private function manualDebtorRows(array $dateRange): array
    {
        if (! Schema::hasTable('manual_debtors')) {
            return [];
        }

        $rows = [];
        $query = DB::table('manual_debtors');
        if (Schema::hasColumn('manual_debtors', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        foreach ($query->get()->all() as $row) {
            $date = data_get($row, 'invoice_date') ?: data_get($row, 'created_at');
            $date = $date ? substr((string) $date, 0, 10) : null;
            if (! $this->dateRanges->contains($date, $dateRange)) {
                continue;
            }
            $value = (float) (data_get($row, 'grand_total') ?? 0);
            $paid = (float) (data_get($row, 'paid_amount') ?? 0);
            $status = $this->cleanLabel(data_get($row, 'status'));
            if ($this->isCancelledStatus($status)) {
                continue;
            }
            if ($paid <= 0 && $this->isPaidStatus($status)) {
                $paid = $value;
            }
            $rows[] = [
                'id' => data_get($row, 'id'),
                'ref' => $this->cleanLabel(data_get($row, 'invoice_ref_no')),
                'client' => $this->cleanLabel(data_get($row, 'client_name')),
                'client_id' => data_get($row, 'client_id'),
                'service_type' => $this->cleanLabel(data_get($row, 'service_type')),
                'status' => $status,
                'value' => $value,
                'paid_amount' => $paid,
                'due_date' => data_get($row, 'due_date') ? substr((string) data_get($row, 'due_date'), 0, 10) : null,
                'date' => $date,
                'month' => $this->monthKey($date),
            ];
        }

        return $rows;
    }

    private function salesInquiryRows(array $dateRange): array
    {
        if (! Schema::hasTable('sales_inquiries')) {
            return [];
        }

        $rows = [];
        $query = DB::table('sales_inquiries');
        if (Schema::hasColumn('sales_inquiries', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        foreach ($query->get()->all() as $row) {
            $date = data_get($row, 'inquiry_date') ?: data_get($row, 'created_at');
            $date = $date ? substr((string) $date, 0, 10) : null;
            if (! $this->dateRanges->contains($date, $dateRange)) {
                continue;
            }
            $rows[] = [
                'company' => $this->cleanLabel(data_get($row, 'client_name') ?: data_get($row, 'company_name')),
                'service_type' => $this->cleanLabel(data_get($row, 'service_required') ?: data_get($row, 'quote_service_type')),
                'source' => $this->cleanLabel(data_get($row, 'source')),
                'status' => $this->cleanLabel(data_get($row, 'status')),
                'owner' => $this->cleanLabel(data_get($row, 'owner_staff_name') ?: data_get($row, 'owner_staff_code') ?: data_get($row, 'created_by_code')),
                'quote_ref' => $this->cleanLabel(data_get($row, 'quote_ref_no')),
                'date' => $date,
                'month' => $this->monthKey($date),
            ];
        }

        return $rows;
    }

    private function vendorPaymentRows(array $dateRange): array
    {
        if (! Schema::hasTable('vendor_payments')) {
            return [];
        }

        $vendorNames = $this->vendorNameMap();
        $projectNames = $this->projectNameMap();
        $query = DB::table('vendor_payments');
        if (Schema::hasColumn('vendor_payments', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        $rows = [];
        foreach ($query->get()->all() as $row) {
            $date = data_get($row, 'date_approved') ?: data_get($row, 'paid_date') ?: data_get($row, 'created_at');
            $date = $date ? substr((string) $date, 0, 10) : null;
            if (! $this->dateRanges->contains($date, $dateRange)) {
                continue;
            }
            $vendorId = data_get($row, 'vendor_id');
            $projectId = data_get($row, 'project_id');
            $rows[] = [
                'vendor' => $vendorNames[(string) $vendorId] ?? 'Not recorded',
                'project' => $projectNames[(string) $projectId] ?? 'Not recorded',
                'status' => $this->cleanLabel(data_get($row, 'status')),
                'value' => (float) (data_get($row, 'amount') ?? 0),
                'paid_amount' => (float) (data_get($row, 'paid_amount') ?? 0),
                'date' => $date,
                'month' => $this->monthKey($date),
            ];
        }

        return $rows;
    }

    private function projectExpenseRows(array $dateRange): array
    {
        if (! Schema::hasTable('project_expenses')) {
            return [];
        }

        $projectNames = $this->projectNameMap();
        $rows = [];
        foreach (DB::table('project_expenses')->get()->all() as $row) {
            $date = data_get($row, 'date') ?: data_get($row, 'created_at');
            $date = $date ? substr((string) $date, 0, 10) : null;
            if (! $this->dateRanges->contains($date, $dateRange)) {
                continue;
            }
            $projectId = data_get($row, 'project_id');
            $value = (float) (data_get($row, 'amount') ?? 0);
            $rows[] = [
                'vendor' => 'Project expense',
                'project' => $projectNames[(string) $projectId] ?? 'Not recorded',
                'status' => 'Recorded',
                'value' => $value,
                'paid_amount' => $value,
                'date' => $date,
                'month' => $this->monthKey($date),
            ];
        }

        return $rows;
    }

    private function staffNameMap(): array
    {
        if (! Schema::hasTable('staff_general')) {
            return [];
        }

        $map = [];
        foreach (DB::table('staff_general')->get(['staff_id', 'full_name', 'name_code'])->all() as $staff) {
            $name = trim((string) (data_get($staff, 'full_name') ?: data_get($staff, 'name_code') ?: 'Staff '.data_get($staff, 'staff_id')));
            $map[(string) data_get($staff, 'staff_id')] = $name;
            if (data_get($staff, 'name_code')) {
                $map[(string) data_get($staff, 'name_code')] = $name;
            }
        }

        return $map;
    }

    private function clientNameMap(): array
    {
        if (! Schema::hasTable('client_company')) {
            return [];
        }

        return DB::table('client_company')
            ->pluck('company_name', 'company_id')
            ->map(fn ($value): string => $this->cleanLabel($value))
            ->all();
    }

    private function vendorNameMap(): array
    {
        if (! Schema::hasTable('vendor_main_details')) {
            return [];
        }

        return DB::table('vendor_main_details')
            ->pluck('vendor_name', 'vendor_id')
            ->map(fn ($value): string => $this->cleanLabel($value))
            ->all();
    }

    private function projectNameMap(): array
    {
        if (! Schema::hasTable('projects_main')) {
            return [];
        }

        return DB::table('projects_main')
            ->pluck('project_name', 'id')
            ->map(fn ($value): string => $this->cleanLabel($value))
            ->all();
    }

    private function rankRows(array $rows, string $groupKey, string $valueKey, string $outputValueKey, string $outputCountKey): array
    {
        $ranked = [];
        foreach ($rows as $row) {
            $label = $this->cleanLabel($row[$groupKey] ?? null);
            $ranked[$label] ??= ['name' => $label, $outputValueKey => 0.0, $outputCountKey => 0];
            $ranked[$label][$outputValueKey] += (float) ($row[$valueKey] ?? 0);
            $ranked[$label][$outputCountKey]++;
        }

        usort($ranked, fn (array $a, array $b): int => ((float) $b[$outputValueKey]) <=> ((float) $a[$outputValueKey]));

        return array_values($ranked);
    }

    private function countBy(array $rows, string $key): array
    {
        $result = [];
        foreach ($rows as $row) {
            $label = $this->cleanLabel($row[$key] ?? null);
            $result[$label] = ($result[$label] ?? 0) + 1;
        }
        arsort($result);

        return $result;
    }

    private function sumBy(array $rows, string $groupKey, string $valueKey): array
    {
        $result = [];
        foreach ($rows as $row) {
            $label = $this->cleanLabel($row[$groupKey] ?? null);
            $result[$label] = ($result[$label] ?? 0) + (float) ($row[$valueKey] ?? 0);
        }
        arsort($result);

        return $result;
    }

    private function sum(array $rows, string $key): float
    {
        return array_reduce($rows, fn (float $carry, array $row): float => $carry + (float) ($row[$key] ?? 0), 0.0);
    }

    private function outstandingSum(array $rows): float
    {
        return array_reduce($rows, fn (float $carry, array $row): float => $carry + max(0, (float) ($row['value'] ?? 0) - (float) ($row['paid_amount'] ?? 0)), 0.0);
    }

    private function cleanLabel(mixed $value): string
    {
        $label = trim((string) $value);

        return $label === '' ? 'Not recorded' : $label;
    }

    private function monthKey(?string $date): string
    {
        if (! $date) {
            return 'Not recorded';
        }

        try {
            return Carbon::parse($date)->format('Y-m');
        } catch (\Throwable) {
            return 'Not recorded';
        }
    }

    private function agingBucket(?string $date): string
    {
        if (! $date) {
            return 'No due date';
        }

        try {
            $days = max(0, Carbon::parse($date)->diffInDays(Carbon::today(), false));
        } catch (\Throwable) {
            return 'No due date';
        }

        return match (true) {
            $days <= 30 => '0-30 days',
            $days <= 60 => '31-60 days',
            $days <= 90 => '61-90 days',
            default => '90+ days',
        };
    }

    private function firstColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function isAwardedStatus(?string $status): bool
    {
        return (bool) preg_match('/\b(awarded|won|accepted|approved)\b/i', (string) $status);
    }

    private function isFailedStatus(?string $status): bool
    {
        return (bool) preg_match('/\b(failed|lost|rejected)\b/i', (string) $status);
    }

    private function isCancelledStatus(?string $status): bool
    {
        return (bool) preg_match('/\b(cancelled|canceled|void|deleted)\b/i', (string) $status);
    }

    private function isAwardedProjectStatus(?string $status): bool
    {
        return (bool) preg_match('/\b(active|ongoing|in progress|completed|closed|awarded|won|delivered)\b/i', (string) $status);
    }

    private function isActiveProjectStatus(?string $status): bool
    {
        return (bool) preg_match('/\b(active|ongoing|in progress|open)\b/i', (string) $status);
    }

    private function isClosedProjectStatus(?string $status): bool
    {
        return (bool) preg_match('/\b(closed|completed|done)\b/i', (string) $status);
    }

    private function isPaidStatus(?string $status): bool
    {
        return (bool) preg_match('/\b(paid|settled|received|collected)\b/i', (string) $status);
    }

    private function isStaleOpen(?string $date, ?string $status): bool
    {
        if (! $date || preg_match('/\b(closed|converted|won|lost|cancelled|canceled)\b/i', (string) $status)) {
            return false;
        }

        try {
            return Carbon::parse($date)->lt(Carbon::today()->subDays(30));
        } catch (\Throwable) {
            return false;
        }
    }
}
