<?php

namespace App\Services\Vendors;

use Illuminate\Http\Request;

class VendorPaymentAuthorizationService
{
    public function __construct(private VendorPaymentWorkflowService $workflow) {}

    public function permissions(Request $request, object $payment): array
    {
        $staffId = $this->staffId($request);
        $status = $this->status($payment);
        $isCreator = $staffId > 0 && $staffId === (int) ($payment->created_by ?? 0);
        $untouched = $this->workflowProgress($payment) === [];
        $canDecide = $this->canDecideCurrentStage($request, $payment);
        $canReview = $status === 'pending'
            && ! $isCreator
            && $this->workflow->stageEnabledForPayment($payment, VendorPaymentWorkflowService::STAGE_REVIEW)
            && $this->workflow->canActForPayment(
                $request,
                $payment,
                VendorPaymentWorkflowService::STAGE_REVIEW,
                $this->workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_REVIEW),
            );
        $hasReviewed = $this->hasCompletedStageByStaff(
            $payment,
            VendorPaymentWorkflowService::STAGE_REVIEW,
            $staffId,
        )
            || (int) ($payment->checked_by ?? 0) === $staffId;
        $canApprove = $status === 'checked'
            && ! $isCreator
            && ! $hasReviewed
            && $this->workflow->stageEnabledForPayment($payment, VendorPaymentWorkflowService::STAGE_APPROVAL)
            && $this->workflow->canActForPayment(
                $request,
                $payment,
                VendorPaymentWorkflowService::STAGE_APPROVAL,
                $this->workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_APPROVAL),
            );
        $canRecordPayment = in_array($status, ['approved', 'partially paid'], true)
            && ! $isCreator
            && ! $hasReviewed
            && $this->workflow->canActForPayment(
                $request,
                $payment,
                VendorPaymentWorkflowService::STAGE_FINANCE,
                1,
            );

        return [
            'can_view' => $staffId > 0,
            'can_edit' => $isCreator && $untouched && in_array($status, ['pending', 'checked'], true),
            'can_cancel' => ($isCreator && $untouched && in_array($status, ['pending', 'checked'], true))
                || ($this->isManager($request) && ! in_array($status, ['paid', 'partially paid', 'cancelled', 'superseded'], true)),
            'can_resubmit' => $isCreator
                && $status === 'returned'
                && empty($payment->superseded_by_payment_id),
            'can_check' => $canReview,
            'can_approve' => $canApprove,
            'can_return' => in_array($status, ['pending', 'checked'], true) && ! $isCreator && $canDecide,
            'can_reject' => in_array($status, ['pending', 'checked'], true) && ! $isCreator && $canDecide,
            'can_record_payment' => $canRecordPayment,
            'can_mark_paid' => $canRecordPayment,
            'can_reverse_payment' => $this->isManagerOrFinance($request)
                && in_array($status, ['paid', 'partially paid'], true),
            'can_delete' => false,
            'can_admin_override' => $this->isSystemAdmin($request),
        ];
    }

    public function can(Request $request, object $payment, string $capability): bool
    {
        return (bool) ($this->permissions($request, $payment)[$capability] ?? false);
    }

    public function isSystemAdmin(Request $request): bool
    {
        return $this->hasRole($request, ['System Admin']);
    }

    private function canDecideCurrentStage(Request $request, object $payment): bool
    {
        $status = $this->status($payment);
        if ($status === 'pending' && $this->workflow->stageEnabledForPayment($payment, VendorPaymentWorkflowService::STAGE_REVIEW)) {
            return $this->workflow->canActForPayment(
                $request,
                $payment,
                VendorPaymentWorkflowService::STAGE_REVIEW,
                $this->workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_REVIEW),
            );
        }

        if ($status === 'checked' && $this->workflow->stageEnabledForPayment($payment, VendorPaymentWorkflowService::STAGE_APPROVAL)) {
            return $this->workflow->canActForPayment(
                $request,
                $payment,
                VendorPaymentWorkflowService::STAGE_APPROVAL,
                $this->workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_APPROVAL),
            );
        }

        return false;
    }

    private function hasCompletedStageByStaff(object $payment, string $stageType, int $staffId): bool
    {
        if ($staffId <= 0) {
            return false;
        }
        foreach ($this->workflowProgress($payment) as $entry) {
            if (
                (string) ($entry['stage_type'] ?? '') === $stageType
                && (int) ($entry['staff_id'] ?? 0) === $staffId
            ) {
                return true;
            }
        }

        return false;
    }

    private function workflowProgress(object $payment): array
    {
        $raw = $payment->workflow_progress_json ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);

        return is_array($decoded) ? $decoded : [];
    }

    private function staffId(Request $request): int
    {
        return (int) $request->session()->get('staff_id', 0);
    }

    private function status(object $payment): string
    {
        return strtolower(trim((string) ($payment->status ?? '')));
    }

    private function isManager(Request $request): bool
    {
        return $this->hasRole($request, ['Manager', 'System Admin']);
    }

    private function isManagerOrFinance(Request $request): bool
    {
        return $this->hasRole($request, ['Finance', 'Account', 'Bank', 'Manager', 'System Admin']);
    }

    private function hasRole(Request $request, array $allowed): bool
    {
        $roles = $request->session()->get('roles', []);
        $roles = is_array($roles) ? $roles : [$roles];
        $normalized = array_map(static fn ($role): string => strtolower(trim((string) $role)), $roles);
        $allowed = array_map(static fn ($role): string => strtolower(trim((string) $role)), $allowed);

        return ! empty(array_intersect($normalized, $allowed));
    }
}
