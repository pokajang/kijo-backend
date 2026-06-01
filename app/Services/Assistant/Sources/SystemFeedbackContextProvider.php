<?php

namespace App\Services\Assistant\Sources;

class SystemFeedbackContextProvider extends SimpleTableContextProvider
{
    public function key(): string { return 'system_feedback'; }

    protected function tokens(): array { return ['feedback', 'issue', 'bug', 'support', 'request', 'ticket']; }

    protected function routeHints(): array { return ['/support/feedback']; }

    protected function tableSpecs(): array
    {
        return [[
            'table' => 'system_feedbacks',
            'id' => 'id',
            'title' => 'feedback',
            'source_type' => 'system_feedback',
            'category' => 'System Feedback',
            'route' => '/support/feedback/{id}',
            'fields' => ['id', 'feedback', 'status', 'reported_by', 'reported_by_id', 'date_reported', 'action_date', 'remarks'],
            'search' => ['feedback', 'status', 'reported_by', 'remarks'],
            'self_staff_column' => 'reported_by',
            'admin_roles' => ['System Admin', 'Manager'],
            'order_by' => 'date_reported',
            'score' => 330,
        ]];
    }
}
