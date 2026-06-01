<?php

namespace App\Services\Assistant\Sources;

class LegalComplianceContextProvider extends SimpleTableContextProvider
{
    public function key(): string { return 'legal_compliance'; }

    protected function tokens(): array { return ['legal', 'compliance', 'assessment', 'osha', 'template', 'clause']; }

    protected function routeHints(): array { return ['/internal-tools/legal-compliance', '/legal-compliance']; }

    protected function tableSpecs(): array
    {
        return [
            [
                'table' => 'legal_compliance_templates',
                'id' => 'id',
                'title' => 'name',
                'source_type' => 'legal_compliance',
                'category' => 'Legal Compliance',
                'route' => '/internal-tools/legal-compliance/templates/{id}',
                'fields' => ['id', 'name', 'slug', 'description', 'is_default', 'active_version_id', 'updated_at'],
                'search' => ['name', 'slug', 'description'],
                'order_by' => 'updated_at',
                'score' => 390,
            ],
            [
                'table' => 'legal_compliance_assessments',
                'id' => 'id',
                'title' => 'company_name',
                'source_type' => 'legal_compliance',
                'category' => 'Legal Compliance',
                'route' => '/internal-tools/legal-compliance/records/{id}',
                'fields' => ['id', 'company_name', 'assessment_date', 'stage', 'template_version', 'staff_id', 'updated_at'],
                'search' => ['company_name', 'assessor_name', 'nature_of_company', 'stage', 'template_version'],
                'self_staff_column' => 'staff_id',
                'admin_roles' => ['System Admin', 'Manager'],
                'order_by' => 'updated_at',
                'score' => 340,
            ],
        ];
    }
}
