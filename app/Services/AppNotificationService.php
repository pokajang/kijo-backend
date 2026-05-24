<?php

namespace App\Services;

use App\Services\Leaves\LeaveWorkflowRecipientService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppNotificationService
{
    private const TABLE = 'in_app_notifications';

    private const MODULE_UI = [
        'staff.leaves' => [
            'route_group' => '/staff/leaves',
            'tab_key' => 'staff.leaves',
            'severity' => 'warning',
        ],
        'my.leaves' => [
            'route_group' => '/my/leaves',
            'tab_key' => 'my.leaves',
            'severity' => 'success',
        ],
        'client.vendor_registration' => [
            'route_group' => '/client/manage',
            'tab_key' => 'client.vendor-registration',
            'severity' => 'danger',
        ],
        'crm.negotiations' => [
            'route_group' => '/crm/price-exceptions',
            'tab_key' => 'crm.negotiations',
            'severity' => 'primary',
        ],
    ];

    private const NEGOTIABLE_SERVICES = ['training', 'manpower'];

    private const SERVICE_TABLES = [
        'training' => 'quotes_training',
        'manpower' => 'quotes_manpower',
    ];

    public function createForStaff(array $staffIds, array $payload): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $now = now();
        $uniqueStaffIds = array_values(array_unique(array_filter(array_map('intval', $staffIds))));

        foreach ($uniqueStaffIds as $staffId) {
            DB::table(self::TABLE)->insert([
                'recipient_staff_id' => $staffId,
                'actor_staff_id' => $payload['actor_staff_id'] ?? null,
                'module_key' => $payload['module_key'],
                'entity_type' => $payload['entity_type'],
                'entity_id' => (int) $payload['entity_id'],
                'type' => $payload['type'],
                'title' => $payload['title'],
                'message' => $payload['message'] ?? null,
                'route' => $payload['route'] ?? null,
                'severity' => $payload['severity'] ?? 'info',
                'metadata_json' => isset($payload['metadata'])
                    ? json_encode($payload['metadata'])
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function resolveActive(
        string $moduleKey,
        string $entityType,
        int $entityId,
        ?array $types = null,
    ): array {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }

        $query = DB::table(self::TABLE)
            ->where('module_key', $moduleKey)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereNull('resolved_at')
            ->whereNull('consumed_at');

        if ($types !== null) {
            $query->whereIn('type', $types);
        }

        $recipientIds = (clone $query)->pluck('recipient_staff_id')->map(fn ($id) => (int) $id)->all();

        $query->update([
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        return array_values(array_unique($recipientIds));
    }

    public function consumeEntity(
        Request $request,
        string $moduleKey,
        string $entityType,
        int $entityId,
        ?string $routePrefix = null,
    ): int {
        if (! Schema::hasTable(self::TABLE)) {
            return 0;
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return 0;
        }

        $query = DB::table(self::TABLE)
            ->where('recipient_staff_id', $staffId)
            ->where('module_key', $moduleKey)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereNull('consumed_at')
            ->whereNull('resolved_at');

        if ($routePrefix !== null && $routePrefix !== '') {
            $query->where('route', 'like', $routePrefix.'%');
        }

        return $query->update([
            'read_at' => now(),
            'consumed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function consumeRouteGroup(
        Request $request,
        string $routePrefix,
        ?array $moduleKeys = null,
    ): int {
        if (! Schema::hasTable(self::TABLE)) {
            return 0;
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        $routePrefix = rtrim(trim($routePrefix), '/');

        if ($staffId <= 0 || $routePrefix === '') {
            return 0;
        }

        $moduleKeys = array_values(array_unique(array_filter(array_map(
            static fn ($key): string => trim((string) $key),
            $moduleKeys ?? [],
        ))));

        if (empty($moduleKeys)) {
            return 0;
        }

        $query = DB::table(self::TABLE)
            ->where('recipient_staff_id', $staffId)
            ->where(function ($query) use ($routePrefix): void {
                $query
                    ->where('route', $routePrefix)
                    ->orWhere('route', 'like', $routePrefix.'/%');
            })
            ->whereNull('consumed_at')
            ->whereNull('resolved_at');
        $query->whereIn('module_key', $moduleKeys);

        return $query->update([
            'read_at' => now(),
            'consumed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function staffIdsForRoles(array $allowedRoles): array
    {
        if (! Schema::hasTable('system_users')) {
            return [];
        }

        $allowed = array_map(static fn ($role) => strtolower(trim((string) $role)), $allowedRoles);

        $query = DB::table('system_users as su')
            ->where('su.is_active', 1)
            ->whereNotNull('su.staff_id');

        if (Schema::hasTable('staff_general')) {
            $query
                ->join('staff_general as sg', 'sg.staff_id', '=', 'su.staff_id')
                ->where('sg.status', 'Active')
                ->whereNull('sg.deleted_at');
        }

        return $query
            ->get(['su.staff_id', 'su.role'])
            ->filter(fn ($row) => $this->rolesMatch($row->role ?? null, $allowed))
            ->pluck('staff_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function summary(Request $request): array
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $byModule = [];

        foreach ($this->storedCounts($staffId) as $moduleKey => $count) {
            $this->addModuleCount($byModule, $moduleKey, $count);
        }

        $byModule['staff.leaves'] = $this->leaveAttentionCount($request);

        $this->addModuleCount(
            $byModule,
            'client.vendor_registration',
            $this->vendorRegistrationAttentionCount($staffId),
        );

        $this->addModuleCount(
            $byModule,
            'crm.negotiations',
            $this->negotiationAttentionCount($request),
        );

        return $this->formatSummary($byModule);
    }

    private function storedCounts(int $staffId): array
    {
        if ($staffId <= 0 || ! Schema::hasTable(self::TABLE)) {
            return [];
        }

        return DB::table(self::TABLE)
            ->where('recipient_staff_id', $staffId)
            ->whereNull('consumed_at')
            ->whereNull('resolved_at')
            ->select('module_key', 'route', DB::raw('COUNT(*) as count'))
            ->groupBy('module_key', 'route')
            ->get()
            ->reduce(function (array $counts, object $row): array {
                $moduleKey = $this->storedNotificationModuleKey(
                    (string) $row->module_key,
                    $row->route ?? null,
                );
                $counts[$moduleKey] = ($counts[$moduleKey] ?? 0) + (int) $row->count;

                return $counts;
            }, []);
    }

    private function storedNotificationModuleKey(string $moduleKey, mixed $route): string
    {
        $routeText = is_string($route) ? $route : '';

        if ($moduleKey === 'staff.leaves' && str_starts_with($routeText, '/my/leaves')) {
            return 'my.leaves';
        }

        return $moduleKey;
    }

    private function leaveAttentionCount(Request $request): int
    {
        if (! Schema::hasTable('hr_leaves_application')) {
            return 0;
        }

        $count = 0;

        if ($this->isLeaveWorkflowRecipient(
            $request,
            LeaveWorkflowRecipientService::STAGE_SUBMITTED_RECOMMENDERS,
            ['hr'],
        )) {
            $count += (int) DB::table('hr_leaves_application')
                ->whereRaw("LOWER(COALESCE(status, '')) = ?", ['pending'])
                ->where(function ($query): void {
                    $query
                        ->whereNull('reviewed_by')
                        ->orWhere('reviewed_by', 0);
                })
                ->where(function ($query): void {
                    $query
                        ->whereNull('reviewed_status')
                        ->orWhere('reviewed_status', '')
                        ->orWhereRaw("LOWER(COALESCE(reviewed_status, '')) = ?", ['pending']);
                })
                ->count();
        }

        if ($this->isLeaveWorkflowRecipient(
            $request,
            LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS,
            ['manager', 'system admin'],
        )) {
            $count += (int) DB::table('hr_leaves_application')
                ->whereRaw("LOWER(COALESCE(status, '')) = ?", ['pending'])
                ->whereRaw("LOWER(COALESCE(reviewed_status, '')) = ?", ['recommended'])
                ->where(function ($query): void {
                    $query
                        ->whereNull('approved_by')
                        ->orWhere('approved_by', 0);
                })
                ->where(function ($query): void {
                    $query
                        ->whereNull('approved_status')
                        ->orWhere('approved_status', '')
                        ->orWhereRaw("LOWER(COALESCE(approved_status, '')) = ?", ['pending']);
                })
                ->count();
        }

        return $count;
    }

    private function isLeaveWorkflowRecipient(Request $request, string $stageKey, array $fallbackRoles): bool
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return false;
        }

        $configuredStaffIds = $this->configuredLeaveWorkflowStaffIds($stageKey);
        if (! empty($configuredStaffIds)) {
            return in_array($staffId, $configuredStaffIds, true);
        }

        return $this->rolesMatch($request->session()->get('roles', []), $fallbackRoles);
    }

    private function configuredLeaveWorkflowStaffIds(string $stageKey): array
    {
        if (! Schema::hasTable('hr_leave_workflow_recipients')) {
            return [];
        }

        $query = DB::table('hr_leave_workflow_recipients as r')
            ->where('r.stage_key', $stageKey)
            ->where('r.is_active', 1);

        if (Schema::hasTable('staff_general')) {
            $query
                ->join('staff_general as sg', 'sg.staff_id', '=', 'r.staff_id')
                ->whereNull('sg.deleted_at')
                ->whereRaw("LOWER(COALESCE(sg.status, '')) = 'active'");
        }

        return $query
            ->pluck('r.staff_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function vendorRegistrationAttentionCount(int $staffId): int
    {
        if (
            $staffId <= 0 ||
            ! Schema::hasTable('client_vendor_registrations') ||
            ! Schema::hasTable('client_vendor_registration_recipients')
        ) {
            return 0;
        }

        return (int) DB::table('client_vendor_registrations as r')
            ->join('client_vendor_registration_recipients as rr', 'rr.registration_id', '=', 'r.id')
            ->where('rr.staff_id', $staffId)
            ->whereNull('r.deleted_at')
            ->whereDate('r.valid_until', '<', Carbon::today()->toDateString())
            ->distinct()
            ->count('r.id');
    }

    private function negotiationAttentionCount(Request $request): int
    {
        if (! Schema::hasTable('quote_price_exception_requests')) {
            return 0;
        }

        $staffId = (int) $request->session()->get('staff_id', 0);

        $count = 0;

        if ($this->isNegotiationApprover($request)) {
            $count = $this->countActionableNegotiations('pending');
        }

        if ($count < 1) {
            $count = $this->countActionableNegotiations('approved', $staffId);
        }

        return $count;
    }

    private function countActionableNegotiations(string $status, ?int $requestedById = null): int
    {
        $total = 0;

        foreach (self::NEGOTIABLE_SERVICES as $service) {
            $table = self::SERVICE_TABLES[$service] ?? null;
            if (! $table || ! Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table('quote_price_exception_requests as r')
                ->join($table.' as q', 'q.id', '=', 'r.quote_id')
                ->where('r.request_type', 'quote')
                ->where('r.service_group', $service)
                ->where('r.quote_id', '>', 0)
                ->where('r.status', $status)
                ->whereRaw('LOWER(COALESCE(q.status, "")) in (?, ?)', ['open', 'pending']);

            if ($requestedById !== null) {
                $query->where('r.requested_by_id', $requestedById);
            }

            $total += $query->count();
        }

        return (int) $total;
    }

    private function formatSummary(array $byModule): array
    {
        $byRouteGroup = [];
        $byTab = [];

        foreach ($byModule as $moduleKey => $count) {
            if ($count <= 0) {
                continue;
            }

            $ui = self::MODULE_UI[$moduleKey] ?? null;
            if (! $ui) {
                continue;
            }

            $byRouteGroup[$ui['route_group']] = ($byRouteGroup[$ui['route_group']] ?? 0) + $count;
            $byTab[$ui['tab_key']] = ($byTab[$ui['tab_key']] ?? 0) + $count;
        }

        return [
            'total' => array_sum($byModule),
            'by_module' => (object) $byModule,
            'by_route_group' => (object) $byRouteGroup,
            'by_tab' => (object) $byTab,
        ];
    }

    private function addModuleCount(array &$counts, string $moduleKey, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $counts[$moduleKey] = ($counts[$moduleKey] ?? 0) + $count;
    }

    private function isNegotiationApprover(Request $request): bool
    {
        return $this->rolesMatch($request->session()->get('roles', []), ['manager', 'system admin']);
    }

    private function rolesMatch(mixed $rawRoles, array $allowedRoles): bool
    {
        $decoded = is_string($rawRoles) ? json_decode($rawRoles, true) : null;
        $roles = is_array($decoded) ? $decoded : (is_array($rawRoles) ? $rawRoles : [$rawRoles]);
        $normalized = array_map(static fn ($role) => strtolower(trim((string) $role)), $roles);

        return ! empty(array_intersect($allowedRoles, $normalized));
    }
}
