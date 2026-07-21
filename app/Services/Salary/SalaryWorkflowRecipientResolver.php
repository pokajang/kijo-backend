<?php

namespace App\Services\Salary;

use App\Services\AppNotificationService;
use Illuminate\Support\Facades\DB;

class SalaryWorkflowRecipientResolver
{
    public function __construct(private AppNotificationService $notifications) {}

    public function stepRecipientIds(?object $instance, string $stepKey, array $excludeStaffIds = []): array
    {
        if (! $instance) {
            return [];
        }

        $step = DB::table('workflow_template_steps')
            ->where('template_id', $instance->template_id)
            ->where('step_key', $stepKey)
            ->where('active', 1)
            ->orderBy('sort_order')
            ->first();

        return $this->recipientsForStep($step, $excludeStaffIds);
    }

    public function currentStepRecipientIds(string $subjectType, int $subjectId, array $excludeStaffIds = []): array
    {
        $step = DB::table('workflow_instances as instance')
            ->join('workflow_template_steps as step', 'step.id', '=', 'instance.current_step_id')
            ->where('instance.subject_type', $subjectType)
            ->where('instance.subject_id', $subjectId)
            ->where('step.active', 1)
            ->orderByDesc('instance.id')
            ->select('step.*')
            ->first();

        return $this->recipientsForStep($step, $excludeStaffIds);
    }

    public function paymentRecipientIds(array $excludeStaffIds = []): array
    {
        return $this->exclude(
            $this->notifications->staffIdsForRoles(SalaryPaymentAccess::ROLES),
            $excludeStaffIds,
        );
    }

    private function recipientsForStep(?object $step, array $excludeStaffIds): array
    {
        if (! $step) {
            return [];
        }

        $recipientIds = DB::table('workflow_step_recipients')
            ->where('step_id', $step->id)
            ->where('active', 1)
            ->pluck('staff_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($recipientIds === []) {
            $recipientIds = $this->notifications->staffIdsForRoles($this->decodeJsonArray($step->fallback_roles));
        }

        return $this->exclude($recipientIds, $excludeStaffIds);
    }

    private function exclude(array $staffIds, array $excludeStaffIds): array
    {
        $staffIds = array_values(array_unique(array_filter(array_map('intval', $staffIds))));
        $exclude = array_values(array_unique(array_filter(array_map('intval', $excludeStaffIds))));

        return array_values(array_diff($staffIds, $exclude));
    }

    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
