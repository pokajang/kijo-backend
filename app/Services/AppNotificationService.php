<?php

namespace App\Services;

use App\Console\Commands\ReconcileLeaveNotifications;
use App\Services\Leaves\LeaveWorkflowRecipientService;
use App\Services\Workflows\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the in-app notification summary (badge counts) per signed-in staff.
 *
 * Reconciliation authority per module (see {@see reconcileModuleCount()}):
 *  - staff.leaves            FLAG-CONTROLLED (remediation Phase D3). Config
 *                            `leave.notification_badge_source`:
 *                              'recompute' (default) -> OVERWRITE: the live
 *                                workflow query is authoritative, replacing any
 *                                stored rows (legacy behaviour).
 *                              'stored' -> STORED: the stored notification rows
 *                                are authoritative (the approved end-state).
 *  - vendor.payments         MAX(stored, recompute)
 *  - crm.negotiations        MAX(stored, recompute)
 *  - client.vendor_registration MAX(stored, recompute)
 *  - my.leaves               stored-authoritative — no recompute; count comes
 *                            purely from stored notification rows.
 *
 * MAX is used for every recompute+stored module so a future stored-row writer
 * cannot double-count against the live recompute (M4). It is output-identical
 * to the previous additive merge while no stored rows exist for those keys.
 *
 * APPROVED DIRECTION (remediation Phase D): stored rows are the single source of
 * truth; the live recompute is demoted to a backfill/reconcile safety net
 * ({@see ReconcileLeaveNotifications}). The flip to
 * 'stored' is gated on the Phase D1 parity log ({@see logStaffLeavesParity()})
 * showing the stored table is trustworthy in production. While the flag remains
 * 'recompute', staff.leaves behaviour is unchanged.
 */
class AppNotificationService
{
    private const TABLE = 'in_app_notifications';

    private const RECONCILE_OVERWRITE = 'overwrite';

    private const RECONCILE_MAX = 'max';

