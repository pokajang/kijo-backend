<?php

namespace App\Services\Vendors;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VendorPaymentFlowPresenter
{
    private array $actorCache = [];

    public function __construct(private VendorPaymentWorkflowService $workflow) {}

    public function build(array $row, array $progress): array
    {
        $payment = (object) $row;
        $stages = collect($this->workflow->stagesForPayment($payment))
            ->map(fn ($stage): array => $this->normalizeStage((array) $stage))
            ->values();
        $progressByKey = collect($progress)->keyBy(
            fn ($entry): string => ($entry['stageType'] ?? '').'.'.((int) ($entry['levelNo'] ?? 1)),
        );
        $status = ucfirst(strtolower(trim((string) ($payment->status ?? ''))));
        $activeStage = $this->currentStage($payment, $status);
        $activeKey = $activeStage
            ? $activeStage['stage_type'].'.'.$activeStage['level_no']
            : null;
        $terminalKey = null;

        if (in_array($status, ['Returned', 'Rejected'], true)) {
            $terminalStage = $stages->first(
                fn ($stage): bool => ! $this->hasCompletionEvidence($stage, $payment, $progressByKey, $stages)
                    && $stage['stageType'] !== VendorPaymentWorkflowService::STAGE_FINANCE,
            );
            $terminalKey = $terminalStage['key'] ?? null;
        }

        $activeIndex = $activeKey === null
            ? null
            : $stages->search(fn ($stage): bool => $stage['key'] === $activeKey);
        $terminalIndex = $terminalKey === null
            ? null
            : $stages->search(fn ($stage): bool => $stage['key'] === $terminalKey);

        $flowStages = $stages->map(function (array $stage, int $index) use (
            $payment,
            $progressByKey,
            $status,
            $activeKey,
            $activeIndex,
            $terminalKey,
            $terminalIndex,
        ): array {
            $completed = $progressByKey->get($stage['key']);
            if ($completed) {
                return array_merge($stage, [
                    'state' => 'completed',
                    'status' => (string) ($completed['status'] ?? 'Completed'),
                    'actor' => $this->actorFromProgress($completed),
                    'completedAt' => $completed['completedAt'] ?? null,
                    'remarks' => (string) ($completed['remarks'] ?? ''),
                ]);
            }

            if ($terminalKey === $stage['key']) {
                $prefix = strtolower($status);

                return array_merge($stage, [
                    'state' => $prefix,
                    'status' => $status,
                    'actor' => $this->actor((int) ($payment->{$prefix.'_by'} ?? 0)),
                    'completedAt' => $payment->{$prefix.'_at'} ?? null,
                    'remarks' => (string) ($payment->{$prefix.'_remarks'} ?? ''),
                ]);
            }

            $isImplicitlyCompleted = $status === 'Paid'
                || ($activeIndex !== null && $activeIndex !== false && $index < $activeIndex)
                || ($terminalIndex !== null && $terminalIndex !== false && $index < $terminalIndex);
            if ($isImplicitlyCompleted) {
                return array_merge($stage, $this->implicitCompletedStage($stage, $payment));
            }

            if ($activeKey === $stage['key']) {
                return array_merge($stage, [
                    'state' => 'current',
                    'status' => $stage['stageType'] === VendorPaymentWorkflowService::STAGE_FINANCE
                        ? 'Ready for payment'
                        : 'Pending',
                    'actor' => null,
                    'completedAt' => null,
                    'remarks' => '',
                ]);
            }

            return array_merge($stage, [
                'state' => 'waiting',
                'status' => 'Waiting',
                'actor' => null,
                'completedAt' => null,
                'remarks' => '',
            ]);
        })->values()->all();

        $currentStage = collect($flowStages)->first(fn ($stage): bool => $stage['state'] === 'current');

        return [
            'status' => $status ?: 'Pending',
            'currentStage' => $currentStage ? [
                'key' => $currentStage['key'],
                'stageType' => $currentStage['stageType'],
                'levelNo' => $currentStage['levelNo'],
                'label' => $currentStage['label'],
            ] : null,
            'stages' => $flowStages,
        ];
    }

    private function currentStage(object $payment, string $status): ?array
    {
        if ($status === 'Pending' && $this->workflow->stageEnabledForPayment($payment, VendorPaymentWorkflowService::STAGE_REVIEW)) {
            return [
                'stage_type' => VendorPaymentWorkflowService::STAGE_REVIEW,
                'level_no' => $this->workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_REVIEW),
            ];
        }

        if ($status === 'Checked' && $this->workflow->stageEnabledForPayment($payment, VendorPaymentWorkflowService::STAGE_APPROVAL)) {
            return [
                'stage_type' => VendorPaymentWorkflowService::STAGE_APPROVAL,
                'level_no' => $this->workflow->currentLevel($payment, VendorPaymentWorkflowService::STAGE_APPROVAL),
            ];
        }

