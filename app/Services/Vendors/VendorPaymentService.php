<?php

namespace App\Services\Vendors;

use App\Http\Requests\Vendor\ApproveVendorPaymentRequest;
use App\Http\Requests\Vendor\CancelVendorPaymentRequest;
use App\Http\Requests\Vendor\CheckVendorPaymentRequest;
use App\Http\Requests\Vendor\DecideVendorPaymentRequest;
use App\Http\Requests\Vendor\DeleteVendorPaymentRequest;
use App\Http\Requests\Vendor\GetVendorPaymentsRequest;
use App\Http\Requests\Vendor\ListVendorPaymentsRequest;
use App\Http\Requests\Vendor\MarkVendorPaymentPaidRequest;
use App\Http\Requests\Vendor\RecordVendorPaymentTransactionRequest;
use App\Http\Requests\Vendor\ResubmitVendorPaymentRequest;
use App\Http\Requests\Vendor\ReverseVendorPaymentTransactionRequest;
use App\Http\Requests\Vendor\StoreVendorPaymentRequest;
use App\Http\Requests\Vendor\UpdateVendorPaymentRequest;
use App\Services\AppNotificationService;
use App\Support\AppFilePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VendorPaymentService extends VendorBaseService
{
    private ?VendorPaymentWorkflowService $workflowService = null;

    private ?VendorPaymentFlowPresenter $workflowFlowPresenter = null;

    private ?VendorPaymentAuthorizationService $authorizationService = null;

    private ?VendorPaymentLifecycleService $lifecycleService = null;

    private const NOTIFICATION_MODULE = 'vendor.payments';

    private const NOTIFICATION_ENTITY = 'vendor_payment';

    private const CHECK_APPROVE_ROLES = ['Manager', 'System Admin'];

    private function notifications(): AppNotificationService
    {
        return app(AppNotificationService::class);
    }

    private function workflow(): VendorPaymentWorkflowService
    {
        return $this->workflowService ??= app(VendorPaymentWorkflowService::class);
    }

    private function workflowFlowPresenter(): VendorPaymentFlowPresenter
    {
        return $this->workflowFlowPresenter ??= app(VendorPaymentFlowPresenter::class);
    }

    private function authorization(): VendorPaymentAuthorizationService
    {
        return $this->authorizationService ??= app(VendorPaymentAuthorizationService::class);
    }

    private function lifecycle(): VendorPaymentLifecycleService
    {
        return $this->lifecycleService ??= app(VendorPaymentLifecycleService::class);
    }

    private function vendorPaymentColumn(string $column)
    {
        if (Schema::hasColumn('vendor_payments', $column)) {
            return "vp.{$column}";
        }

        return DB::raw("NULL as {$column}");
    }

    private function projectDescriptionColumn()
    {
        return Schema::hasColumn('projects_main', 'description')
            ? DB::raw('pm.description as project_description')
            : DB::raw('NULL as project_description');
    }

    private function canRole(Request $request, array $allowedRoles): bool
    {
        $roles = $request->session()->get('roles', []);
        if (! is_array($roles)) {
            $roles = [$roles];
        }
        $roleKeys = array_map(static fn ($role): string => strtolower(trim((string) $role)), $roles);
        if (in_array('system admin', $roleKeys, true)) {
            return true;
        }

        $allowed = array_map(static fn ($role) => strtolower(trim((string) $role)), $allowedRoles);
        foreach ($roleKeys as $role) {
            if (in_array($role, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    private function optionalUpdateColumns(array $values): array
    {
        return array_filter(
            $values,
            static fn ($value, $column): bool => Schema::hasColumn('vendor_payments', (string) $column),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function normalizeStatus(?string $status): string
    {
        return strtolower(trim((string) $status));
    }

    private function notificationRoute(int $paymentId): string
    {
        return "/vendor/payment-records/{$paymentId}";
    }

    private function notifyStaff(array $staffIds, array $payload): void
    {
        $this->notifications()->createForStaff($staffIds, array_merge([
            'module_key' => self::NOTIFICATION_MODULE,
            'entity_type' => self::NOTIFICATION_ENTITY,
            'severity' => 'warning',
        ], $payload));
    }

    private function notifyRoleRecipients(Request $request, int $paymentId, array $roles, array $payload): void
    {
        $actorId = (int) $request->session()->get('staff_id', 0);
        $staffIds = array_values(array_diff($this->notifications()->staffIdsForRoles($roles), [$actorId]));

        if (empty($staffIds)) {
            return;
        }

        $this->notifyStaff($staffIds, array_merge([
            'actor_staff_id' => $actorId ?: null,
            'entity_id' => $paymentId,
            'route' => $this->notificationRoute($paymentId),
        ], $payload));
    }

    private function notifyRequester(Request $request, object $payment, array $payload): void
    {
        $requesterId = (int) ($payment->created_by ?? 0);
        if ($requesterId <= 0) {
            return;
        }

        $this->notifyStaff([$requesterId], array_merge([
            'actor_staff_id' => (int) $request->session()->get('staff_id', 0) ?: null,
            'entity_id' => (int) $payment->id,
            'route' => $this->notificationRoute((int) $payment->id),
        ], $payload));
    }

    private function resolvePaymentNotifications(int $paymentId): void
    {
        $this->notifications()->resolveActive(
            self::NOTIFICATION_MODULE,
            self::NOTIFICATION_ENTITY,
            $paymentId,
        );
    }

    private function currentWorkflowStage(object $payment): ?array
    {
        $workflow = $this->workflow();
        $status = $this->normalizeStatus($payment->status ?? '');

        if ($status === 'pending' && $workflow->stageEnabledForPayment($payment, VendorPaymentWorkflowService::STAGE_REVIEW)) {
            return [
                'stage_type' => VendorPaymentWorkflowService::STAGE_REVIEW,
                'level_no' => $workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_REVIEW),
            ];
        }

        if ($status === 'checked' && $workflow->stageEnabledForPayment($payment, VendorPaymentWorkflowService::STAGE_APPROVAL)) {
            return [
                'stage_type' => VendorPaymentWorkflowService::STAGE_APPROVAL,
                'level_no' => $workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_APPROVAL),
            ];
        }

        if ($status === 'approved') {
            return [
                'stage_type' => VendorPaymentWorkflowService::STAGE_FINANCE,
                'level_no' => 1,
            ];
        }

        return null;
    }

    private function canDecideCurrentStage(Request $request, object $payment): bool
    {
        $stage = $this->currentWorkflowStage($payment);
        if (! $stage) {
            return $this->canRole($request, self::CHECK_APPROVE_ROLES);
        }

        return $this->workflow()->canActForPayment($request, $payment, $stage['stage_type'], $stage['level_no']);
    }

    private function canMarkPaid(Request $request, object $payment): bool
    {
        return $this->workflow()->canActForPayment(
            $request,
            $payment,
            VendorPaymentWorkflowService::STAGE_FINANCE,
            1,
        );
    }

    private function paymentPermissions(Request $request, object $payment): array
    {
        $permissions = $this->authorization()->permissions($request, $payment);

        return [
            'permissions' => $permissions,
            'can_check' => $permissions['can_check'],
            'can_approve' => $permissions['can_approve'],
            'can_return' => $permissions['can_return'],
            'can_reject' => $permissions['can_reject'],
            'can_mark_paid' => $permissions['can_record_payment'],
            'can_delete' => false,
        ];
    }

    private function normalizePaymentRowForRequest(array $row, Request $request): array
    {
        $payment = (object) $row;
        $progress = $this->paymentWorkflowProgress($row);
        $normalized = $this->normalizePaymentRow($row);
        unset($normalized['workflow_settings_snapshot_json']);

        return array_merge(
            $normalized,
            [
                'workflow_progress' => $progress,
                'workflow_flow' => $this->paymentWorkflowFlow($row, $progress),
            ],
            $this->paymentActors($row),
            $this->paymentPermissions($request, $payment),
        );
    }

    private function paymentActors(array $row): array
    {
        return [
            'requested_by_actor' => $this->actorPayload(
                (int) ($row['created_by'] ?? 0),
                $row['created_by_full_name'] ?? null,
                $row['created_by_name_code'] ?? null,
            ),
            'reviewed_by_actor' => $this->actorPayload(
                (int) ($row['checked_by'] ?? 0),
                $row['checked_by_full_name'] ?? null,
                $row['checked_by_name_code'] ?? null,
            ),
            'approved_by_actor' => $this->actorPayload(
                (int) ($row['approved_by'] ?? 0),
                $row['approved_by_full_name'] ?? null,
                $row['approved_by_name_code'] ?? null,
            ),
            'paid_by_actor' => $this->actorPayload(
                (int) ($row['paid_by'] ?? 0),
                $row['paid_by_full_name'] ?? null,
                $row['paid_by_name_code'] ?? null,
            ),
        ];
    }

    private function actorPayload(int $staffId, mixed $fullName, mixed $nameCode): ?array
    {
        $fullName = trim((string) $fullName);
        $nameCode = trim((string) $nameCode);
        if ($staffId <= 0 && $fullName === '' && $nameCode === '') {
            return null;
        }

        $display = match (true) {
            $fullName !== '' && $nameCode !== '' => "{$fullName} ({$nameCode})",
            $fullName !== '' => $fullName,
            $nameCode !== '' => $nameCode,
            default => "Historical actor unavailable (Staff #{$staffId})",
        };

        return [
            'staff_id' => $staffId > 0 ? $staffId : null,
            'full_name' => $fullName,
            'name_code' => $nameCode,
            'display' => $display,
        ];
    }

    private function paymentDetailQuery(int $paymentId)
    {
        return DB::table('vendor_payments as vp')
            ->leftJoin('vendor_main_details as vmd', 'vp.vendor_id', '=', 'vmd.vendor_id')
            ->leftJoin('projects_main as pm', 'vp.project_id', '=', 'pm.id')
            ->leftJoin('staff_general as sg_created', 'vp.created_by', '=', 'sg_created.staff_id')
            ->leftJoin('staff_general as sg_checked', 'vp.checked_by', '=', 'sg_checked.staff_id')
            ->leftJoin('staff_general as sg_approved', 'vp.approved_by', '=', 'sg_approved.staff_id')
            ->leftJoin('staff_general as sg_paid', 'vp.paid_by', '=', 'sg_paid.staff_id')
            ->where('vp.id', $paymentId)
            ->whereNull('vp.deleted_at')
            ->select([
                'vp.*',
                'vmd.vendor_name',
                'pm.project_name',
                $this->projectDescriptionColumn(),
                DB::raw('COALESCE(vp.created_by_full_name, sg_created.full_name) as created_by_full_name'),
                DB::raw('COALESCE(vp.created_by_name_code, sg_created.name_code) as created_by_name_code'),
                DB::raw('sg_checked.full_name as checked_by_full_name'),
                DB::raw('sg_checked.name_code as checked_by_name_code'),
                DB::raw('sg_approved.full_name as approved_by_full_name'),
                DB::raw('sg_approved.name_code as approved_by_name_code'),
                DB::raw('sg_paid.full_name as paid_by_full_name'),
                DB::raw('sg_paid.name_code as paid_by_name_code'),
            ]);
    }

    private function paymentWorkflowProgress(array $row): array
    {
        $raw = $row['workflow_progress_json'] ?? null;
        $progress = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        if (! is_array($progress) || empty($progress)) {
            return [];
        }

        $staffIds = collect($progress)
            ->map(fn ($entry): int => (int) ($entry['staff_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $staffById = empty($staffIds) || ! Schema::hasTable('staff_general')
            ? collect()
            : DB::table('staff_general')
                ->whereIn('staff_id', $staffIds)
                ->select(['staff_id', 'full_name', 'name_code'])
                ->get()
                ->keyBy('staff_id');

        return collect($progress)
            ->map(function ($entry) use ($staffById): array {
                $stage = (string) ($entry['stage_type'] ?? '');
                $level = (int) ($entry['level_no'] ?? 0);
                $staffId = (int) ($entry['staff_id'] ?? 0);
                $staff = $staffById->get($staffId);
                $label = match ($stage) {
                    VendorPaymentWorkflowService::STAGE_REVIEW => $level > 1 ? "Review Level {$level}" : 'Review',
                    VendorPaymentWorkflowService::STAGE_APPROVAL => $level > 1 ? "Approval Level {$level}" : 'Approval',
                    VendorPaymentWorkflowService::STAGE_FINANCE => 'Finance',
                    default => 'Workflow',
                };
                $status = match ($stage) {
                    VendorPaymentWorkflowService::STAGE_REVIEW => 'Reviewed',
                    VendorPaymentWorkflowService::STAGE_APPROVAL => 'Approved',
                    VendorPaymentWorkflowService::STAGE_FINANCE => 'Paid',
                    default => 'Completed',
                };

                return [
                    'stageType' => $stage,
                    'levelNo' => $level,
                    'label' => $label,
                    'status' => $status,
                    'staffId' => $staffId ?: null,
                    'actorName' => (string) ($staff->full_name ?? ''),
                    'actorCode' => (string) ($staff->name_code ?? ''),
                    'remarks' => (string) ($entry['remarks'] ?? ''),
                    'completedAt' => $entry['completed_at'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function paymentWorkflowFlow(array $row, array $progress): array
    {
        return $this->workflowFlowPresenter()->build($row, $progress);
    }

    private function applyWorkflowTransition(
        int $paymentId,
        string $expectedStatus,
        array $updates,
        ?string $levelColumn = null,
        ?int $expectedLevel = null,
    ): bool {
        $query = DB::table('vendor_payments')
            ->where('id', $paymentId)
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(COALESCE(status, '')) = ?", [strtolower($expectedStatus)]);

        if ($levelColumn !== null && $expectedLevel !== null && Schema::hasColumn('vendor_payments', $levelColumn)) {
            $query->whereRaw("COALESCE({$levelColumn}, 1) = ?", [$expectedLevel]);
        }

        return $query->update($updates) === 1;
    }

    private function transitionConflictResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Payment changed before this action was completed. Please refresh and try again.',
        ], 409);
    }

    public function vendorPayments(GetVendorPaymentsRequest $request)
    {
        $data = $request->validated();
        $vendorId = (int) $data['vendor_id'];
        $perPage = $this->resolvePerPage($data, 50);

        $query = DB::table('vendor_payments as vp')
            ->leftJoin('projects_main as pm', 'vp.project_id', '=', 'pm.id')
            ->where('vp.vendor_id', $vendorId)
            ->whereNull('vp.deleted_at')
            ->select([
                'vp.id',
                'vp.vendor_id',
                'vp.project_id',
                'vp.payment_context',
                'vp.remarks',
                'vp.amount',
                'vp.method',
                'vp.status',
                'vp.created_at',
                'vp.date_approved',
                $this->vendorPaymentColumn('checked_by'),
                $this->vendorPaymentColumn('checked_at'),
                $this->vendorPaymentColumn('checker_remarks'),
                $this->vendorPaymentColumn('approval_remarks'),
                $this->vendorPaymentColumn('returned_by'),
                $this->vendorPaymentColumn('returned_at'),
                $this->vendorPaymentColumn('returned_remarks'),
                $this->vendorPaymentColumn('rejected_by'),
                $this->vendorPaymentColumn('rejected_at'),
                $this->vendorPaymentColumn('rejected_remarks'),
                $this->vendorPaymentColumn('paid_date'),
                $this->vendorPaymentColumn('paid_amount'),
                $this->vendorPaymentColumn('paid_by'),
                $this->vendorPaymentColumn('paid_at'),
                $this->vendorPaymentColumn('paid_remarks'),
                'vp.payment_type',
                'vp.receipt_path',
                'vp.created_by',
                'vp.created_by_full_name',
                'vp.created_by_name_code',
                'pm.project_name',
                $this->projectDescriptionColumn(),
            ]);

        if (! empty($data['year'])) {
            $query->whereYear('vp.created_at', (int) $data['year']);
        }

        $paginator = $query->orderBy('vp.created_at', 'asc')->paginate($perPage);

        $history = collect($paginator->items())
            ->map(fn ($row) => $this->normalizePaymentRow((array) $row))
            ->values()
            ->all();

        $outstanding = (float) DB::table('vendor_payments')
            ->where('vendor_id', $vendorId)
            ->whereNull('deleted_at')
            ->where('status', 'Approved')
            ->when(! empty($data['year']), fn ($query) => $query->whereYear('created_at', (int) $data['year']))
            ->sum('amount');

        return response()->json([
            'status' => 'success',
            'outstanding' => $outstanding,
            'history' => $history,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function listPayments(ListVendorPaymentsRequest $request)
    {
        $data = $request->validated();
        $perPage = $this->resolvePerPage($data, 50);

        $query = DB::table('vendor_payments as vp')
            ->leftJoin('vendor_main_details as vmd', 'vp.vendor_id', '=', 'vmd.vendor_id')
            ->leftJoin('projects_main as pm', 'vp.project_id', '=', 'pm.id')
            ->leftJoin('staff_general as sg_created', 'vp.created_by', '=', 'sg_created.staff_id')
            ->leftJoin('staff_general as sg_checked', 'vp.checked_by', '=', 'sg_checked.staff_id')
            ->leftJoin('staff_general as sg_approved', 'vp.approved_by', '=', 'sg_approved.staff_id')
            ->leftJoin('staff_general as sg_paid', 'vp.paid_by', '=', 'sg_paid.staff_id')
            ->whereNull('vp.deleted_at')
            ->select([
                'vp.id',
                'vp.vendor_id',
                'vmd.vendor_name',
                'vp.project_id',
                'pm.project_name',
                $this->projectDescriptionColumn(),
                'vp.payment_context',
                'vp.remarks',
                'vp.amount',
                'vp.method',
                'vp.status',
                'vp.created_at',
                'vp.date_approved',
                $this->vendorPaymentColumn('checked_by'),
                $this->vendorPaymentColumn('checked_at'),
                $this->vendorPaymentColumn('checker_remarks'),
                $this->vendorPaymentColumn('approval_remarks'),
                $this->vendorPaymentColumn('returned_by'),
                $this->vendorPaymentColumn('returned_at'),
                $this->vendorPaymentColumn('returned_remarks'),
                $this->vendorPaymentColumn('rejected_by'),
                $this->vendorPaymentColumn('rejected_at'),
                $this->vendorPaymentColumn('rejected_remarks'),
                $this->vendorPaymentColumn('paid_date'),
                $this->vendorPaymentColumn('paid_amount'),
                $this->vendorPaymentColumn('paid_by'),
                $this->vendorPaymentColumn('paid_at'),
                $this->vendorPaymentColumn('paid_remarks'),
                $this->vendorPaymentColumn('current_review_level'),
                $this->vendorPaymentColumn('current_approval_level'),
                $this->vendorPaymentColumn('workflow_progress_json'),
                $this->vendorPaymentColumn('workflow_settings_snapshot_json'),
                $this->vendorPaymentColumn('version'),
                $this->vendorPaymentColumn('project_vendor_assignment_id'),
                $this->vendorPaymentColumn('parent_payment_id'),
                $this->vendorPaymentColumn('revision_number'),
                $this->vendorPaymentColumn('superseded_by_payment_id'),
                $this->vendorPaymentColumn('cancelled_at'),
                $this->vendorPaymentColumn('cancelled_by'),
                $this->vendorPaymentColumn('cancellation_reason'),
                $this->vendorPaymentColumn('vendor_name_snapshot'),
                $this->vendorPaymentColumn('project_name_snapshot'),
                $this->vendorPaymentColumn('client_name_snapshot'),
                $this->vendorPaymentColumn('payment_terms_snapshot'),
                $this->vendorPaymentColumn('award_value_snapshot'),
                $this->vendorPaymentColumn('receipt_original_name'),
                $this->vendorPaymentColumn('receipt_mime_type'),
                $this->vendorPaymentColumn('receipt_size'),
                $this->vendorPaymentColumn('receipt_state'),
                'vp.payment_type',
                'vp.receipt_path',
                'vp.created_by',
                DB::raw('COALESCE(vp.created_by_full_name, sg_created.full_name) as created_by_full_name'),
                DB::raw('COALESCE(vp.created_by_name_code, sg_created.name_code) as created_by_name_code'),
                'vp.approved_by',
                DB::raw('sg_checked.full_name as checked_by_full_name'),
                DB::raw('sg_checked.name_code as checked_by_name_code'),
                DB::raw('sg_approved.full_name as approved_by_full_name'),
                DB::raw('sg_approved.name_code as approved_by_name_code'),
                DB::raw('sg_paid.full_name as paid_by_full_name'),
                DB::raw('sg_paid.name_code as paid_by_name_code'),
            ]);

        if (! empty($data['year'])) {
            $query->whereYear('vp.created_at', (int) $data['year']);
        }

        $paginator = $query->orderBy('vp.created_at', 'desc')->paginate($perPage);

        $history = collect($paginator->items())
            ->map(fn ($row) => $this->normalizePaymentRowForRequest((array) $row, $request))
            ->values()
            ->all();

        $roles = $request->session()->get('roles', []);
        if (! is_array($roles)) {
            $roles = [$roles];
        }

        return response()->json([
            'status' => 'success',
            'staff' => [
                'staff_id' => $request->session()->get('staff_id'),
                'roles' => $roles,
                'full_name' => $request->session()->get('full_name', '-'),
                'name_code' => $request->session()->get('name_code', '-'),
            ],
            'history' => $history,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function paidPaymentsByVendor(ListVendorPaymentsRequest $request)
    {
        $data = $request->validated();
        $perPage = $this->resolvePerPage($data, 50);

        $query = DB::table('vendor_payments as vp')
            ->leftJoin('vendor_main_details as vmd', 'vp.vendor_id', '=', 'vmd.vendor_id')
            ->whereNull('vp.deleted_at')
            ->whereIn(DB::raw("LOWER(COALESCE(vp.status, ''))"), ['paid', 'partially paid'])
            ->when(Schema::hasColumn('vendor_payments', 'paid_date'), fn ($query) => $query->whereNotNull('vp.paid_date'))
            ->select([
                'vp.vendor_id',
                'vmd.vendor_name',
                DB::raw('COUNT(*) as paid_count'),
                Schema::hasColumn('vendor_payments', 'paid_amount')
                    ? DB::raw('COALESCE(SUM(COALESCE(vp.paid_amount, vp.amount)), 0) as total_paid')
                    : DB::raw('COALESCE(SUM(vp.amount), 0) as total_paid'),
                Schema::hasColumn('vendor_payments', 'paid_date')
                    ? DB::raw('MAX(vp.paid_date) as last_paid_date')
                    : DB::raw('NULL as last_paid_date'),
            ])
            ->groupBy('vp.vendor_id', 'vmd.vendor_name');

        if (! empty($data['year']) && Schema::hasColumn('vendor_payments', 'paid_date')) {
            $query->whereYear('vp.paid_date', (int) $data['year']);
        }

        $paginator = $query->orderByDesc('last_paid_date')->paginate($perPage);
        $items = collect($paginator->items())->map(function ($row) {
            $row->vendor_name = $row->vendor_name ?: 'Vendor #'.$row->vendor_id;

            return $row;
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function paidPaymentsForVendor(ListVendorPaymentsRequest $request, int $vendorId)
    {
        $data = $request->validated();
        $perPage = $this->resolvePerPage($data, 50);

        $query = DB::table('vendor_payments as vp')
            ->leftJoin('vendor_main_details as vmd', 'vp.vendor_id', '=', 'vmd.vendor_id')
            ->leftJoin('projects_main as pm', 'vp.project_id', '=', 'pm.id')
            ->where('vp.vendor_id', $vendorId)
            ->whereNull('vp.deleted_at')
            ->whereIn(DB::raw("LOWER(COALESCE(vp.status, ''))"), ['paid', 'partially paid'])
            ->when(Schema::hasColumn('vendor_payments', 'paid_date'), fn ($query) => $query->whereNotNull('vp.paid_date'));

        if (Schema::hasColumn('vendor_payments', 'paid_by') && Schema::hasTable('staff_general')) {
            $query->leftJoin('staff_general as sg_paid', 'vp.paid_by', '=', 'sg_paid.staff_id');
        }

        $query->select([
            'vp.id',
            'vp.vendor_id',
            'vmd.vendor_name',
            'vp.project_id',
            'pm.project_name',
            'vp.payment_context',
            'vp.remarks',
            'vp.amount',
            'vp.method',
            'vp.status',
            'vp.created_at',
            'vp.date_approved',
            $this->vendorPaymentColumn('paid_date'),
            $this->vendorPaymentColumn('paid_amount'),
            $this->vendorPaymentColumn('paid_by'),
            Schema::hasColumn('vendor_payments', 'paid_by') && Schema::hasTable('staff_general')
                ? DB::raw('sg_paid.name_code as paid_by_name_code')
                : DB::raw('NULL as paid_by_name_code'),
            $this->vendorPaymentColumn('paid_remarks'),
            'vp.payment_type',
            'vp.receipt_path',
            'vp.created_by',
            'vp.created_by_full_name',
            'vp.created_by_name_code',
        ]);

        if (! empty($data['year']) && Schema::hasColumn('vendor_payments', 'paid_date')) {
            $query->whereYear('vp.paid_date', (int) $data['year']);
        }

        $paginator = $query->orderByDesc(Schema::hasColumn('vendor_payments', 'paid_date') ? 'vp.paid_date' : 'vp.created_at')->paginate($perPage);
        $rows = collect($paginator->items())
            ->map(fn ($row) => $this->normalizePaymentRow((array) $row))
            ->values()
            ->all();

        return response()->json([
            'status' => 'success',
            'data' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function storePayment(StoreVendorPaymentRequest $request)
    {
        return $this->lifecycle()->store($request);
    }

    public function showPayment(Request $request, int $paymentId): JsonResponse
    {
        $row = $this->paymentDetailQuery($paymentId)->first();
        if (! $row) {
            return response()->json(['status' => 'error', 'message' => 'Payment record not found.'], 404);
        }

        $payment = $this->normalizePaymentRowForRequest((array) $row, $request);
        $payment['transactions'] = $this->lifecycle()->transactions($paymentId);
        $payment['events'] = Schema::hasTable('vendor_payment_events')
            ? DB::table('vendor_payment_events')->where('vendor_payment_id', $paymentId)->orderByDesc('created_at')->get()->map(fn ($event) => (array) $event)->all()
            : [];

        return response()->json(['status' => 'success', 'data' => $payment]);
    }

    public function paymentInvoice(Request $request, int $paymentId)
    {
        $payment = DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at')->first();
        if (! $payment || empty($payment->receipt_path) || ! AppFilePaths::storedPathExists((string) $payment->receipt_path)) {
            return response()->json(['status' => 'error', 'message' => 'Invoice attachment is unavailable.'], 404);
        }

        $localPath = AppFilePaths::storedPathLocalPath((string) $payment->receipt_path);
        $expectedHash = strtolower(trim((string) ($payment->receipt_sha256 ?? '')));
        if ($expectedHash !== '' && $localPath !== null) {
            $actualHash = hash_file('sha256', $localPath);
            if (! is_string($actualHash) || ! hash_equals($expectedHash, strtolower($actualHash))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invoice attachment failed integrity verification.',
                ], 409);
            }
        }

        $response = AppFilePaths::storedPathResponse(
            (string) $payment->receipt_path,
            (string) (($payment->receipt_original_name ?? null) ?: basename((string) $payment->receipt_path)),
        );
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function updatePayment(UpdateVendorPaymentRequest $request, int $paymentId): JsonResponse
    {
        return $this->lifecycle()->update($request, $paymentId);
    }

    public function cancelPayment(CancelVendorPaymentRequest $request, int $paymentId): JsonResponse
    {
        return $this->lifecycle()->cancel($request, $paymentId);
    }

    public function resubmitPayment(ResubmitVendorPaymentRequest $request, int $paymentId): JsonResponse
    {
        return $this->lifecycle()->resubmit($request, $paymentId);
    }

    public function recordPaymentTransaction(RecordVendorPaymentTransactionRequest $request, int $paymentId): JsonResponse
    {
        return $this->lifecycle()->recordTransaction($request, $paymentId);
    }

    public function reversePaymentTransaction(
        ReverseVendorPaymentTransactionRequest $request,
        int $paymentId,
        int $transactionId,
    ): JsonResponse {
        return $this->lifecycle()->reverseTransaction($request, $paymentId, $transactionId);
    }

    public function checkPayment(CheckVendorPaymentRequest $request, ?int $id = null)
    {
        $data = $request->validated();
        $staffId = (int) $request->session()->get('staff_id', 0);
        $workflow = $this->workflow();

        if ($id !== null && $id > 0 && isset($data['id']) && (int) $data['id'] !== $id) {
            return response()->json(['status' => 'error', 'message' => 'Payment ID mismatch.'], 409);
        }

        $paymentId = $this->resolveId($id, $data, 'id');
        if (! $paymentId) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid payment ID'], 400);
        }
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }
        $payment = DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at')->first();
        if (! $payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment record not found.'], 404);
        }
        if (! $workflow->stageEnabledForPayment($payment, VendorPaymentWorkflowService::STAGE_REVIEW)) {
            return response()->json(['status' => 'error', 'message' => 'Review is not enabled for vendor payments.'], 409);
        }
        if ($this->normalizeStatus($payment->status ?? '') !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'Only pending payments can be reviewed.'], 409);
        }
        $level = $workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_REVIEW);
        if (! $this->authorization()->can($request, $payment, 'can_check')) {
            return response()->json(['status' => 'error', 'message' => 'You cannot review a payment you created or are not assigned to review.'], 403);
        }

        $remarks = trim((string) ($data['remarks'] ?? '')) ?: null;
        $reviewLevels = $workflow->stageLevelsForPayment($payment, VendorPaymentWorkflowService::STAGE_REVIEW);
        $approvalEnabled = $workflow->stageEnabledForPayment($payment, VendorPaymentWorkflowService::STAGE_APPROVAL);
        $isFinalReview = $level >= $reviewLevels;

        $baseUpdates = [
            'status' => $isFinalReview
                ? ($approvalEnabled ? 'Checked' : 'Approved')
                : 'Pending',
        ];

        if ($isFinalReview && ! $approvalEnabled) {
            $baseUpdates['date_approved'] = now();
            $baseUpdates['approved_by'] = $staffId;
        }

        $updates = array_merge($baseUpdates, $this->optionalUpdateColumns([
            'checked_by' => $staffId,
            'checked_at' => now(),
            'checker_remarks' => $remarks,
            'current_review_level' => $isFinalReview ? $level : $level + 1,
            'current_approval_level' => $isFinalReview && $approvalEnabled ? 1 : ($payment->current_approval_level ?? null),
            'workflow_progress_json' => $workflow->appendProgress($payment, VendorPaymentWorkflowService::STAGE_REVIEW, $level, $staffId, $remarks),
            'version' => (int) ($payment->version ?? 1) + 1,
            'updated_at' => now(),
            'updated_by' => $staffId,
        ]));

        if (! $this->applyWorkflowTransition($paymentId, 'Pending', $updates, 'current_review_level', $level)) {
            return $this->transitionConflictResponse();
        }

        $this->resolvePaymentNotifications($paymentId);

        if (! $isFinalReview) {
            $workflow->notifyPaymentStage($request, $payment, $paymentId, VendorPaymentWorkflowService::STAGE_REVIEW, $level + 1, [
                'type' => 'vendor_payment_review_requested',
                'title' => 'Vendor payment requires review',
                'message' => "Payment request #{$paymentId} is ready for review level ".($level + 1).'.',
                'severity' => 'warning',
            ]);
        } elseif ($approvalEnabled) {
            $workflow->notifyPaymentStage($request, $payment, $paymentId, VendorPaymentWorkflowService::STAGE_APPROVAL, 1, [
                'type' => 'vendor_payment_checked',
                'title' => 'Vendor payment ready for approval',
                'message' => "Payment request #{$paymentId} has completed review.",
                'severity' => 'primary',
            ]);
        } else {
            $workflow->notifyPaymentStage($request, $payment, $paymentId, VendorPaymentWorkflowService::STAGE_FINANCE, 1, [
                'type' => 'vendor_payment_finance_requested',
                'title' => 'Vendor payment ready for finance',
                'message' => "Payment request #{$paymentId} is ready for finance payment.",
                'severity' => 'primary',
            ]);
        }

        $this->auditLog->log($request, "Reviewed payment ID #{$paymentId}");

        return response()->json([
            'status' => 'success',
            'message' => $isFinalReview
                ? ($approvalEnabled ? 'Payment reviewed.' : 'Payment approved.')
                : 'Payment review level completed.',
        ]);
    }

    public function approvePayment(ApproveVendorPaymentRequest $request, ?int $id = null)
    {
        $data = $request->validated();
        $staffId = (int) $request->session()->get('staff_id', 0);
        $workflow = $this->workflow();

        if ($id !== null && $id > 0 && isset($data['id']) && (int) $data['id'] !== $id) {
            return response()->json(['status' => 'error', 'message' => 'Payment ID mismatch.'], 409);
        }

        $paymentId = $this->resolveId($id, $data, 'id');

        if (! $paymentId) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid payment ID'], 400);
        }
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }
        $payment = DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at')->first();
        if (! $payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment record not found.'], 404);
        }
        if (! $workflow->stageEnabledForPayment($payment, VendorPaymentWorkflowService::STAGE_APPROVAL)) {
            return response()->json(['status' => 'error', 'message' => 'Approval is not enabled for vendor payments.'], 409);
        }
        $level = $workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_APPROVAL);
        if (! $workflow->canActForPayment($request, $payment, VendorPaymentWorkflowService::STAGE_APPROVAL, $level)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }
        if ($this->normalizeStatus($payment->status ?? '') !== 'checked') {
            return response()->json(['status' => 'error', 'message' => 'Only checked payments can be approved.'], 409);
        }
        if (! $this->authorization()->can($request, $payment, 'can_approve')) {
            return response()->json(['status' => 'error', 'message' => 'The requester or a prior reviewer cannot approve this payment.'], 403);
        }

        $remarks = trim((string) ($data['remarks'] ?? '')) ?: null;
        $approvalLevels = $workflow->stageLevelsForPayment($payment, VendorPaymentWorkflowService::STAGE_APPROVAL);
        $isFinalApproval = $level >= $approvalLevels;

        $updates = array_merge([
            'status' => $isFinalApproval ? 'Approved' : 'Checked',
        ], $isFinalApproval ? [
            'date_approved' => now(),
            'approved_by' => $staffId,
        ] : [], $this->optionalUpdateColumns([
            'approval_remarks' => $remarks,
            'current_approval_level' => $isFinalApproval ? $level : $level + 1,
            'workflow_progress_json' => $workflow->appendProgress($payment, VendorPaymentWorkflowService::STAGE_APPROVAL, $level, $staffId, $remarks),
            'version' => (int) ($payment->version ?? 1) + 1,
            'updated_at' => now(),
            'updated_by' => $staffId,
        ]));

        if (! $this->applyWorkflowTransition($paymentId, 'Checked', $updates, 'current_approval_level', $level)) {
            return $this->transitionConflictResponse();
        }

        $this->resolvePaymentNotifications($paymentId);
        if ($isFinalApproval) {
            $workflow->notifyPaymentStage($request, $payment, $paymentId, VendorPaymentWorkflowService::STAGE_FINANCE, 1, [
                'type' => 'vendor_payment_finance_requested',
                'title' => 'Vendor payment ready for finance',
                'message' => "Payment request #{$paymentId} is ready for finance payment.",
                'severity' => 'primary',
            ]);
        } else {
            $workflow->notifyPaymentStage($request, $payment, $paymentId, VendorPaymentWorkflowService::STAGE_APPROVAL, $level + 1, [
                'type' => 'vendor_payment_approval_requested',
                'title' => 'Vendor payment requires approval',
                'message' => "Payment request #{$paymentId} is ready for approval level ".($level + 1).'.',
                'severity' => 'primary',
            ]);
        }

        $this->auditLog->log($request, "Approved payment ID #{$paymentId}");

        return response()->json([
            'status' => 'success',
            'message' => $isFinalApproval ? 'Payment approved.' : 'Payment approval level completed.',
        ]);
    }

    public function rejectPayment(DecideVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->decidePayment($request, $id, 'Rejected');
    }

    public function returnPayment(DecideVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->decidePayment($request, $id, 'Returned');
    }

    private function decidePayment(DecideVendorPaymentRequest $request, ?int $id, string $decision)
    {
        $data = $request->validated();
        $staffId = (int) $request->session()->get('staff_id', 0);

        if ($id !== null && $id > 0 && isset($data['id']) && (int) $data['id'] !== $id) {
            return response()->json(['status' => 'error', 'message' => 'Payment ID mismatch.'], 409);
        }

        $paymentId = $this->resolveId($id, $data, 'id');
        if (! $paymentId) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid payment ID'], 400);
        }
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $payment = DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at')->first();
        if (! $payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment record not found.'], 404);
        }
        $capability = $decision === 'Returned' ? 'can_return' : 'can_reject';
        if (! $this->authorization()->can($request, $payment, $capability)) {
            return response()->json(['status' => 'error', 'message' => 'You cannot decide a payment you created or are not assigned to act on.'], 403);
        }
        $status = $this->normalizeStatus($payment->status ?? '');
        if (! in_array($status, ['pending', 'checked'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Only pending or checked payments can be returned or rejected.'], 409);
        }

        $prefix = $decision === 'Returned' ? 'returned' : 'rejected';
        $updates = array_merge([
            'status' => $decision,
        ], $this->optionalUpdateColumns([
            "{$prefix}_by" => $staffId,
            "{$prefix}_at" => now(),
            "{$prefix}_remarks" => trim((string) ($data['remarks'] ?? '')) ?: null,
            'version' => (int) ($payment->version ?? 1) + 1,
            'updated_at' => now(),
            'updated_by' => $staffId,
        ]));

        if (! $this->applyWorkflowTransition($paymentId, $status, $updates)) {
            return $this->transitionConflictResponse();
        }

        $this->resolvePaymentNotifications($paymentId);
        $this->notifyRequester($request, $payment, [
            'type' => 'vendor_payment_'.strtolower($decision),
            'title' => "Vendor payment {$decision}",
            'message' => "Payment request #{$paymentId} has been {$decision}.",
            'severity' => $decision === 'Rejected' ? 'danger' : 'warning',
        ]);

        $this->auditLog->log($request, "{$decision} payment ID #{$paymentId}");

        return response()->json(['status' => 'success', 'message' => "Payment {$decision}."]);
    }

    public function markPaymentPaid(MarkVendorPaymentPaidRequest $request, ?int $id = null)
    {
        $data = $request->validated();
        $staffId = (int) $request->session()->get('staff_id', 0);

        if ($id !== null && $id > 0 && isset($data['id']) && (int) $data['id'] !== $id) {
            return response()->json(['status' => 'error', 'message' => 'Payment ID mismatch.'], 409);
        }

        $paymentId = $this->resolveId($id, $data, 'id');
        if (! $paymentId) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid payment ID'], 400);
        }
        $payment = DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at')->first();
        if (! $payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment record not found.'], 404);
        }
        if ($staffId <= 0 || ! $this->authorization()->can($request, $payment, 'can_record_payment')) {
            return response()->json(['status' => 'error', 'message' => 'The requester or a prior reviewer cannot record this payment.'], 403);
        }
        if ($this->normalizeStatus($payment->status ?? '') !== 'approved') {
            return response()->json(['status' => 'error', 'message' => 'Only approved payments can be marked paid.'], 409);
        }

        $updates = array_merge([
            'status' => 'Paid',
        ], $this->optionalUpdateColumns([
            'paid_date' => $data['paid_date'],
            'paid_amount' => $data['paid_amount'] ?? $payment->amount ?? null,
            'paid_by' => $staffId,
            'paid_at' => now(),
            'paid_remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
            'version' => (int) ($payment->version ?? 1) + 1,
            'updated_at' => now(),
            'updated_by' => $staffId,
        ]));

        if (! $this->applyWorkflowTransition($paymentId, 'Approved', $updates)) {
            return $this->transitionConflictResponse();
        }

        if (Schema::hasTable('vendor_payment_transactions')) {
            DB::table('vendor_payment_transactions')->insert([
                'vendor_payment_id' => $paymentId,
                'idempotency_key' => (string) Str::uuid(),
                'amount' => $data['paid_amount'] ?? $payment->amount,
                'paid_date' => $data['paid_date'],
                'method' => $payment->method ?? null,
                'reference_number' => 'LEGACY-'.strtoupper(Str::random(12)),
                'remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
                'created_by' => $staffId,
                'created_at' => now(),
            ]);
        }

        $this->resolvePaymentNotifications($paymentId);
        $this->notifyRequester($request, $payment, [
            'type' => 'vendor_payment_paid',
            'title' => 'Vendor payment marked paid',
            'message' => "Payment request #{$paymentId} has been marked paid.",
            'severity' => 'success',
        ]);

        $this->auditLog->log($request, "Marked payment ID #{$paymentId} as Paid");

        return response()->json(['status' => 'success', 'message' => 'Payment marked paid.']);
    }

    public function deletePayment(DeleteVendorPaymentRequest $request, ?int $id = null)
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Payment requests are retained for audit. Cancel an eligible request instead.',
        ], 403);
    }
}