    private const RECONCILE_STORED = 'stored';

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
        'vendor.payments' => [
            'route_group' => '/vendor/payment-records',
            'tab_key' => 'vendor.payment-records',
            'severity' => 'warning',
        ],
        'financial.salary' => [
            'route_group' => '/financial/salary-records',
            'tab_key' => 'financial.salary-records',
            'severity' => 'warning',
        ],
        'financial.other-claims' => [
            'route_group' => '/financial/other-claim-records',
            'tab_key' => 'financial.other-claim-records',
            'severity' => 'warning',
        ],
        'my.salary' => [
            'route_group' => '/my/salary',
            'tab_key' => 'my.salary.records',
            'severity' => 'success',
        ],
        'my.other-claims' => [
            'route_group' => '/my/salary',
            'tab_key' => 'my.salary.other-claim-records',
            'severity' => 'success',
        ],
        'system.admin.workload_snapshots' => [
            'route_group' => '/system-admin/dashboard',
            'tab_key' => 'system-admin.ai-workload-governance',
            'severity' => 'danger',
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

    /**
     * Read-only list of the signed-in user's active notifications (Phase D5
     * step 1 — enablement). Surfaces the stored notification CONTENT
     * (title/message/route/severity) that the summary endpoint only ever
     * exposed as counts. User-scoped exactly like the consume endpoints
     * (recipient_staff_id = session staff); active = not consumed, not resolved.
     *
     * No UI consumes this yet; it makes the data readable so a future
     * notification center can render it.
     */
    public function list(Request $request, int $limit = 20, int $offset = 0): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return ['items' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return ['items' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $base = DB::table(self::TABLE)
            ->where('recipient_staff_id', $staffId)
            ->whereNull('consumed_at')
            ->whereNull('resolved_at');

        $total = (clone $base)->count();

        $items = $base
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->offset($offset)
            ->get([
                'id',
                'module_key',
                'entity_type',
                'entity_id',
                'type',
                'title',
                'message',
                'route',
                'severity',
                'read_at',
                'created_at',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'module_key' => (string) $row->module_key,
                'entity_type' => (string) $row->entity_type,
                'entity_id' => (int) $row->entity_id,
                'type' => (string) $row->type,
                'title' => (string) $row->title,
                'message' => $row->message !== null ? (string) $row->message : null,
                'route' => $row->route !== null ? (string) $row->route : null,
                'severity' => (string) $row->severity,
                'read_at' => $row->read_at,
                'created_at' => $row->created_at,
            ])
            ->all();

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
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

        // Stored-row count for staff.leaves, captured BEFORE reconciliation
        // (Phase D1 parity instrumentation).
        $storedStaffLeaves = (int) ($byModule['staff.leaves'] ?? 0);
        $recomputeStaffLeaves = $this->leaveAttentionCount($request);
        $this->logStaffLeavesParity($staffId, $recomputeStaffLeaves, $storedStaffLeaves);

        // Phase D3: authority is flag-controlled. Default 'recompute' keeps the
        // live workflow query authoritative (unchanged behavior). 'stored' makes
        // the stored notification rows authoritative (the approved end-state),
        // to be flipped once D1 parity data and the D2 backfill confirm safety.
        $staffLeavesStrategy = config('leave.notification_badge_source', 'recompute') === 'stored'
            ? self::RECONCILE_STORED
            : self::RECONCILE_OVERWRITE;

        $this->reconcileModuleCount(
            $byModule,
            'staff.leaves',
            $recomputeStaffLeaves,
            $staffLeavesStrategy,
        );

        $this->reconcileModuleCount(
            $byModule,
            'client.vendor_registration',
            $this->vendorRegistrationAttentionCount($staffId),
            self::RECONCILE_MAX,
        );

        $this->reconcileModuleCount(
            $byModule,
            'crm.negotiations',
            $this->negotiationAttentionCount($request),
            self::RECONCILE_MAX,
        );

        $this->reconcileModuleCount(
            $byModule,
            'vendor.payments',
            $this->vendorPaymentAttentionCount($request),
            self::RECONCILE_MAX,
        );

        $this->reconcileModuleCount(
            $byModule,
            'financial.salary',
            $this->salaryWorkflowAttentionCount($request, 'salary_application'),
            self::RECONCILE_OVERWRITE,
        );

        $this->reconcileModuleCount(
            $byModule,
            'financial.other-claims',
            $this->salaryWorkflowAttentionCount($request, 'other_claim_application'),
            self::RECONCILE_OVERWRITE,
        );

        return $this->formatSummary($byModule, $this->listableTotal($staffId));
    }

    /**
     * Count of the user's stored, active notification rows — i.e. exactly what
     * the notification list ({@see list()}) can display. The notification bell
     * badge MUST use this, not the all-module `total`, otherwise it can show a
     * count for recompute-only modules (vendor registrations, negotiations,
     * vendor/salary attention) that have no stored content to list — producing
     * "1 unread" over an empty drawer.
     */
    private function listableTotal(int $staffId): int
    {
        if ($staffId <= 0 || ! Schema::hasTable(self::TABLE)) {
            return 0;
        }

        return (int) DB::table(self::TABLE)
            ->where('recipient_staff_id', $staffId)
            ->whereNull('consumed_at')
            ->whereNull('resolved_at')
            ->count();
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
            $this->logMissingTable('leaveAttentionCount', 'hr_leaves_application');

            return 0;
        }

        $count = 0;

        if ($this->isLeaveWorkflowRecipient(
            $request,
            LeaveWorkflowRecipientService::STAGE_SUBMITTED_RECOMMENDERS,
            ['manager', 'system admin'],
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
            ['hr', 'system admin'],
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
        if ($this->rolesMatch($request->session()->get('roles', []), ['System Admin'])) {
            return true;
        }

        $configuredStaffIds = $this->configuredLeaveWorkflowStaffIds($stageKey);
        if (! empty($configuredStaffIds)) {
            return in_array($staffId, $configuredStaffIds, true);
        }

        return $this->rolesMatch($request->session()->get('roles', []), $fallbackRoles);
    }

    /**
     * Configured recipient staff IDs for a leave workflow stage.
     *
     * Delegates to {@see LeaveWorkflowRecipientService::configuredStageStaffIds()}
     * so the badge count resolves configured recipients through the exact same
     * (central-then-legacy) logic used by leave notification creation and
     * permission checks — preventing the "counted but not a recipient" drift.
     */
    private function configuredLeaveWorkflowStaffIds(string $stageKey): array
    {
        return app(LeaveWorkflowRecipientService::class)->configuredStageStaffIds($stageKey);
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
            $this->logMissingTable('negotiationAttentionCount', 'quote_price_exception_requests');

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

    private function vendorPaymentAttentionCount(Request $request): int
    {
        if (! Schema::hasTable('vendor_payments')) {
            $this->logMissingTable('vendorPaymentAttentionCount', 'vendor_payments');

            return 0;
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return 0;
        }

        $roles = $request->session()->get('roles', []);
        if ($this->rolesMatch($roles, ['system admin'])) {
            return $this->countVendorPaymentsByStatuses(['Pending', 'Checked', 'Approved']);
        }

        $count = 0;

        if ($this->canActForVendorPaymentStage($request, 'review', ['manager', 'system admin'])) {
            $count += $this->countVendorPaymentsByStatuses(['Pending']);
        }

        if ($this->canActForVendorPaymentStage($request, 'approval', ['manager', 'system admin'])) {
            $count += $this->countVendorPaymentsByStatuses(['Checked']);
        }

        if ($this->canActForVendorPaymentStage($request, 'finance', ['finance', 'account', 'bank', 'manager', 'system admin'])) {
            $count += $this->countVendorPaymentsByStatuses(['Approved']);
        }

        return $count;
    }

    private function countVendorPaymentsByStatuses(array $statuses): int
    {
        $normalizedStatuses = array_values(array_unique(array_map(
            static fn ($status): string => strtolower(trim((string) $status)),
            $statuses,
        )));

        if (empty($normalizedStatuses)) {
            return 0;
        }

        $query = DB::table('vendor_payments');

        if (Schema::hasColumn('vendor_payments', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) $query
            ->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), $normalizedStatuses)
            ->count();
    }

    private function canActForVendorPaymentStage(Request $request, string $stageType, array $fallbackRoles): bool
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return false;
        }
        if ($this->rolesMatch($request->session()->get('roles', []), ['System Admin'])) {
            return true;
        }

        $configuredStaffIds = $this->configuredVendorPaymentStageStaffIds($stageType);
        if (! empty($configuredStaffIds)) {
            return in_array($staffId, $configuredStaffIds, true);
        }

        return $this->rolesMatch($request->session()->get('roles', []), $fallbackRoles);
    }

    private function configuredVendorPaymentStageStaffIds(string $stageType): array
    {
        if (
            Schema::hasTable('workflow_templates')
            && Schema::hasTable('workflow_template_steps')
            && Schema::hasTable('workflow_step_recipients')
        ) {
            $query = DB::table('workflow_step_recipients as r')
                ->join('workflow_template_steps as step', 'step.id', '=', 'r.step_id')
                ->join('workflow_templates as template', 'template.id', '=', 'step.template_id')
                ->where('template.process_key', 'vendor-payment')
                ->where('step.step_key', $stageType)
                ->where('step.active', 1)
                ->where('r.active', 1);

            if (Schema::hasTable('staff_general')) {
                $query
                    ->join('staff_general as sg', 'sg.staff_id', '=', 'r.staff_id')
                    ->whereNull('sg.deleted_at')
                    ->whereRaw("LOWER(COALESCE(sg.status, '')) = 'active'");
            }

            if (Schema::hasTable('system_users')) {
                $query
                    ->join('system_users as su', 'su.staff_id', '=', 'r.staff_id')
                    ->where('su.is_active', 1);
            }

            $ids = $query
                ->pluck('r.staff_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            if (DB::table('workflow_templates')->where('process_key', 'vendor-payment')->exists()) {
                return $ids;
            }
        }

        if (! Schema::hasTable('vendor_payment_workflow_recipients')) {
            return [];
        }

        $query = DB::table('vendor_payment_workflow_recipients as r')
            ->where('r.stage_type', $stageType)
            ->where('r.is_active', 1);

        if (Schema::hasTable('staff_general')) {
            $query
                ->join('staff_general as sg', 'sg.staff_id', '=', 'r.staff_id')
                ->whereNull('sg.deleted_at')
                ->whereRaw("LOWER(COALESCE(sg.status, '')) = 'active'");
        }

        if (Schema::hasTable('system_users')) {
            $query
                ->join('system_users as su', 'su.staff_id', '=', 'r.staff_id')
                ->where('su.is_active', 1);
        }

        return $query
            ->pluck('r.staff_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function salaryWorkflowAttentionCount(Request $request, string $subjectType): int
    {
        if (
            ! Schema::hasTable('workflow_instances') ||
            ! Schema::hasTable('workflow_templates') ||
            ! Schema::hasTable('workflow_template_steps') ||
            ! Schema::hasTable('workflow_step_recipients')
        ) {
            $this->logMissingTable('salaryWorkflowAttentionCount', 'workflow tables');

            return 0;
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return 0;
        }

        $table = $subjectType === 'other_claim_application'
            ? 'hr_other_claim_applications'
            : 'hr_salary_applications';
        if (! Schema::hasTable($table)) {
            $this->logMissingTable('salaryWorkflowAttentionCount', $table);

            return 0;
        }

        $instances = DB::table('workflow_instances as instance')
            ->join('workflow_templates as template', 'template.id', '=', 'instance.template_id')
            ->join('workflow_template_steps as step', 'step.id', '=', 'instance.current_step_id')
            ->where('template.process_key', 'salary-application')
            ->where('instance.subject_type', $subjectType)
            ->whereIn('instance.status', ['Submitted', 'Prepared', 'Checked'])
            ->where('step.active', 1)
            ->select([
                'instance.id',
                'instance.subject_id',
                'instance.maker_staff_id',
                'instance.status',
                'step.id as step_id',
                'step.step_key',
                'step.fallback_roles',
            ])
            ->get();

        return $instances
            ->filter(fn (object $instance): bool => $this->canActOnSalaryWorkflowInstance(
                $request,
                $instance,
                $table,
                $subjectType,
            ))
            ->count();
    }

    private function canActOnSalaryWorkflowInstance(
        Request $request,
        object $instance,
        string $applicationTable,
        string $subjectType,
    ): bool {
        $actorId = (int) $request->session()->get('staff_id', 0);
        if ($actorId <= 0) {
            return false;
        }

        $isSystemAdmin = $this->rolesMatch($request->session()->get('roles', []), ['System Admin']);
        if ($subjectType === 'salary_application') {
            $isSystemAdmin = false;
        }
        if (! $isSystemAdmin && $actorId === (int) $instance->maker_staff_id) {
            return false;
        }

        $canActOnStep = $subjectType === 'salary_application'
            ? $this->canActOnConfiguredWorkflowStep($request, (int) $instance->step_id)
            : $this->canActOnWorkflowStep($request, (int) $instance->step_id, $instance->fallback_roles);
        if (! $isSystemAdmin && ! $canActOnStep) {
            return false;
        }

        if (! $isSystemAdmin && (string) $instance->step_key === 'approve') {
            $checkerId = DB::table($applicationTable)->where('id', $instance->subject_id)->value('checked_by');
            if ((int) $checkerId === $actorId) {
                return false;
            }
        }

        return true;
    }

    private function canActOnConfiguredWorkflowStep(Request $request, int $stepId): bool
    {
        $actorId = (int) $request->session()->get('staff_id', 0);
        if ($actorId <= 0 || $stepId <= 0) {
            return false;
        }

        $recipients = DB::table('workflow_step_recipients')
            ->where('step_id', $stepId)
            ->where('active', 1)
            ->pluck('staff_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return in_array($actorId, $recipients, true);
    }

    private function canActOnWorkflowStep(Request $request, int $stepId, mixed $fallbackRoles): bool
    {
        $actorId = (int) $request->session()->get('staff_id', 0);
        if ($actorId <= 0 || $stepId <= 0) {
            return false;
        }

        $recipients = DB::table('workflow_step_recipients')
            ->where('step_id', $stepId)
            ->where('active', 1)
            ->pluck('staff_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if (! empty($recipients)) {
            return in_array($actorId, $recipients, true);
        }

        $decodedFallbackRoles = is_string($fallbackRoles) ? json_decode($fallbackRoles, true) : $fallbackRoles;

        return $this->rolesMatch(
            $request->session()->get('roles', []),
            is_array($decodedFallbackRoles) ? $decodedFallbackRoles : [],
        );
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

    private function formatSummary(array $byModule, int $listableTotal = 0): array
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
            // Count of stored rows the notification list can actually show. The
            // bell badge uses this so its count always matches its drawer.
            'listable_total' => $listableTotal,
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

    /**
     * Single reconciliation point for merging a module's live recompute with any
     * count already present from stored notification rows.
     *
     *  - OVERWRITE: recompute replaces the stored value entirely.
     *  - MAX:       the larger of stored vs recompute (prevents M4 double-count).
     *
     * A non-positive result is unset; {@see formatSummary()} already drops
     * non-positive counts, so this is output-equivalent to the prior per-module
     * merges while no stored rows exist for the MAX modules.
     */
    private function reconcileModuleCount(
        array &$counts,
        string $moduleKey,
        int $recompute,
        string $strategy,
    ): void {
        $stored = (int) ($counts[$moduleKey] ?? 0);

        $value = match ($strategy) {
            self::RECONCILE_OVERWRITE => $recompute,
            self::RECONCILE_MAX => max($stored, $recompute),
            self::RECONCILE_STORED => $stored,
            default => $stored + $recompute,
        };

        if ($value > 0) {
            $counts[$moduleKey] = $value;
        } else {
            unset($counts[$moduleKey]);
        }
    }

    /**
     * Phase D1 parity instrumentation: record where the staff.leaves live
     * recompute and the stored-notification-row count disagree. This builds the
     * evidence base for the planned authority flip (D3) without changing the
     * returned summary. Logged at info on divergence; silent on agreement.
     */
    private function logStaffLeavesParity(int $staffId, int $recompute, int $stored): void
    {
        if ($recompute === $stored) {
            return;
        }

        Log::info('notif.parity.staff_leaves', [
            'staff_id' => $staffId,
            'recompute' => $recompute,
            'stored' => $stored,
            'delta' => $recompute - $stored,
        ]);
    }

    /**
     * Observability for the silent "badge fell to zero because a domain table is
     * missing" failure mode. Debug level so a legitimately absent optional table
     * in a minimal deployment does not spam logs. No control-flow effect.
     */
    private function logMissingTable(string $context, string $table): void
    {
        Log::debug('AppNotificationService: notification count skipped; table missing.', [
            'context' => $context,
            'table' => $table,
        ]);
    }

    private function isNegotiationApprover(Request $request): bool
    {
        if (app(WorkflowService::class)->canActOnTemplateStep(
            $request,
            WorkflowService::NEGOTIATION_TEMPLATE_KEY,
            'approve',
            1,
            ['Manager', 'System Admin'],
        )) {
            return true;
        }

        return false;
    }

    private function rolesMatch(mixed $rawRoles, array $allowedRoles): bool
    {
        $decoded = is_string($rawRoles) ? json_decode($rawRoles, true) : null;
        $roles = is_array($decoded) ? $decoded : (is_array($rawRoles) ? $rawRoles : [$rawRoles]);
        $normalized = array_map(static fn ($role) => strtolower(trim((string) $role)), $roles);
        $allowedRoles = array_map(static fn ($role): string => strtolower(trim((string) $role)), $allowedRoles);

        return ! empty(array_intersect($allowedRoles, $normalized));
    }
}