        return $status === 'Approved'
            ? ['stage_type' => VendorPaymentWorkflowService::STAGE_FINANCE, 'level_no' => 1]
            : null;
    }

    private function normalizeStage(array $stage): array
    {
        $stageType = (string) ($stage['stage_type'] ?? $stage['stageType'] ?? '');
        $level = max(1, (int) ($stage['level_no'] ?? $stage['levelNo'] ?? 1));
        $recipients = $stage['effective_recipients'] ?? $stage['effectiveRecipients']
            ?? $stage['recipients'] ?? [];

        return [
            'key' => (string) ($stage['key'] ?? $stageType.'.'.$level),
            'stageType' => $stageType,
            'levelNo' => $level,
            'label' => (string) ($stage['label'] ?? ucfirst($stageType)),
            'recipients' => collect(is_array($recipients) ? $recipients : [])
                ->map(fn ($recipient): array => [
                    'staffId' => (int) ($recipient['staff_id'] ?? $recipient['staffId'] ?? 0),
                    'fullName' => (string) ($recipient['full_name'] ?? $recipient['fullName'] ?? ''),
                    'nameCode' => (string) ($recipient['name_code'] ?? $recipient['nameCode'] ?? ''),
                ])
                ->filter(fn ($recipient): bool => $recipient['staffId'] > 0)
                ->values()
                ->all(),
        ];
    }

    private function actorFromProgress(array $entry): ?array
    {
        $staffId = (int) ($entry['staffId'] ?? 0);
        if ($staffId <= 0 && empty($entry['actorName']) && empty($entry['actorCode'])) {
            return null;
        }

        return [
            'staffId' => $staffId ?: null,
            'fullName' => (string) ($entry['actorName'] ?? ''),
            'nameCode' => (string) ($entry['actorCode'] ?? ''),
        ];
    }

    private function actor(int $staffId): ?array
    {
        if ($staffId <= 0) {
            return null;
        }
        if (array_key_exists($staffId, $this->actorCache)) {
            return $this->actorCache[$staffId];
        }

        $staff = Schema::hasTable('staff_general')
            ? DB::table('staff_general')->where('staff_id', $staffId)->first(['full_name', 'name_code'])
            : null;

        return $this->actorCache[$staffId] = [
            'staffId' => $staffId,
            'fullName' => (string) ($staff->full_name ?? ''),
            'nameCode' => (string) ($staff->name_code ?? ''),
        ];
    }

    private function implicitCompletedStage(array $stage, object $payment): array
    {
        $stageType = $stage['stageType'];
        $isFinance = $stageType === VendorPaymentWorkflowService::STAGE_FINANCE;
        $isApproval = $stageType === VendorPaymentWorkflowService::STAGE_APPROVAL;
        $staffId = (int) ($isFinance
            ? ($payment->paid_by ?? 0)
            : ($isApproval ? ($payment->approved_by ?? 0) : ($payment->checked_by ?? 0)));

        return [
            'state' => 'completed',
            'status' => $isFinance ? 'Paid' : ($isApproval ? 'Approved' : 'Reviewed'),
            'actor' => $this->actor($staffId),
            'completedAt' => $isFinance
                ? ($payment->paid_at ?? $payment->paid_date ?? null)
                : ($isApproval ? ($payment->date_approved ?? null) : ($payment->checked_at ?? null)),
            'remarks' => (string) ($isFinance
                ? ($payment->paid_remarks ?? '')
                : ($isApproval ? ($payment->approval_remarks ?? '') : ($payment->checker_remarks ?? ''))),
        ];
    }

    private function hasCompletionEvidence(
        array $stage,
        object $payment,
        Collection $progressByKey,
        Collection $stages,
    ): bool {
        if ($progressByKey->has($stage['key'])) {
            return true;
        }

        $level = (int) $stage['levelNo'];
        $stageCount = $stages->where('stageType', $stage['stageType'])->count();

        return match ($stage['stageType']) {
            VendorPaymentWorkflowService::STAGE_REVIEW => $level < (int) ($payment->current_review_level ?? 1)
                || (int) ($payment->current_approval_level ?? 0) > 0
                || ($stageCount === 1 && (
                    (int) ($payment->checked_by ?? 0) > 0
                    || ! empty($payment->checked_at)
                )),
            VendorPaymentWorkflowService::STAGE_APPROVAL => $level < (int) ($payment->current_approval_level ?? 1)
                || ($stageCount === 1 && (
                    (int) ($payment->approved_by ?? 0) > 0
                    || ! empty($payment->date_approved)
                )),
            VendorPaymentWorkflowService::STAGE_FINANCE => (int) ($payment->paid_by ?? 0) > 0
                || ! empty($payment->paid_at)
                || ! empty($payment->paid_date),
            default => false,
        };
    }
}
