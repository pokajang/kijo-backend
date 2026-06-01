<?php

namespace App\Services\Assistant\Sources;

class StaffDirectoryContextProvider extends SimpleTableContextProvider
{
    public function key(): string { return 'staff_directory'; }

    protected function tokens(): array { return ['staff', 'staf', 'employee', 'hr', 'directory', 'position', 'profile']; }

    protected function routeHints(): array { return ['/staff/manage', '/my/profile']; }

    protected function tableSpecs(): array
    {
        return [[
            'table' => 'staff_general',
            'id' => 'staff_id',
            'title' => 'full_name',
            'source_type' => 'staff',
            'category' => 'Staff Directory',
            'route' => '/staff/manage/{id}',
            'route_pattern' => '~/staff/manage/(\d+)(?:/|$)~i',
            'fields' => ['staff_id', 'full_name', 'name_code', 'email', 'crm_position', 'created_at'],
            'search' => ['full_name', 'name_code', 'email', 'crm_position'],
            'self_staff_column' => 'staff_id',
            'admin_roles' => ['System Admin', 'Manager', 'HR'],
            'order_by' => 'full_name',
            'score' => 360,
        ]];
    }
}
