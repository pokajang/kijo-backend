<?php

namespace App\Services\Assistant\UserTrace;

class AssistantUserTraceMetricCatalog
{
    public function entries(): array
    {
        return [
            'quote' => [
                'metric_key' => 'user_trace.quote_issued',
                'domain' => 'quote',
                'analyzer' => 'quote',
                'default_range' => 'current_year',
                'route' => '/crm/quotes',
                'synonyms' => [
                    'quote', 'quotes', 'quotation', 'quotations', 'sebut harga', 'sebutharga',
                    'kuotasi', 'sales', 'crm',
                ],
                'metric_terms' => [
                    'how many', 'berapa', 'count', 'total', 'issued', 'created', 'won', 'awarded',
                    'failed', 'lost', 'open', 'break down', 'breakdown', 'by month', 'by status',
                    'by client', 'by service', 'trend', 'my quotation', 'my quotations', 'quotation saya',
                ],
                'safe_fields' => ['count', 'value', 'month', 'status', 'service_type', 'client_name', 'quote_ref_no'],
            ],
            'leave' => [
                'metric_key' => 'user_trace.leave_taken',
                'domain' => 'leave',
                'analyzer' => 'leave',
                'default_range' => 'current_year',
                'route' => '/my/leaves',
                'synonyms' => [
                    'leave', 'cuti', 'entitlement', 'balance', 'baki', 'annual leave',
                    'medical leave', 'permohonan cuti',
                ],
                'metric_terms' => [
                    'status', 'how many', 'berapa', 'taken', 'ambil', 'sudah ambil', 'pending',
                    'approved', 'rejected', 'cancelled', 'remaining', 'entitlement', 'balance',
                    'baki', 'by month', 'by type', 'by status', 'my leave', 'cuti saya',
                ],
                'safe_fields' => ['taken_days', 'pending_count', 'status', 'leave_type', 'month', 'entitlement'],
            ],
            'kpi' => [
                'metric_key' => 'user_trace.kpi_status',
                'domain' => 'kpi',
                'analyzer' => 'kpi',
                'default_range' => 'current_year',
                'route' => '/staff/appraise',
                'synonyms' => [
                    'kpi', 'appraisal', 'performance', 'feedback', 'review', 'score',
                    'improve', 'improvement', 'perbaiki', 'tingkat',
                ],
                'metric_terms' => [
                    'status', 'latest', 'score', 'period', 'feedback', 'how can i improve',
                    'improve further', 'better', 'weak', 'criteria',
                ],
                'safe_fields' => ['status', 'period', 'score', 'feedback_excerpt'],
            ],
            'employment' => [
                'metric_key' => 'user_trace.employment_tenure',
                'domain' => 'employment',
                'analyzer' => 'employment',
                'default_range' => 'all_time',
                'route' => '/my/profile',
                'synonyms' => [
                    'tenure', 'spent here', 'worked here', 'working here', 'been here',
                    'been with company', 'been with this company', 'joined', 'join date',
                    'profile', 'position', 'department', 'lama kerja', 'kerja sini',
                ],
                'metric_terms' => [
                    'how long', 'how many years', 'years', 'tenure', 'worked here',
                    'been here', 'joined', 'join date', 'berapa lama', 'mula kerja',
                    'department', 'position', 'profile',
                ],
                'safe_fields' => ['join_date', 'join_date_source', 'department', 'position', 'status', 'tenure'],
            ],
            'task' => [
                'metric_key' => 'user_trace.task_status',
                'domain' => 'task',
                'analyzer' => 'task',
                'default_range' => 'current_year',
                'route' => '/task-manager',
                'synonyms' => ['task', 'tasks', 'workload', 'todo', 'assigned', 'tugasan'],
                'metric_terms' => [
                    'how many', 'berapa', 'count', 'status', 'open', 'pending', 'completed',
                    'overdue', 'break down', 'by status', 'by category', 'by project',
                ],
                'safe_fields' => ['count', 'status', 'category', 'due_date', 'title'],
            ],
            'project' => [
                'metric_key' => 'user_trace.project_status',
                'domain' => 'project',
                'analyzer' => null,
                'default_range' => 'current_year',
                'route' => '/project/manage',
                'synonyms' => ['project', 'projects', 'projek'],
                'metric_terms' => ['status', 'involved', 'assigned', 'progress'],
                'safe_fields' => [],
                'unsupported_reason' => 'Project self-trace is not enabled yet because ownership rules need a dedicated analyzer.',
            ],
            'sales_activity' => [
                'metric_key' => 'user_trace.sales_activity',
                'domain' => 'sales_activity',
                'analyzer' => null,
                'default_range' => 'current_year',
                'route' => '/dashboard/sales',
                'synonyms' => ['sales activity', 'inquiries', 'follow ups', 'calls', 'meetings'],
                'metric_terms' => ['handled', 'made', 'follow up', 'activity'],
                'safe_fields' => [],
                'unsupported_reason' => 'Sales activity self-trace is not enabled yet because activity ownership rules need a dedicated analyzer.',
            ],
            'invoice' => [
                'metric_key' => 'user_trace.invoice_payment',
                'domain' => 'invoice',
                'analyzer' => null,
                'default_range' => 'current_year',
                'route' => '/commercial/invoice',
                'synonyms' => ['invoice', 'invoices', 'payment', 'paid', 'unpaid', 'invois'],
                'metric_terms' => ['status', 'paid', 'unpaid', 'created', 'issued'],
                'safe_fields' => [],
                'unsupported_reason' => 'Invoice/payment self-trace is not enabled yet because user ownership rules need a dedicated analyzer.',
            ],
            'salary' => [
                'metric_key' => 'user_trace.salary_private',
                'domain' => 'salary',
                'analyzer' => null,
                'default_range' => 'current_year',
                'route' => '/my/salary',
                'synonyms' => ['salary', 'gaji', 'payslip', 'pay slip', 'slip gaji', 'net pay', 'basic salary'],
                'metric_terms' => ['amount', 'how much', 'berapa', 'status', 'latest', 'salary', 'gaji', 'payslip', 'slip gaji'],
                'safe_fields' => [],
                'unsupported_reason' => 'Salary self-trace is not enabled in this assistant phase. Use the authorized salary module for salary records.',
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

    public function supportedDomains(): array
    {
        return array_values(array_filter(array_keys($this->entries()), fn (string $domain): bool => ! empty($this->entries()[$domain]['analyzer'])));
    }
}
