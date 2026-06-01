<?php

namespace App\Services\Assistant\Sources;

class Jd14ContextProvider extends SimpleTableContextProvider
{
    public function key(): string { return 'jd14'; }

    protected function tokens(): array { return ['jd14', 'jd', 'delivery', 'do', 'approval']; }

    protected function routeHints(): array { return ['/commercial/jd14']; }

    protected function tableSpecs(): array
    {
        return [[
            'table' => 'invoices_jd14form',
            'id' => 'id',
            'title' => 'approval_no',
            'source_type' => 'jd14',
            'category' => 'JD14',
            'route' => '/commercial/jd14/{id}',
            'route_pattern' => '~/commercial/jd14/(\d+)(?:/|$)~i',
            'fields' => ['id', 'approval_no', 'project_id', 'client_id', 'status', 'created_at', 'updated_at'],
            'search' => ['approval_no', 'status'],
            'order_by' => 'updated_at',
            'score' => 360,
        ]];
    }
}
