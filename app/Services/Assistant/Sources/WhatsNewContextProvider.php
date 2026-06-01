<?php

namespace App\Services\Assistant\Sources;

class WhatsNewContextProvider extends SimpleTableContextProvider
{
    public function key(): string { return 'whats_new'; }

    protected function tokens(): array { return ['whats', 'new', 'release', 'update', 'feature', 'announcement']; }

    protected function routeHints(): array { return ['/system-admin/whats-new']; }

    protected function tableSpecs(): array
    {
        return [[
            'table' => 'whats_new_notes',
            'id' => 'id',
            'title' => 'title',
            'source_type' => 'whats_new',
            'category' => 'WhatsNew',
            'route' => '/system-admin/whats-new/{id}',
            'fields' => ['id', 'version', 'title', 'summary', 'items', 'is_published', 'published_at', 'updated_at'],
            'search' => ['version', 'title', 'summary', 'body'],
            'published_column' => 'is_published',
            'published_value' => true,
            'order_by' => 'published_at',
            'score' => 360,
        ]];
    }
}
