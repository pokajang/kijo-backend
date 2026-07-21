<?php

namespace App\Services\QuoteApprovals;

use App\Services\Workflows\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuoteApprovalRecipientService
{
    public function recipients(string $step): array
    {
        $configured = app(WorkflowService::class)->effectiveStepRecipients(
            (string) config('quote_approval.workflow_template', 'quote-approval'),
            $step,
            1,
            [],
        );
        if ($configured !== []) {
            return $configured;
        }

        $email = strtolower(trim((string) config("quote_approval.default_approvers.{$step}")));
        if ($email === '') {
            return [];
        }
        if (! Schema::hasTable('system_users') || ! Schema::hasTable('staff_general')) {
            return [['staff_id' => null, 'email' => $email, 'full_name' => strtoupper($step).' Approver']];
        }

        $query = DB::table('system_users as su')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'su.staff_id')
            ->whereRaw('LOWER(su.email) = ?', [$email])
            ->whereNotNull('su.staff_id');
        if (Schema::hasColumn('system_users', 'is_active')) {
            $query->where('su.is_active', true);
        }

        $users = $query
            ->get([
                'su.staff_id', 'su.email', 'su.role',
                'sg.full_name', 'sg.name_code',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        return $users !== [] ? $users : [[
            'staff_id' => null,
            'email' => $email,
            'full_name' => strtoupper($step).' Approver',
        ]];
    }

    public function staffIds(string $step): array
    {
        return collect($this->recipients($step))
            ->pluck('staff_id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function notificationRecipients(string $step): array
    {
        return collect(array_merge($this->recipients($step), $this->systemAdministrators()))
            ->unique(function (array $recipient): string {
                $staffId = (int) ($recipient['staff_id'] ?? 0);

                return $staffId > 0
                    ? 'staff:'.$staffId
                    : 'email:'.strtolower(trim((string) ($recipient['email'] ?? '')));
            })
            ->values()
            ->all();
    }

    public function canDecide(Request $request, string $step): bool
    {
        if ($this->rolesMatch($request->session()->get('roles', []), ['System Admin'])) {
            return true;
        }

        return in_array((int) $request->session()->get('staff_id', 0), $this->staffIds($step), true);
    }

    private function systemAdministrators(): array
    {
        if (! Schema::hasTable('system_users') || ! Schema::hasTable('staff_general')) {
            return [];
        }

        $query = DB::table('system_users as su')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'su.staff_id')
            ->whereNotNull('su.staff_id')
            ->whereRaw('LOWER(COALESCE(su.role, "")) LIKE ?', ['%system admin%']);
        if (Schema::hasColumn('system_users', 'is_active')) {
            $query->where('su.is_active', true);
        }
        if (Schema::hasColumn('staff_general', 'status')) {
            $query->whereRaw('LOWER(COALESCE(sg.status, "active")) = ?', ['active']);
        }
        if (Schema::hasColumn('staff_general', 'deleted_at')) {
            $query->whereNull('sg.deleted_at');
        }

        return $query
            ->get([
                'su.staff_id', 'su.email', 'su.role',
                'sg.full_name', 'sg.name_code',
            ])
            ->filter(fn (object $row): bool => $this->rolesMatch($row->role ?? null, ['System Admin']))
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    private function rolesMatch(mixed $rawRoles, array $allowedRoles): bool
    {
        $decoded = is_string($rawRoles) ? json_decode($rawRoles, true) : null;
        $roles = is_array($decoded) ? $decoded : (is_array($rawRoles) ? $rawRoles : [$rawRoles]);
        $normalized = array_map(static fn ($role): string => strtolower(trim((string) $role)), $roles);
        $allowed = array_map(static fn ($role): string => strtolower(trim((string) $role)), $allowedRoles);

        return ! empty(array_intersect($allowed, $normalized));
    }
}
