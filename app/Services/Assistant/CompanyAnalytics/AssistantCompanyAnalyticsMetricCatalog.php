<?php

namespace App\Services\Assistant\CompanyAnalytics;

class AssistantCompanyAnalyticsMetricCatalog
{
    public function entries(): array
    {
        return [
            'sales' => [
                'metric_key' => 'company_analytics.sales',
                'domain' => 'sales',
                'analyzer' => 'sales',
                'route' => '/dashboard/sales',
                'definition' => 'Sales means awarded or closed project value, falling back to awarded quotation value when project award data is unavailable.',
                'synonyms' => ['sales', 'sale', 'sold', 'awarded', 'closed won', 'won', 'revenue won'],
                'metrics' => ['top', 'count', 'sum', 'trend', 'breakdown', 'comparison', 'summary'],
            ],
            'quotes' => [
                'metric_key' => 'company_analytics.quotes',
                'domain' => 'quotes',
                'analyzer' => 'quotes',
                'route' => '/crm/quotes',
                'definition' => 'Quote analytics count saved quotation records once per latest reference, with awarded, failed, open, quoted value, and conversion summaries.',
                'synonyms' => ['quote', 'quotes', 'quotation', 'quotations', 'quoted value', 'conversion', 'win rate', 'pipeline', 'sebut harga', 'kuotasi'],
                'metrics' => ['count', 'sum', 'status', 'trend', 'breakdown', 'conversion', 'pipeline'],
            ],
            'crm_inquiries' => [
                'metric_key' => 'company_analytics.crm_inquiries',
                'domain' => 'crm_inquiries',
                'analyzer' => 'crm_inquiries',
                'route' => '/crm/inquiries',
                'definition' => 'CRM inquiry analytics summarize inquiry status, source, service, owner, linked quotes, and stale open inquiries.',
                'synonyms' => ['crm', 'inquiry', 'inquiries', 'lead', 'leads', 'source', 'follow up', 'prospect'],
                'metrics' => ['count', 'status', 'trend', 'breakdown', 'conversion', 'pipeline'],
            ],
            'clients' => [
                'metric_key' => 'company_analytics.clients',
                'domain' => 'clients',
                'analyzer' => 'clients',
                'route' => '/client',
                'definition' => 'Client contribution ranks clients by awarded sales value, invoiced value, received payment, outstanding balance, profit, or ROI depending on the question.',
                'synonyms' => ['client', 'clients', 'customer', 'customers', 'pelanggan', 'contributes', 'contribution', 'top client', 'most sales'],
                'metrics' => ['top', 'sum', 'comparison', 'profitability', 'margin', 'roi', 'aging'],
            ],
            'projects' => [
                'metric_key' => 'company_analytics.projects',
                'domain' => 'projects',
                'analyzer' => 'projects',
                'route' => '/project/manage',
                'definition' => 'Project analytics summarize active, closed, terminated, value, status, and invoicing coverage from commercial project records.',
                'synonyms' => ['project', 'projects', 'projek', 'active project', 'closed project', 'terminated project', 'progress'],
                'metrics' => ['count', 'sum', 'status', 'trend', 'breakdown', 'pipeline'],
            ],
            'invoices' => [
                'metric_key' => 'company_analytics.invoices',
                'domain' => 'invoices',
                'analyzer' => 'invoices',
                'route' => '/commercial/invoice',
                'definition' => 'Invoice analytics summarize billed value, paid value, outstanding value, status, client, service, and monthly invoice trends.',
                'synonyms' => ['invoice', 'invoices', 'billed', 'billing', 'revenue', 'invoiced', 'invois'],
                'metrics' => ['count', 'sum', 'status', 'trend', 'breakdown'],
            ],
            'receivables' => [
                'metric_key' => 'company_analytics.receivables',
                'domain' => 'receivables',
                'analyzer' => 'receivables',
                'route' => '/commercial/debtors',
                'definition' => 'Receivable analytics summarize unpaid, overdue, outstanding, aging, received, and collected balances from invoices and manual debtor records.',
                'synonyms' => ['receivable', 'receivables', 'outstanding', 'unpaid', 'overdue', 'aging', 'received', 'collected', 'paid payment', 'payment received', 'belum bayar', 'tertunggak', 'bayaran diterima', 'kutipan'],
                'metrics' => ['sum', 'aging', 'status', 'trend', 'breakdown'],
            ],
            'debtors' => [
                'metric_key' => 'company_analytics.debtors',
                'domain' => 'debtors',
                'analyzer' => 'receivables',
                'route' => '/commercial/debtors',
                'definition' => 'Debtor analytics summarize unpaid manual debtor and invoice balances by client, status, and aging bucket.',
                'synonyms' => ['debtor', 'debtors', 'manual debtor', 'hutang'],
                'metrics' => ['sum', 'aging', 'status', 'breakdown'],
            ],
            'vendor_costs' => [
                'metric_key' => 'company_analytics.vendor_costs',
                'domain' => 'vendor_costs',
                'analyzer' => 'vendor_costs',
                'route' => '/vendor/payments',
                'definition' => 'Vendor cost analytics summarize approved, paid, outstanding vendor payments and project expenses by vendor, project, status, and month.',
                'synonyms' => ['vendor cost', 'vendor costs', 'vendor payment', 'vendor payments', 'project expenses', 'expenses', 'cost', 'costs', 'supplier cost'],
                'metrics' => ['sum', 'status', 'trend', 'breakdown', 'profitability', 'margin'],
            ],
            'commercial_documents' => [
                'metric_key' => 'company_analytics.commercial_documents',
                'domain' => 'commercial_documents',
                'analyzer' => 'commercial_documents',
                'route' => '/commercial',
                'definition' => 'Commercial document analytics summarize delivery order, JD14, supplier PO, vendor LOA, and related commercial document counts where tables exist.',
                'synonyms' => ['commercial document', 'commercial documents', 'delivery order', 'do', 'jd14', 'supplier po', 'purchase order', 'vendor loa', 'loa'],
                'metrics' => ['count', 'status', 'breakdown'],
            ],
        ];
    }

    public function entry(?string $domain): ?array
    {
        if ($domain === null) {
            return null;
        }

        return $this->entries()[$domain] ?? null;
    }
}
