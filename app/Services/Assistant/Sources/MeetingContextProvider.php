<?php

namespace App\Services\Assistant\Sources;

class MeetingContextProvider extends SimpleTableContextProvider
{
    public function key(): string { return 'meeting'; }

    protected function tokens(): array { return ['meeting', 'minutes', 'agenda', 'action', 'attendee']; }

    protected function routeHints(): array { return ['/administration/meetings']; }

    protected function tableSpecs(): array
    {
        return [[
            'table' => 'meeting_minutes',
            'id' => 'id',
            'title' => 'meeting_title',
            'source_type' => 'meeting',
            'category' => 'Meetings',
            'route' => '/administration/meetings/{id}',
            'fields' => ['id', 'meeting_title', 'meeting_type', 'meeting_datetime', 'venue', 'agenda', 'record_status', 'created_by', 'created_code', 'updated_at'],
            'search' => ['meeting_title', 'meeting_type', 'venue', 'agenda', 'record_status'],
            'self_staff_column' => 'created_by',
            'admin_roles' => ['System Admin', 'Manager'],
            'order_by' => 'meeting_datetime',
            'score' => 340,
        ]];
    }
}
