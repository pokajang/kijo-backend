<?php

namespace App\Services\Vendors;

use App\Http\Requests\Vendor\ApproveVendorPaymentRequest;
use App\Http\Requests\Vendor\CheckVendorPaymentRequest;
use App\Http\Requests\Vendor\DecideVendorPaymentRequest;
use App\Http\Requests\Vendor\DeleteVendorPaymentRequest;
use App\Http\Requests\Vendor\GetVendorPaymentsRequest;
use App\Http\Requests\Vendor\ListVendorPaymentsRequest;
use App\Http\Requests\Vendor\MarkVendorPaymentPaidRequest;
use App\Http\Requests\Vendor\StoreVendorPaymentRequest;
use App\Services\AppNotificationService;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VendorPaymentService extends VendorBaseService
{
    private const NOTIFICATION_MODULE = 'vendor.payments';

    private const NOTIFICATION_ENTITY = 'vendor_payment';

    private const CHECK_APPROVE_ROLES = ['Manager', 'System Admin'];

    private const PAID_ROLES = ['Manager', 'System Admin', 'Finance', 'Account', 'Bank'];

    private function notifications(): AppNotificationService
    {
        return app(AppNotificationService::class);
    }

    private function workflow(): VendorPaymentWorkflowService
    {
        return app(VendorPaymentWorkflowService::class);
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

        if ($status === 'pending' && $workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_REVIEW)) {
            return [
                'stage_type' => VendorPaymentWorkflowService::STAGE_REVIEW,
                'level_no' => $workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_REVIEW),
            ];
        }

        if ($status === 'checked' && $workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_APPROVAL)) {
            return [
                'stage_type' => VendorPaymentWorkflowService::STAGE_APPROVAL,
                'level_no' => $workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_APPROVAL),
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

        return $this->workflow()->canAct($request, $stage['stage_type'], $stage['level_no']);
    }

    private function canMarkPaid(Request $request): bool
    {
        $workflow = $this->workflow();
        if ($workflow->hasConfiguredRecipients(VendorPaymentWorkflowService::STAGE_FINANCE, 1)) {
            return $workflow->canAct($request, VendorPaymentWorkflowService::STAGE_FINANCE, 1);
        }

        return $this->canRole($request, self::PAID_ROLES);
    }

    private function paymentPermissions(Request $request, object $payment): array
    {
        $workflow = $this->workflow();
        $staffId = (int) $request->session()->get('staff_id', 0);
        $status = $this->normalizeStatus($payment->status ?? '');
        $canReview = false;
        $canApprove = false;

        if ($status === 'pending' && $workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_REVIEW)) {
            $reviewLevel = $workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_REVIEW);
            $canReview = $staffId > 0
                && $workflow->canAct($request, VendorPaymentWorkflowService::STAGE_REVIEW, $reviewLevel)
                && (int) ($payment->created_by ?? 0) !== $staffId;
        }

        if ($status === 'checked' && $workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_APPROVAL)) {
            $approvalLevel = $workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_APPROVAL);
            $canApprove = $staffId > 0
                && $workflow->canAct($request, VendorPaymentWorkflowService::STAGE_APPROVAL, $approvalLevel)
                && (int) ($payment->created_by ?? 0) !== $staffId
                && (int) ($payment->checked_by ?? 0) !== $staffId
                && ! $workflow->actorAlreadyCompleted($payment, $staffId, VendorPaymentWorkflowService::STAGE_REVIEW)
                && ! $workflow->actorAlreadyCompleted($payment, $staffId, VendorPaymentWorkflowService::STAGE_APPROVAL);
        }

        return [
            'can_check' => $canReview,
            'can_approve' => $canApprove,
            'can_return' => in_array($status, ['pending', 'checked'], true) && $this->canDecideCurrentStage($request, $payment),
            'can_reject' => in_array($status, ['pending', 'checked'], true) && $this->canDecideCurrentStage($request, $payment),
            'can_mark_paid' => $status === 'approved' && $this->canMarkPaid($request),
            'can_delete' => in_array($status, ['pending', 'checked'], true) && $this->canRole($request, self::CHECK_APPROVE_ROLES),
        ];
    }

    private function normalizePaymentRowForRequest(array $row, Request $request): array
    {
        $payment = (object) $row;

        return array_merge(
            $this->normalizePaymentRow($row),
            ['workflow_progress' => $this->paymentWorkflowProgress($row)],
            $this->paymentPermissions($request, $payment),
        );
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
            ->leftJoin('staff_general as sg_approved', 'vp.approved_by', '=', 'sg_approved.staff_id')
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
                'vp.payment_type',
                'vp.receipt_path',
                'vp.created_by',
                'vp.created_by_full_name',
                'vp.created_by_name_code',
                'vp.approved_by',
                DB::raw('sg_approved.name_code as approved_by_name_code'),
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
            ->whereRaw("LOWER(COALESCE(vp.status, '')) = ?", ['paid'])
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
            ->whereRaw("LOWER(COALESCE(vp.status, '')) = ?", ['paid'])
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
        $data = $request->validated();
        $staffId = (int) $request->session()->get('staff_id', 0);
        $fullName = (string) $request->session()->get('full_name', '');
        $nameCode = (string) $request->session()->get('name_code', '');

        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $year = now()->format('Y');
            $month = now()->format('m');
            $filename = 'receipt_'.Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
            $storedPath = "payments/{$year}/{$month}/{$filename}";

            $receiptPath = AppFilePaths::storeFileAs("payments/{$year}/{$month}", $file, $filename);
        }

        $workflow = $this->workflow();
        $initialStatus = $workflow->initialStatus();
        $workflowColumns = $this->optionalUpdateColumns([
            'current_review_level' => $workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_REVIEW) ? 1 : null,
            'current_approval_level' => $workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_APPROVAL) ? 1 : null,
            'workflow_progress_json' => json_encode([]),
            'workflow_settings_snapshot_json' => $workflow->snapshot(),
        ]);

        try {
            $paymentId = DB::table('vendor_payments')->insertGetId(array_merge([
                'vendor_id' => $data['vendor_id'],
                'project_id' => $data['project_id'] ?? null,
                'payment_context' => $data['payment_context'],
                'payment_type' => $data['payment_type'],
                'amount' => $data['amount'],
                'method' => $data['method'],
                'status' => $initialStatus,
                'remarks' => $data['remarks'] ?? '',
                'receipt_path' => $receiptPath,
                'created_by' => $staffId,
                'created_by_full_name' => $fullName,
                'created_by_name_code' => $nameCode,
            ], $workflowColumns));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Database error'], 500);
        }

        if ($workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_REVIEW)) {
            $workflow->notifyStage($request, $paymentId, VendorPaymentWorkflowService::STAGE_REVIEW, 1, [
                'type' => 'vendor_payment_submitted',
                'title' => 'Vendor payment requires review',
                'message' => "Payment request #{$paymentId} is pending reviewer action.",
                'severity' => 'warning',
            ]);
        } elseif ($workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_APPROVAL)) {
            $workflow->notifyStage($request, $paymentId, VendorPaymentWorkflowService::STAGE_APPROVAL, 1, [
                'type' => 'vendor_payment_checked',
                'title' => 'Vendor payment ready for approval',
                'message' => "Payment request #{$paymentId} is ready for approver action.",
                'severity' => 'primary',
            ]);
        } else {
            $workflow->notifyStage($request, $paymentId, VendorPaymentWorkflowService::STAGE_FINANCE, 1, [
                'type' => 'vendor_payment_finance_requested',
                'title' => 'Vendor payment ready for finance',
                'message' => "Payment request #{$paymentId} is ready for finance payment.",
                'severity' => 'primary',
            ]);
        }

        $this->auditLog->log($request, "Created vendor payment request #{$paymentId}");

        return response()->json(['status' => 'success', 'id' => $paymentId]);
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
        if (! $workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_REVIEW)) {
            return response()->json(['status' => 'error', 'message' => 'Review is not enabled for vendor payments.'], 409);
        }

        $payment = DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at')->first();
        if (! $payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment record not found.'], 404);
        }
        $level = $workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_REVIEW);
        if (! $workflow->canAct($request, VendorPaymentWorkflowService::STAGE_REVIEW, $level)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }
        if ($this->normalizeStatus($payment->status ?? '') !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'Only pending payments can be checked.'], 409);
        }
        if ((int) ($payment->created_by ?? 0) === $staffId) {
            return response()->json(['status' => 'error', 'message' => 'Requester cannot check their own payment request.'], 409);
        }

        $remarks = trim((string) ($data['remarks'] ?? '')) ?: null;
        $reviewLevels = $workflow->stageLevels(VendorPaymentWorkflowService::STAGE_REVIEW);
        $approvalEnabled = $workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_APPROVAL);
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
        ]));

        DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at')->update($updates);

        $this->resolvePaymentNotifications($paymentId);

        if (! $isFinalReview) {
            $workflow->notifyStage($request, $paymentId, VendorPaymentWorkflowService::STAGE_REVIEW, $level + 1, [
                'type' => 'vendor_payment_review_requested',
                'title' => 'Vendor payment requires review',
                'message' => "Payment request #{$paymentId} is ready for review level ".($level + 1).'.',
                'severity' => 'warning',
            ]);
        } elseif ($approvalEnabled) {
            $workflow->notifyStage($request, $paymentId, VendorPaymentWorkflowService::STAGE_APPROVAL, 1, [
                'type' => 'vendor_payment_checked',
                'title' => 'Vendor payment ready for approval',
                'message' => "Payment request #{$paymentId} has completed review.",
                'severity' => 'primary',
            ]);
        } else {
            $workflow->notifyStage($request, $paymentId, VendorPaymentWorkflowService::STAGE_FINANCE, 1, [
                'type' => 'vendor_payment_finance_requested',
                'title' => 'Vendor payment ready for finance',
                'message' => "Payment request #{$paymentId} is ready for finance payment.",
                'severity' => 'primary',
            ]);
        }

        $this->auditLog->log($request, "Checked payment ID #{$paymentId}");

        return response()->json([
            'status' => 'success',
            'message' => $isFinalReview
                ? ($approvalEnabled ? 'Payment checked.' : 'Payment approved.')
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
        if (! $workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_APPROVAL)) {
            return response()->json(['status' => 'error', 'message' => 'Approval is not enabled for vendor payments.'], 409);
        }

        $payment = DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at')->first();
        if (! $payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment record not found.'], 404);
        }
        $level = $workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_APPROVAL);
        if (! $workflow->canAct($request, VendorPaymentWorkflowService::STAGE_APPROVAL, $level)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }
        if ($this->normalizeStatus($payment->status ?? '') !== 'checked') {
            return response()->json(['status' => 'error', 'message' => 'Only checked payments can be approved.'], 409);
        }
        if ((int) ($payment->created_by ?? 0) === $staffId) {
            return response()->json(['status' => 'error', 'message' => 'Requester cannot approve their own payment request.'], 409);
        }
        if ((int) ($payment->checked_by ?? 0) === $staffId) {
            return response()->json(['status' => 'error', 'message' => 'Checker and approver must be different users.'], 409);
        }
        if ($workflow->actorAlreadyCompleted($payment, $staffId, VendorPaymentWorkflowService::STAGE_REVIEW)) {
            return response()->json(['status' => 'error', 'message' => 'Reviewer and approver must be different users.'], 409);
        }
        if ($workflow->actorAlreadyCompleted($payment, $staffId, VendorPaymentWorkflowService::STAGE_APPROVAL)) {
            return response()->json(['status' => 'error', 'message' => 'Approver has already approved this payment request.'], 409);
        }

        $remarks = trim((string) ($data['remarks'] ?? '')) ?: null;
        $approvalLevels = $workflow->stageLevels(VendorPaymentWorkflowService::STAGE_APPROVAL);
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
        ]));

        DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at')->update($updates);

        $this->resolvePaymentNotifications($paymentId);
        if ($isFinalApproval) {
            $workflow->notifyStage($request, $paymentId, VendorPaymentWorkflowService::STAGE_FINANCE, 1, [
                'type' => 'vendor_payment_finance_requested',
                'title' => 'Vendor payment ready for finance',
                'message' => "Payment request #{$paymentId} is ready for finance payment.",
                'severity' => 'primary',
            ]);
        } else {
            $workflow->notifyStage($request, $paymentId, VendorPaymentWorkflowService::STAGE_APPROVAL, $level + 1, [
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
        if (! $this->canDecideCurrentStage($request, $payment)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
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
        ]));

        DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at')->update($updates);

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
        if ($staffId <= 0 || ! $this->canMarkPaid($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $payment = DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at')->first();
        if (! $payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment record not found.'], 404);
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
        ]));

        DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at')->update($updates);

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
        $data = $request->validated();
        $staffId = (int) $request->session()->get('staff_id', 0);

        if ($id !== null && $id > 0 && isset($data['id']) && (int) $data['id'] !== $id) {
            return response()->json(['status' => 'error', 'message' => 'Payment ID mismatch.'], 409);
        }

        $paymentId = $this->resolveId($id, $data, 'id');

        if (! $paymentId) {
            return response()->json(['status' => 'error', 'message' => 'Missing payment ID'], 400);
        }
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $payment = DB::table('vendor_payments')
            ->where('id', $paymentId)
            ->whereNull('deleted_at')
            ->first();

        if (! $payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment record not found.'], 404);
        }

        if (in_array($this->normalizeStatus($payment->status ?? ''), ['approved', 'paid'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Approved or paid payments cannot be deleted.'], 409);
        }

        $affected = DB::table('vendor_payments')
            ->where('id', $paymentId)
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => now(),
                'deleted_by' => $staffId,
            ]);

        if ($affected < 1) {
            return response()->json(['status' => 'error', 'message' => 'No payment deleted or already deleted.']);
        }

        $this->resolvePaymentNotifications($paymentId);
        $this->auditLog->log($request, "Soft deleted payment ID #{$paymentId}");

        return response()->json(['status' => 'success', 'message' => 'Payment soft deleted.']);
    }
}
