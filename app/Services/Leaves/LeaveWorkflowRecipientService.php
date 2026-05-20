<?php

namespace App\Services\Leaves;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LeaveWorkflowRecipientService
{
    public const STAGE_SUBMITTED_RECOMMENDERS = 'leave.submitted.recommenders';
    public const STAGE_RECOMMENDED_APPROVERS = 'leave.recommended.approvers';
    public const STAGE_APPROVED_NOTIFY = 'leave.approved.notify';
    public const STAGE_REJECTED_NOTIFY = 'leave.rejected.notify';
    public const STAGE_CANCELLED_NOTIFY = 'leave.cancelled.notify';
    public const STAGE_REVOKED_NOTIFY = 'leave.revoked.notify';

    private const TABLE = 'hr_leave_workflow_recipients';

    private const CONFIGURABLE_STAGES = [
        self::STAGE_SUBMITTED_RECOMMENDERS,
        self::STAGE_RECOMMENDED_APPROVERS,
    ];

    private const FALLBACK_ROLES = [
        self::STAGE_SUBMITTED_RECOMMENDERS => ['HR'],
        self::STAGE_RECOMMENDED_APPROVERS => ['Manager', 'System Admin'],
    ];

    private const STAGES = [
        self::STAGE_SUBMITTED_RECOMMENDERS => [
            'label' => 'New Application',
            'description' => 'Receives leave applications that need recommendation.',
            'fallback' => 'Active HR staff',
        ],
        self::STAGE_RECOMMENDED_APPROVERS => [
            'label' => 'Recommended Leave',
            'description' => 'Receives recommended leave applications that need approval.',
            'fallback' => 'Active Manager or System Admin staff',
        ],
        self::STAGE_APPROVED_NOTIFY => [
            'label' => 'Approved Leave',
            'description' => 'Receives copies when leave is approved. Applicant is always notified.',
            'fallback' => 'Applicant and earlier workflow participants',
        ],
        self::STAGE_REJECTED_NOTIFY => [
            'label' => 'Rejected Leave',
            'description' => 'Receives copies when leave is rejected. Applicant is always notified.',
            'fallback' => 'Applicant and earlier workflow participants',
        ],
        self::STAGE_CANCELLED_NOTIFY => [
            'label' => 'Cancelled Leave',
            'description' => 'Receives copies when a pending leave request is cancelled.',
            'fallback' => 'Applicant and active pending workflow participants',
        ],
        self::STAGE_REVOKED_NOTIFY => [
            'label' => 'Revoked Leave',
            'description' => 'Receives copies when approved leave is revoked. Applicant is always notified.',
            'fallback' => 'Applicant and earlier workflow participants',
        ],
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'stages' => $this->serializedStages(),
        ]);
    }

    public function update(Request $request, array $payload): JsonResponse
    {
        $this->assertConfiguredTable();

        $stages = $payload['stages'] ?? [];
        $unknownStages = array_values(array_diff(array_keys($stages), self::CONFIGURABLE_STAGES));
        if (!empty($unknownStages)) {
            throw ValidationException::withMessages([
                'stages' => 'Unknown leave workflow stage: ' . implode(', ', $unknownStages),
            ]);
        }

        $actorId = (int) $request->session()->get('staff_id', 0) ?: null;
        $now = now();

        DB::transaction(function () use ($stages, $actorId, $now): void {
            DB::table(self::TABLE)
                ->whereIn('stage_key', array_keys(self::STAGES))
                ->update([
                    'is_active' => 0,
                    'updated_by' => $actorId,
                    'updated_at' => $now,
                ]);

            foreach (self::CONFIGURABLE_STAGES as $stageKey) {
                $staffIds = $this->normalizeStaffIds($stages[$stageKey] ?? []);
                foreach ($staffIds as $index => $staffId) {
                    DB::table(self::TABLE)->updateOrInsert(
                        [
                            'stage_key' => $stageKey,
                            'staff_id' => $staffId,
                        ],
                        [
                            'sort_order' => $index,
                            'is_active' => 1,
                            'created_by' => $actorId,
                            'updated_by' => $actorId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    );
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Leave workflow recipients updated successfully.',
            'stages' => $this->serializedStages(),
        ]);
    }

    public function recipientsForStage(string $stageKey, array $fallbackRoles = []): array
    {
        $configured = $this->configuredRecipients($stageKey);
        if (!empty($configured)) {
            return $configured;
        }

        return $fallbackRoles ? $this->activeStaffForRoles($fallbackRoles) : [];
    }

    public function configuredRecipientsForStage(string $stageKey): array
    {
        if (!in_array($stageKey, self::CONFIGURABLE_STAGES, true)) {
            return [];
        }

        return $this->configuredRecipients($stageKey);
    }

    public function stageStaffIds(string $stageKey, array $fallbackRoles = []): array
    {
        return array_values(array_unique(array_map(
            static fn (array $recipient): int => (int) $recipient['staff_id'],
            $this->recipientsForStage($stageKey, $fallbackRoles),
        )));
    }

    public function emailAddresses(array $recipients): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $recipient): string => trim((string) ($recipient['email'] ?? '')),
            $recipients,
        ), static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
    }

    public function recipientsForStaffIds(array $staffIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $staffIds))));
        if (empty($ids) || !Schema::hasTable('staff_general')) {
            return [];
        }

        return DB::table('staff_general')
            ->whereIn('staff_id', $ids)
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->select(['staff_id', 'full_name', 'name_code', 'email'])
            ->get()
            ->map(fn ($row) => $this->formatRecipient($row))
            ->values()
            ->all();
    }

    private function serializedStages(): array
    {
        $recipients = $this->configuredRecipientsByStage();

        return array_map(function (string $stageKey) use ($recipients): array {
            $configuredRecipients = $recipients[$stageKey] ?? [];
            $effectiveRecipients = !empty($configuredRecipients)
                ? $configuredRecipients
                : $this->activeStaffForRoles(self::FALLBACK_ROLES[$stageKey] ?? []);

            return [
                'key' => $stageKey,
                'label' => self::STAGES[$stageKey]['label'],
                'description' => self::STAGES[$stageKey]['description'],
                'fallback' => self::STAGES[$stageKey]['fallback'],
                'recipients' => $configuredRecipients,
                'effective_recipients' => $effectiveRecipients,
                'using_default' => empty($configuredRecipients),
            ];
        }, self::CONFIGURABLE_STAGES);
    }

    private function configuredRecipientsByStage(): array
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasTable('staff_general')) {
            return [];
        }

        return DB::table(self::TABLE . ' as r')
            ->join('staff_general as sg', 'sg.staff_id', '=', 'r.staff_id')
            ->where('r.is_active', 1)
            ->whereIn('r.stage_key', array_keys(self::STAGES))
            ->whereNull('sg.deleted_at')
            ->whereRaw("LOWER(COALESCE(sg.status, '')) = 'active'")
            ->select([
                'r.stage_key',
                'r.staff_id',
                'r.sort_order',
                'sg.full_name',
                'sg.name_code',
                'sg.email',
            ])
            ->orderBy('r.stage_key')
            ->orderBy('r.sort_order')
            ->get()
            ->groupBy('stage_key')
            ->map(fn ($rows) => $rows->map(fn ($row) => $this->formatRecipient($row))->values()->all())
            ->all();
    }

    private function configuredRecipients(string $stageKey): array
    {
        $grouped = $this->configuredRecipientsByStage();
        return $grouped[$stageKey] ?? [];
    }

    private function activeStaffForRoles(array $roles): array
    {
        if (!Schema::hasTable('system_users') || !Schema::hasTable('staff_general')) {
            return [];
        }

        $allowed = array_map(static fn ($role): string => strtolower(trim((string) $role)), $roles);

        return DB::table('system_users as su')
            ->join('staff_general as sg', 'sg.staff_id', '=', 'su.staff_id')
            ->where('su.is_active', 1)
            ->whereNull('sg.deleted_at')
            ->whereRaw("LOWER(COALESCE(sg.status, '')) = 'active'")
            ->select([
                'sg.staff_id',
                'sg.full_name',
                'sg.name_code',
                DB::raw('COALESCE(NULLIF(su.email, ""), sg.email) as email'),
                'su.role',
            ])
            ->get()
            ->filter(fn ($row) => $this->rolesMatch($row->role ?? null, $allowed))
            ->map(fn ($row) => $this->formatRecipient($row))
            ->unique('staff_id')
            ->values()
            ->all();
    }

    private function normalizeStaffIds(array $staffIds): array
    {
        if (empty($staffIds)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $staffIds))));
        if (empty($ids) || !Schema::hasTable('staff_general')) {
            return [];
        }

        return DB::table('staff_general')
            ->whereIn('staff_id', $ids)
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->pluck('staff_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function formatRecipient(object $row): array
    {
        return [
            'staff_id' => (int) $row->staff_id,
            'full_name' => (string) ($row->full_name ?? ''),
            'name_code' => (string) ($row->name_code ?? ''),
            'email' => (string) ($row->email ?? ''),
        ];
    }

    private function rolesMatch(mixed $rawRoles, array $allowedRoles): bool
    {
        $decoded = is_string($rawRoles) ? json_decode($rawRoles, true) : null;
        $roles = is_array($decoded) ? $decoded : (is_array($rawRoles) ? $rawRoles : [$rawRoles]);
        $normalized = array_map(static fn ($role): string => strtolower(trim((string) $role)), $roles);

        return !empty(array_intersect($allowedRoles, $normalized));
    }

    private function assertConfiguredTable(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            throw ValidationException::withMessages([
                'stages' => 'Leave workflow recipient table is not available. Run migrations first.',
            ]);
        }
    }
}
