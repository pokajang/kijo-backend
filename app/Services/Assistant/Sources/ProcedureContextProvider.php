<?php

namespace App\Services\Assistant\Sources;

class ProcedureContextProvider extends SimpleTableContextProvider
{
    public function key(): string { return 'procedure'; }

    protected function tokens(): array { return ['procedure', 'procedures', 'sop', 'process', 'administration']; }

    protected function routeHints(): array { return ['/administration/procedures']; }

    protected function tableSpecs(): array
    {
        return [[
            'table' => 'procedures',
            'id' => 'id',
            'title' => 'title',
            'source_type' => 'procedure',
            'category' => 'Procedures',
            'route' => '/administration/procedures/{id}',
            'fields' => ['id', 'title', 'summary', 'status', 'category', 'created_at', 'updated_at'],
            'search' => ['title', 'summary', 'status', 'category'],
            'published_column' => 'is_published',
            'published_value' => true,
            'order_by' => 'updated_at',
            'score' => 380,
        ]];
    }
}
