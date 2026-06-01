<?php

namespace App\Services\Assistant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AssistantDetailContextBuilder
{
    private const LINK_LIMIT = 8;

    public function __construct(private readonly AssistantContextSanitizer $sanitizer) {}

    public function matchRoute(string $currentRoute, Request $request): ?array
    {
        $path = $this->routePath($currentRoute);
        $query = $this->routeQuery($currentRoute);
        if ($path === '') {
            return null;
        }

        foreach ($this->specs() as $spec) {
            $matches = [];
            if (isset($spec['query_param'])) {
                if (! preg_match($spec['pattern'], $path)) {
                    continue;
                }

                $id = (int) ($query[$spec['query_param']] ?? 0);
            } else {
                if (! preg_match($spec['pattern'], $path, $matches)) {
                    continue;
                }

                $id = (int) ($matches['id'] ?? $matches[1] ?? 0);
            }

            if (! Schema::hasTable($spec['table'])) {
                continue;
            }

            if ($id <= 0) {
                continue;
            }

            if (! $this->canAccess($spec, $request)) {
                continue;
            }

            return ['spec' => $spec, 'id' => $id, 'path' => $path];
        }

        return null;
    }

    public function build(array $match, Request $request): ?array
    {
        $spec = $match['spec'];
        $id = (int) $match['id'];
        $idColumn = $spec['id_column'] ?? 'id';

        if (! Schema::hasColumn($spec['table'], $idColumn)) {
            return null;
        }

        $query = DB::table($spec['table'])->where($idColumn, $id);
        if (Schema::hasColumn($spec['table'], 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if (in_array(($spec['scope'] ?? null), ['self', 'self_or_roles'], true) && ! $this->hasRoleAccess($spec, $request)) {
            $staffId = (int) $request->session()->get('staff_id');
            $selfColumn = $spec['self_column'] ?? 'staff_id';
            if (! Schema::hasColumn($spec['table'], $selfColumn) || $staffId <= 0) {
                return null;
            }
            $query->where($selfColumn, $staffId);
        }

        $record = $query->first();
        if (! $record) {
            return null;
        }

        $recordArray = $this->objectToArray($record);
        $payload = [
            'record_type' => $spec['type'],
            'record_id' => $id,
            'detail' => $recordArray,
        ];

        $links = $this->linkedRows($spec, $recordArray);
        if ($links !== []) {
            $payload['linked_records'] = $links;
        }

        $payload = $this->sanitizer->detail($payload);

        return [
            'slug' => $spec['type'].':detail:'.$id,
            'source_type' => $spec['source_type'] ?? $spec['type'],
            'title' => $this->title($spec, $recordArray, $id),
            'route' => str_replace('{id}', (string) $id, $spec['route']),
            'payload' => $payload,
            'category' => $spec['category'] ?? 'Record detail',
            'score' => $spec['score'] ?? 520,
            'source_status' => $this->statusFor($recordArray),
            'source_is_deleted' => $this->deletedState($recordArray),
        ];
    }

    private function statusFor(array $record): ?string
    {
        foreach (['status', 'stage', 'state', 'approval_status', 'payment_status'] as $key) {
            $value = trim((string) ($record[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function deletedState(array $record): ?bool
    {
        if (array_key_exists('is_deleted', $record)) {
            return (bool) $record['is_deleted'];
        }
        if (array_key_exists('deleted_at', $record)) {
            return $record['deleted_at'] !== null && trim((string) $record['deleted_at']) !== '';
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function specs(): array
    {
        return [
            [
                'type' => 'project',
                'pattern' => '~^/project/manage/(?<id>\d+)(?:/|$)~',
                'table' => 'projects_main',
                'route' => '/project/manage/{id}',
                'category' => 'Projects',
                'title_columns' => ['project_name', 'po_loa_number'],
                'links' => [
                    ['name' => 'progress', 'table' => 'project_progress', 'foreign_key' => 'project_id', 'order' => ['progress_date', 'id']],
                    ['name' => 'collaborators', 'table' => 'project_collaborators', 'foreign_key' => 'project_id'],
                    ['name' => 'vendors', 'table' => 'project_vendors', 'foreign_key' => 'project_id'],
                    ['name' => 'expenses', 'table' => 'project_expenses', 'foreign_key' => 'project_id', 'order' => ['date', 'id']],
                    ['name' => 'closing_details', 'table' => 'project_closing_details', 'foreign_key' => 'project_id'],
                ],
                'belongs_to' => [
                    ['name' => 'client', 'table' => 'client_company', 'local_key' => 'client_id', 'foreign_key' => 'company_id', 'route' => '/client/manage/{id}', 'title_columns' => ['company_name']],
                ],
            ],
            [
                'type' => 'client',
                'pattern' => '~^/client/(?:manage|roi)/(?<id>\d+)(?:/|$)~',
                'table' => 'client_company',
                'id_column' => 'company_id',
                'route' => '/client/manage/{id}',
                'category' => 'Clients',
                'title_columns' => ['company_name'],
                'links' => [
                    ['name' => 'branches', 'table' => 'client_company_branch', 'foreign_key' => 'company_id', 'local_key' => 'company_id'],
                    ['name' => 'contacts', 'table' => 'client_pic', 'foreign_key' => 'company_id', 'local_key' => 'company_id'],
                    ['name' => 'projects', 'table' => 'projects_main', 'foreign_key' => 'client_id', 'local_key' => 'company_id', 'order' => ['id']],
                    ['name' => 'invoices', 'table' => 'invoices', 'foreign_key' => 'client_id', 'local_key' => 'company_id', 'order' => ['invoice_date', 'id']],
                ],
            ],
            [
                'type' => 'vendor_registration',
                'pattern' => '~^/client/vendor-registration/(?<id>\d+)(?:/|$)~',
                'table' => 'client_vendor_registrations',
                'route' => '/client/vendor-registration/{id}',
                'category' => 'Client vendor registrations',
                'title_columns' => ['portal_url', 'portal_username'],
                'links' => [
                    ['name' => 'recipients', 'table' => 'client_vendor_registration_recipients', 'foreign_key' => 'registration_id'],
                ],
                'belongs_to' => [
                    ['name' => 'client', 'table' => 'client_company', 'local_key' => 'client_id', 'foreign_key' => 'company_id', 'route' => '/client/manage/{id}', 'title_columns' => ['company_name']],
                ],
            ],
            [
                'type' => 'catalog',
                'pattern' => '~^/catalog/manage/(?<id>\d+)(?:/|$)~',
                'table' => 'catalog_items',
                'route' => '/catalog/manage/{id}',
                'category' => 'Catalog',
                'title_columns' => ['item_name', 'supplier_name'],
            ],
            [
                'type' => 'invoice',
                'pattern' => '~^/commercial/invoice/(?<id>\d+)(?:/|$)~',
                'table' => 'invoices',
                'route' => '/commercial/invoice/{id}',
                'category' => 'Invoices',
                'title_columns' => ['invoice_ref_no', 'invoice_client_name'],
                'links' => [
                    ['name' => 'breakdown', 'table' => 'invoice_breakdown', 'foreign_key' => 'invoice_id', 'order' => ['sort_order', 'id']],
                ],
                'belongs_to' => [
                    ['name' => 'project', 'table' => 'projects_main', 'local_key' => 'project_id', 'foreign_key' => 'id', 'route' => '/project/manage/{id}', 'title_columns' => ['project_name']],
                    ['name' => 'client', 'table' => 'client_company', 'local_key' => 'client_id', 'foreign_key' => 'company_id', 'route' => '/client/manage/{id}', 'title_columns' => ['company_name']],
                ],
            ],
            [
                'type' => 'debtor',
                'pattern' => '~^/commercial/debtors/manual/(?<id>\d+)(?:/|$)~',
                'table' => 'manual_debtors',
                'route' => '/commercial/debtors/manual/{id}/edit',
                'category' => 'Debtors',
                'title_columns' => ['invoice_ref_no', 'client_name'],
                'belongs_to' => [
                    ['name' => 'client', 'table' => 'client_company', 'local_key' => 'client_id', 'foreign_key' => 'company_id', 'route' => '/client/manage/{id}', 'title_columns' => ['company_name']],
                ],
            ],
            [
                'type' => 'sales_inquiry',
                'pattern' => '~^/pipeline/inquiries/(?<id>\d+)(?:/|$)~',
                'table' => 'sales_inquiries',
                'route' => '/pipeline/inquiries/{id}',
                'category' => 'Sales inquiries',
                'title_columns' => ['company_name', 'quote_ref_no'],
                'links' => [
                    ['name' => 'proofs', 'table' => 'sales_inquiry_proofs', 'foreign_key' => 'sales_inquiry_id', 'order' => ['sort_order', 'id']],
                ],
                'belongs_to' => [
                    ['name' => 'client', 'table' => 'client_company', 'local_key' => 'client_id', 'foreign_key' => 'company_id', 'route' => '/client/manage/{id}', 'title_columns' => ['company_name']],
                ],
            ],
            [
                'type' => 'pipeline_entry',
                'source_type' => 'sales_inquiry',
                'pattern' => '~^/pipeline/entries/(?<id>\d+)(?:/|$)~',
                'table' => 'monitoring_manual_pipeline_entries',
                'route' => '/pipeline/entries/{id}',
                'category' => 'Pipeline',
                'title_columns' => ['company_name', 'title', 'status'],
            ],
            [
                'type' => 'call_record',
                'source_type' => 'sales_inquiry',
                'pattern' => '~^/pipeline/call-records/(?<id>\d+)(?:/|$)~',
                'table' => 'google_call_records',
                'route' => '/pipeline/call-records/{id}',
                'category' => 'Call records',
                'title_columns' => ['caller_name', 'phone_number', 'direction'],
            ],
            [
                'type' => 'vendor_payment',
                'source_type' => 'vendor',
                'pattern' => '~^/vendor/(?:pay/history|payment-records)/(?<id>\d+)(?:/|$)~',
                'table' => 'vendor_payments',
                'route' => '/vendor/payment-records/{id}',
                'category' => 'Vendor payments',
                'title_columns' => ['payment_context', 'status'],
                'belongs_to' => [
                    ['name' => 'vendor', 'table' => 'vendor_main_details', 'local_key' => 'vendor_id', 'foreign_key' => 'vendor_id', 'route' => '/vendor/frozen/{id}', 'title_columns' => ['vendor_name']],
                    ['name' => 'project', 'table' => 'projects_main', 'local_key' => 'project_id', 'foreign_key' => 'id', 'route' => '/project/manage/{id}', 'title_columns' => ['project_name']],
                ],
            ],
            [
                'type' => 'vendor',
                'pattern' => '~^/vendor/(?:manage/frozen|frozen|paid)/(?<id>\d+)(?:/|$)~',
                'table' => 'vendor_main_details',
                'id_column' => 'vendor_id',
                'route' => '/vendor/frozen/{id}',
                'category' => 'Vendors',
                'title_columns' => ['vendor_name'],
                'links' => [
                    ['name' => 'categories', 'table' => 'vendor_categories', 'foreign_key' => 'vendor_id', 'local_key' => 'vendor_id'],
                    ['name' => 'payments', 'table' => 'vendor_payments', 'foreign_key' => 'vendor_id', 'local_key' => 'vendor_id', 'order' => ['id']],
                ],
            ],
            [
                'type' => 'delivery_order',
                'pattern' => '~^/commercial/delivery-order/(?<id>\d+)(?:/|$)~',
                'table' => 'do_details',
                'route' => '/commercial/delivery-order/{id}',
                'category' => 'Delivery orders',
                'title_columns' => ['do_ref_no', 'delivery_order_no', 'client_name'],
                'belongs_to' => [
                    ['name' => 'project', 'table' => 'projects_main', 'local_key' => 'project_id', 'foreign_key' => 'id', 'route' => '/project/manage/{id}', 'title_columns' => ['project_name']],
                ],
            ],
            [
                'type' => 'jd14',
                'pattern' => '~^/commercial/jd14/(?<id>\d+)(?:/|$)~',
                'table' => 'invoices_jd14form',
                'route' => '/commercial/jd14/{id}',
                'category' => 'JD14',
                'title_columns' => ['approval_no', 'employer_name'],
                'belongs_to' => [
                    ['name' => 'project', 'table' => 'projects_main', 'local_key' => 'project_id', 'foreign_key' => 'id', 'route' => '/project/manage/{id}', 'title_columns' => ['project_name']],
                ],
            ],
            [
                'type' => 'vendor_loa',
                'source_type' => 'vendor',
                'pattern' => '~^/commercial/vendor-loa/(?<id>\d+)(?:/|$)~',
                'table' => 'vendor_payments',
                'route' => '/commercial/vendor-loa/{id}',
                'category' => 'Vendor LOA',
                'title_columns' => ['payment_context', 'status'],
                'belongs_to' => [
                    ['name' => 'vendor', 'table' => 'vendor_main_details', 'local_key' => 'vendor_id', 'foreign_key' => 'vendor_id', 'route' => '/vendor/frozen/{id}', 'title_columns' => ['vendor_name']],
                    ['name' => 'project', 'table' => 'projects_main', 'local_key' => 'project_id', 'foreign_key' => 'id', 'route' => '/project/manage/{id}', 'title_columns' => ['project_name']],
                ],
            ],
            [
                'type' => 'supplier_po',
                'source_type' => 'purchase_order',
                'pattern' => '~^/commercial/supplier-po/(?<id>\d+)(?:/|$)~',
                'table' => 'supplier_po_main',
                'id_column' => 'po_id',
                'route' => '/commercial/supplier-po/{id}',
                'category' => 'Supplier purchase orders',
                'title_columns' => ['po_ref_no', 'supplier_name'],
                'links' => [
                    ['name' => 'items', 'table' => 'supplier_po_items', 'foreign_key' => 'po_id', 'local_key' => 'po_id'],
                ],
            ],
            [
                'type' => 'meeting',
                'pattern' => '~^/administration/meetings/(?:view|edit)/(?<id>\d+)(?:/|$)~',
                'table' => 'meeting_minutes',
                'route' => '/administration/meetings/view/{id}',
                'category' => 'Meetings',
                'title_columns' => ['meeting_title', 'title', 'status'],
                'links' => [
                    ['name' => 'attendees', 'table' => 'meeting_attendees', 'foreign_key' => 'meeting_id'],
                    ['name' => 'action_items', 'table' => 'meeting_action_items', 'foreign_key' => 'meeting_id'],
                    ['name' => 'comments', 'table' => 'meeting_comments', 'foreign_key' => 'meeting_id'],
                ],
            ],
            [
                'type' => 'procedure',
                'pattern' => '~^/(?:administration/procedures|procedures|procedure)(?:/(?:view|edit))?/(?<id>\d+)(?:/|$)~',
                'table' => 'procedures',
                'route' => '/procedures/{id}',
                'category' => 'Procedures',
                'title_columns' => ['title', 'procedure_name', 'status'],
            ],
            [
                'type' => 'task',
                'pattern' => '~^/task-manager/(?<id>\d+)(?:/|$)~',
                'table' => 'tasks',
                'route' => '/task-manager/{id}',
                'category' => 'Tasks',
                'title_columns' => ['title'],
                'scope' => 'self',
                'self_column' => 'staff_id',
                'links' => [
                    ['name' => 'comments', 'table' => 'task_comments', 'foreign_key' => 'task_id', 'order' => ['id']],
                ],
            ],
            [
                'type' => 'task',
                'pattern' => '~^/staff/tasks/(?<id>\d+)(?:/|$)~',
                'table' => 'tasks',
                'route' => '/staff/tasks/{id}',
                'category' => 'Tasks',
                'title_columns' => ['title'],
                'roles' => ['HR', 'Manager', 'System Admin'],
                'links' => [
                    ['name' => 'comments', 'table' => 'task_comments', 'foreign_key' => 'task_id', 'order' => ['id']],
                ],
            ],
            [
                'type' => 'leave',
                'pattern' => '~^/my/leaves/records/(?<id>\d+)(?:/|$)~',
                'table' => 'hr_leaves_application',
                'route' => '/my/leaves/records/{id}',
                'category' => 'Leave',
                'title_columns' => ['type', 'status'],
                'scope' => 'self',
                'self_column' => 'staff_id',
            ],
            [
                'type' => 'leave',
                'pattern' => '~^/staff/leaves/records/(?<id>\d+)(?:/|$)~',
                'table' => 'hr_leaves_application',
                'route' => '/staff/leaves/records/{id}',
                'category' => 'Leave',
                'title_columns' => ['type', 'status'],
                'roles' => ['HR', 'Manager', 'System Admin'],
            ],
            [
                'type' => 'salary',
                'pattern' => '~^/my/salary/records/(?<id>\d+)(?:/|$)~',
                'table' => 'hr_salary_applications',
                'route' => '/my/salary/records/{id}',
                'category' => 'Salary',
                'title_columns' => ['salary_month', 'status'],
                'scope' => 'self',
                'self_column' => 'staff_id',
                'links' => [
                    ['name' => 'claims', 'table' => 'hr_salary_claims', 'foreign_key' => 'application_id'],
                ],
            ],
            [
                'type' => 'appraisal',
                'pattern' => '~^/appraisal/records/(?<id>\d+)(?:/|$)~',
                'table' => 'hr_appraisal',
                'route' => '/appraisal/records/{id}',
                'category' => 'Appraisals',
                'title_columns' => ['staff_name', 'appraisal_period', 'status'],
                'scope' => 'self_or_roles',
                'self_column' => 'staff_id',
                'roles' => ['HR', 'Manager', 'System Admin'],
            ],
            [
                'type' => 'appraisal',
                'pattern' => '~^/staff/appraise/records/(?<id>\d+)(?:/|$)~',
                'table' => 'hr_appraisal',
                'route' => '/staff/appraise/records/{id}',
                'category' => 'Appraisals',
                'title_columns' => ['staff_name', 'appraisal_period', 'status'],
                'roles' => ['HR', 'Manager', 'System Admin'],
            ],
            [
                'type' => 'appraisal',
                'pattern' => '~^/staff/appraise/final-appraisal/(?:records/)?(?<id>\d+)(?:/|$)~',
                'table' => 'hr_final_appraisals',
                'route' => '/staff/appraise/final-appraisal/records/{id}',
                'category' => 'Appraisals',
                'title_columns' => ['staff_name', 'appraisal_period', 'status'],
                'roles' => ['HR', 'Manager', 'System Admin'],
            ],
            [
                'type' => 'staff',
                'pattern' => '~^/staff/manage/(?<id>\d+)(?:/|$)~',
                'table' => 'staff_general',
                'id_column' => 'staff_id',
                'route' => '/staff/manage/{id}',
                'category' => 'Staff',
                'title_columns' => ['full_name', 'name_code'],
                'roles' => ['HR', 'Manager', 'System Admin'],
            ],
            [
                'type' => 'handbook',
                'source_type' => 'handbook',
                'pattern' => '~^/handbook/versions/(?<id>\d+)(?:/|$)~',
                'table' => 'hr_handbook_versions',
                'route' => '/handbook/versions/{id}',
                'category' => 'Handbook',
                'title_columns' => ['version_label', 'title'],
            ],
            [
                'type' => 'legal_compliance',
                'pattern' => '~^/internal-tools/legal-compliance/templates/(?<id>\d+)(?:/|$)~',
                'table' => 'legal_compliance_templates',
                'route' => '/internal-tools/legal-compliance/templates/{id}',
                'category' => 'Legal compliance',
                'title_columns' => ['title', 'name', 'status'],
                'links' => [
                    ['name' => 'versions', 'table' => 'legal_compliance_template_versions', 'foreign_key' => 'template_id', 'order' => ['version_no', 'id']],
                ],
            ],
            [
                'type' => 'legal_compliance',
                'pattern' => '~^/internal-tools/legal-compliance$~',
                'query_param' => 'assessmentId',
                'table' => 'legal_compliance_assessments',
                'route' => '/internal-tools/legal-compliance?assessmentId={id}',
                'category' => 'Legal compliance',
                'title_columns' => ['title', 'status'],
                'scope' => 'self_or_roles',
                'self_column' => 'staff_id',
                'roles' => ['Manager', 'System Admin'],
                'belongs_to' => [
                    ['name' => 'template', 'table' => 'legal_compliance_templates', 'local_key' => 'template_id', 'foreign_key' => 'id', 'route' => '/internal-tools/legal-compliance/templates/{id}', 'title_columns' => ['title', 'name']],
                ],
            ],
            [
                'type' => 'tool_request',
                'source_type' => 'system_feedback',
                'pattern' => '~^/support/requests/(?<id>\d+)(?:/|$)~',
                'table' => 'tool_requests',
                'route' => '/support/requests/{id}',
                'category' => 'Support requests',
                'title_columns' => ['title', 'status'],
            ],
            [
                'type' => 'system_feedback',
                'pattern' => '~^/support/feedback/(?<id>\d+)(?:/|$)~',
                'table' => 'system_feedbacks',
                'route' => '/support/feedback/{id}',
                'category' => 'System feedback',
                'title_columns' => ['feedback', 'status'],
            ],
            [
                'type' => 'whats_new',
                'pattern' => '~^/(?:system-admin/)?whats-new/(?<id>\d+)(?:/edit)?(?:/|$)~',
                'table' => 'whats_new_notes',
                'route' => '/whats-new/{id}',
                'category' => 'What\'s new',
                'title_columns' => ['title', 'version', 'status'],
                'links' => [
                    ['name' => 'attachments', 'table' => 'whats_new_attachments', 'foreign_key' => 'whats_new_note_id'],
                ],
            ],
        ];
    }

    private function linkedRows(array $spec, array $record): array
    {
        $links = [];

        foreach ($spec['links'] ?? [] as $link) {
            $rows = $this->directRows($link, $record);
            if ($rows !== []) {
                $links[$link['name']] = $rows;
            }
        }

        foreach ($spec['belongs_to'] ?? [] as $link) {
            $row = $this->belongsToRow($link, $record);
            if ($row !== null) {
                $links[$link['name']] = $row;
            }
        }

        return $links;
    }

    private function directRows(array $link, array $record): array
    {
        $table = $link['table'];
        $foreignKey = $link['foreign_key'];
        $localKey = $link['local_key'] ?? ($link['parent_key'] ?? 'id');
        $value = $record[$localKey] ?? null;

        if ($value === null || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $foreignKey)) {
            return [];
        }

        $query = DB::table($table)->where($foreignKey, $value);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        foreach ($link['order'] ?? [] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $query->orderBy($column);
            }
        }

        return $query
            ->limit($link['limit'] ?? self::LINK_LIMIT)
            ->get()
            ->map(fn ($row): array => $this->objectToArray($row))
            ->all();
    }

    private function belongsToRow(array $link, array $record): ?array
    {
        $table = $link['table'];
        $localKey = $link['local_key'];
        $foreignKey = $link['foreign_key'] ?? 'id';
        $value = $record[$localKey] ?? null;

        if ($value === null || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $foreignKey)) {
            return null;
        }

        $query = DB::table($table)->where($foreignKey, $value);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $row = $query->first();
        if (! $row) {
            return null;
        }

        $rowArray = $this->objectToArray($row);
        $route = isset($link['route'])
            ? str_replace('{id}', (string) ($rowArray[$foreignKey] ?? $value), $link['route'])
            : null;

        return array_filter([
            'record_type' => $link['name'],
            'title' => $this->title($link, $rowArray, (int) $value),
            'related_route' => $route,
            'detail' => $rowArray,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function title(array $spec, array $record, int $id): string
    {
        foreach ($spec['title_columns'] ?? [] as $column) {
            $value = trim((string) ($record[$column] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return ucwords(str_replace('_', ' ', (string) ($spec['type'] ?? $spec['name'] ?? 'record'))).' #'.$id;
    }

    private function canAccess(array $spec, Request $request): bool
    {
        if (($spec['scope'] ?? null) === 'self' || ($spec['scope'] ?? null) === 'self_or_roles') {
            return (int) $request->session()->get('staff_id') > 0;
        }

        if (! isset($spec['roles'])) {
            return true;
        }

        return $this->hasRoleAccess($spec, $request);
    }

    private function hasRoleAccess(array $spec, Request $request): bool
    {
        if (! isset($spec['roles'])) {
            return false;
        }

        $roles = $request->session()->get('roles', []);
        if (! is_array($roles)) {
            $roles = [$roles];
        }

        $normalized = array_map(static fn ($role): string => strtolower(trim((string) $role)), $roles);
        if (in_array('system admin', $normalized, true)) {
            return true;
        }

        $allowed = array_map(static fn ($role): string => strtolower(trim((string) $role)), $spec['roles']);

        return count(array_intersect($normalized, $allowed)) > 0;
    }

    private function routePath(string $currentRoute): string
    {
        $route = trim($currentRoute);
        if ($route === '') {
            return '';
        }

        $path = parse_url($route, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            $path = $route;
        }

        $path = '/'.ltrim($path, '/');

        return rtrim($path, '/') ?: '/';
    }

    private function routeQuery(string $currentRoute): array
    {
        $query = parse_url(trim($currentRoute), PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return [];
        }

        parse_str($query, $params);

        return is_array($params) ? $params : [];
    }

    private function objectToArray(object $row): array
    {
        return json_decode(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', true) ?: [];
    }
}
