<?php

namespace App\Services\Assistant\Sources;

class AppraisalContextProvider extends SimpleTableContextProvider
{
    public function key(): string { return 'appraisal'; }

    protected function tokens(): array { return ['appraisal', 'performance', 'review', 'feedback', 'kpi']; }

    protected function routeHints(): array { return ['/staff/appraise']; }

    protected function tableSpecs(): array
    {
        return [[
            'table' => 'hr_appraisal',
            'id' => 'id',
            'title' => 'title',
            'source_type' => 'appraisal',
            'category' => 'Appraisals',
            'route' => '/staff/appraise/{id}',
            'fields' => ['id', 'staff_id', 'title', 'status', 'period', 'score', 'feedback', 'created_at', 'updated_at'],
            'search' => ['title', 'status', 'period', 'feedback'],
            'self_staff_column' => 'staff_id',
            'admin_roles' => ['System Admin', 'Manager', 'HR'],
            'order_by' => 'updated_at',
            'score' => 340,
        ]];
    }
}
