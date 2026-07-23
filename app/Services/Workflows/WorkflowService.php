<?php

namespace App\Services\Workflows;

use App\Services\Salary\SalaryWorkflowNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkflowService
{
    public const SALARY_TEMPLATE_KEY = 'salary-application';

    private const VENDOR_TEMPLATE_KEY = 'vendor-payment';

    private const LEAVE_TEMPLATE_KEY = 'leave-application';

    public const NEGOTIATION_TEMPLATE_KEY = 'quote-price-exception';

    public const QUOTE_APPROVAL_TEMPLATE_KEY = 'quote-approval';

    private const SALARY_SUBJECT_TYPE = 'salary_application';

    private const OTHER_CLAIM_SUBJECT_TYPE = 'other_claim_application';

    private const SALARY_SUBMITTED_STATUS = 'Submitted';

    private const SALARY_PENDING_CHECK_STATUSES = ['Submitted', 'Prepared'];

    private const CANCELLED_STATUS = 'Cancelled';

    private const MANAGE_ROLES = ['Manager', 'System Admin'];

    private const VENDOR_FALLBACK_ROLES = [
        'review' => ['Manager', 'System Admin'],
        'approval' => ['Manager', 'System Admin'],
        'finance' => ['Finance', 'Account', 'Bank', 'Manager', 'System Admin'],
    ];

    private const LEAVE_FALLBACK_ROLES = [
        'leave.submitted.recommenders' => ['Manager', 'System Admin'],
        'leave.recommended.approvers' => ['HR', 'System Admin'],
    ];

    private const NEGOTIATION_FALLBACK_ROLES = [
        'approve' => ['Manager', 'System Admin'],
    ];

    public function templates(Request $request): JsonResponse
    {
        $this->ensureDefaultTemplates();

        $templates = DB::table('workflow_templates')
            ->orderByRaw("CASE process_key WHEN 'salary-application' THEN 1 WHEN 'vendor-payment' THEN 2 WHEN 'leave-application' THEN 3 WHEN 'quote-price-exception' THEN 4 WHEN 'quote-approval' THEN 5 ELSE 9 END")
            ->orderBy('label')
            ->get()
            ->map(fn (object $template): array => $this->templateSummary($template))
            ->values()
            ->all();

        return response()->json([
            'status' => 'success',
            'templates' => $templates,
            'can_edit' => $this->hasAnyRole($request, self::MANAGE_ROLES),
        ]);
    }

    public function setupStatus(Request $request): JsonResponse
    {
        $templateKeys = [
            self::SALARY_TEMPLATE_KEY,
            self::VENDOR_TEMPLATE_KEY,
            self::LEAVE_TEMPLATE_KEY,
            self::NEGOTIATION_TEMPLATE_KEY,
            self::QUOTE_APPROVAL_TEMPLATE_KEY,
        ];
        $emptyStatus = array_fill_keys($templateKeys, ['missing' => 0]);

        if (
            ! Schema::hasTable('workflow_templates')
            || ! Schema::hasTable('workflow_template_steps')
            || ! Schema::hasTable('workflow_step_recipients')
        ) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_missing' => 0,
                    'templates' => $emptyStatus,
                ],
            ]);
        }

        $this->ensureDefaultTemplates();

        $templates = DB::table('workflow_templates')
            ->whereIn('process_key', $templateKeys)
            ->where('enabled', 1)
            ->select(['id', 'process_key'])
            ->get()
            ->keyBy(fn (object $template): int => (int) $template->id);

        $steps = DB::table('workflow_template_steps')
            ->whereIn('template_id', $templates->keys()->all())
            ->where('active', 1)
            ->select(['id', 'template_id'])
            ->get();

        $recipientCounts = $steps->isEmpty()
            ? collect()
            : DB::table('workflow_step_recipients')
                ->whereIn('step_id', $steps->pluck('id')->all())
                ->where('active', 1)
                ->select('step_id', DB::raw('COUNT(*) as recipient_count'))
                ->groupBy('step_id')
                ->pluck('recipient_count', 'step_id');

        $missingByTemplate = array_fill_keys($templateKeys, 0);
        foreach ($steps as $step) {
            $template = $templates->get((int) $step->template_id);
            if (! $template) {
                continue;
            }

            if ((int) ($recipientCounts->get((int) $step->id) ?? 0) === 0) {
                $missingByTemplate[(string) $template->process_key] += 1;
            }
        }

        $templatesPayload = [];
        foreach ($templateKeys as $key) {
            $templatesPayload[$key] = ['missing' => (int) $missingByTemplate[$key]];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_missing' => array_sum($missingByTemplate),
                'templates' => $templatesPayload,
            ],
        ]);
    }

    public function template(Request $request, string $key): JsonResponse
    {
        $this->ensureDefaultTemplates();

        if ($key === self::VENDOR_TEMPLATE_KEY) {
            return response()->json($this->vendorTemplatePayload($request));
        }
        if ($key === self::LEAVE_TEMPLATE_KEY) {
            return response()->json($this->leaveTemplatePayload($request));
        }
        if (in_array($key, [self::NEGOTIATION_TEMPLATE_KEY, self::QUOTE_APPROVAL_TEMPLATE_KEY], true)) {
            return response()->json($this->genericTemplatePayload($request, $key));
        }

        $template = $this->templateByKey($key);
        if (! $template) {
            return response()->json(['status' => 'error', 'message' => 'Workflow template not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'template' => $this->salaryTemplatePayload($template),
            'active_staff' => $this->activeStaff(),
            'can_edit' => $this->hasAnyRole($request, self::MANAGE_ROLES),
        ]);
    }

    public function updateTemplate(Request $request, string $key): JsonResponse
    {
        $this->ensureDefaultTemplates();

        if (! $this->hasAnyRole($request, self::MANAGE_ROLES)) {
            return response()->json(['status' => 'error', 'message' => 'Not authorized to update workflow settings.'], 403);
        }

        if ($key === self::VENDOR_TEMPLATE_KEY) {
            return response()->json($this->updateVendorTemplate($request));
        }
        if ($key === self::LEAVE_TEMPLATE_KEY) {
            return response()->json($this->updateLeaveTemplate($request));
        }
        if (in_array($key, [self::NEGOTIATION_TEMPLATE_KEY, self::QUOTE_APPROVAL_TEMPLATE_KEY], true)) {
            $message = $key === self::QUOTE_APPROVAL_TEMPLATE_KEY
                ? 'Quotation approval workflow settings saved.'
                : 'Negotiation workflow settings saved.';

            return response()->json($this->updateGenericTemplate($request, $key, $message));
        }
        if ($key !== self::SALARY_TEMPLATE_KEY) {
            return response()->json(['status' => 'error', 'message' => 'Workflow template not found.'], 404);
        }

        $template = $this->templateByKey($key);
        $stepsPayload = Validator::make($request->all(), [
            'steps' => ['required', 'array'],
            'steps.*.id' => ['required', 'integer', 'min:1'],
            'steps.*.recipient_staff_ids' => ['nullable', 'array'],
            'steps.*.recipient_staff_ids.*' => ['integer', 'min:1'],
        ])->validate()['steps'];

        $validStepIds = DB::table('workflow_template_steps')
            ->where('template_id', $template->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $validStaffIds = $this->validStaffIds(collect($stepsPayload)->flatMap(
            fn ($step): array => (array) ($step['recipient_staff_ids'] ?? []),
        )->all());

        DB::transaction(function () use ($stepsPayload, $validStepIds, $validStaffIds): void {
            foreach ($stepsPayload as $step) {
                $stepId = (int) $step['id'];
                if (! in_array($stepId, $validStepIds, true)) {
                    continue;
                }
                DB::table('workflow_step_recipients')->where('step_id', $stepId)->update([
                    'active' => 0,
                    'updated_at' => now(),
                ]);
                foreach (array_values(array_intersect(
                    array_map('intval', (array) ($step['recipient_staff_ids'] ?? [])),
                    $validStaffIds,
                )) as $index => $staffId) {
                    DB::table('workflow_step_recipients')->updateOrInsert(
                        ['step_id' => $stepId, 'staff_id' => $staffId],
                        [
                            'sort_order' => $index,
                            'active' => 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Salary workflow settings saved.',
            'template' => $this->salaryTemplatePayload($template),
            'active_staff' => $this->activeStaff(),
            'can_edit' => true,
        ]);
    }

    public function inbox(Request $request): JsonResponse
    {
        $this->ensureDefaultTemplates();

        $instances = DB::table('workflow_instances as instance')
            ->join('workflow_templates as template', 'template.id', '=', 'instance.template_id')
            ->leftJoin('workflow_template_steps as step', 'step.id', '=', 'instance.current_step_id')
            ->leftJoin('staff_general as maker', 'maker.staff_id', '=', 'instance.maker_staff_id')
            ->leftJoin('hr_salary_applications as salary', function ($join): void {
                $join->on('salary.id', '=', 'instance.subject_id')
                    ->where('instance.subject_type', self::SALARY_SUBJECT_TYPE);
            })
            ->leftJoin('hr_other_claim_applications as other_claim', function ($join): void {
                $join->on('other_claim.id', '=', 'instance.subject_id')
                    ->where('instance.subject_type', self::OTHER_CLAIM_SUBJECT_TYPE);
            })
            ->where('template.process_key', self::SALARY_TEMPLATE_KEY)
            ->whereIn('instance.status', [...self::SALARY_PENDING_CHECK_STATUSES, 'Checked'])
            ->where(function ($query): void {
                $query
                    ->where(function ($salaryQuery): void {
                        $salaryQuery
                            ->where('instance.subject_type', self::SALARY_SUBJECT_TYPE)
                            ->whereNotNull('salary.id')
                            ->where('salary.status', '<>', self::CANCELLED_STATUS);
                    })
                    ->orWhere(function ($otherClaimQuery): void {
                        $otherClaimQuery
                            ->where('instance.subject_type', self::OTHER_CLAIM_SUBJECT_TYPE)
                            ->whereNotNull('other_claim.id')
                            ->where('other_claim.status', '<>', self::CANCELLED_STATUS);
                    });
            })
            ->select([
                'instance.*',
                'template.process_key',
                'template.label as template_label',
                'step.label as step_label',
                'step.step_key',
                'maker.full_name as maker_name',
                'maker.name_code as maker_code',
                'salary.salary_month_label',
                'salary.payable_salary',
                'salary.staff_id as salary_staff_id',
                'other_claim.claim_month_label',
                'other_claim.claims_total',
                'other_claim.staff_id as other_claim_staff_id',
            ])
            ->orderByDesc('instance.submitted_at')
            ->get()
            ->map(function (object $instance) use ($request): array {
                $payload = $this->workflowPayloadForInstance($instance, $request);

                return [
                    'id' => (int) $instance->id,
                    'module' => (string) $instance->subject_type === self::OTHER_CLAIM_SUBJECT_TYPE ? 'Other Claim' : 'Salary',
                    'moduleKey' => (string) $instance->subject_type === self::OTHER_CLAIM_SUBJECT_TYPE
                        ? 'other-claim'
                        : 'salary',
                    'record' => (string) $instance->subject_type === self::OTHER_CLAIM_SUBJECT_TYPE
                        ? trim((string) ($instance->claim_month_label ?? 'Other claim').' - RM '.number_format((float) ($instance->claims_total ?? 0), 2))
                        : trim((string) ($instance->salary_month_label ?? 'Salary record').' - RM '.number_format((float) ($instance->payable_salary ?? 0), 2)),
                    'recordRoute' => (string) $instance->subject_type === self::OTHER_CLAIM_SUBJECT_TYPE
                        ? '/financial/other-claim-records'
                        : '/financial/salary-records',
                    'maker' => $this->staffLabel($instance->maker_name ?? '', $instance->maker_code ?? '', $instance->maker_staff_id ?? null),
                    'currentStep' => $payload['currentStepLabel'],
                    'submittedAt' => $instance->submitted_at,
                    'status' => $instance->status,
                    'workflow' => $payload,
                ];
            })
            ->filter(fn (array $row): bool => ! empty($row['workflow']['availableActions']))
            ->values()
            ->all();

        return response()->json(['status' => 'success', 'items' => $instances]);
    }

    public function action(Request $request, int $instanceId): JsonResponse
    {
        $this->ensureDefaultTemplates();

        $data = Validator::make($request->all(), [
            'action' => ['required', 'string', 'in:check,approve,reject,return'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'record_version' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        if ((string) ($data['action'] ?? '') === 'reject' && trim((string) ($data['remarks'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'remarks' => ['Enter a reason before rejecting this record.'],
            ]);
        }

        $record = null;
        $subjectType = null;
        $subjectId = null;
        DB::transaction(function () use ($request, $instanceId, $data, &$record): void {
            $instance = DB::table('workflow_instances')->where('id', $instanceId)->lockForUpdate()->first();
            if (! $instance) {
                abort(response()->json(['status' => 'error', 'message' => 'Workflow instance not found.'], 404));
            }

            $template = DB::table('workflow_templates')->where('id', $instance->template_id)->first();
            if (
                ! $template ||
                $template->process_key !== self::SALARY_TEMPLATE_KEY ||
                ! in_array((string) $instance->subject_type, [self::SALARY_SUBJECT_TYPE, self::OTHER_CLAIM_SUBJECT_TYPE], true)
            ) {
                abort(response()->json(['status' => 'error', 'message' => 'Workflow action is not supported for this record.'], 422));
            }

            $record = (string) $instance->subject_type === self::OTHER_CLAIM_SUBJECT_TYPE
                ? $this->applyOtherClaimAction($request, $instance, $data)
                : $this->applySalaryAction($request, $instance, $data);
        });

        $updatedInstance = DB::table('workflow_instances')->where('id', $instanceId)->first();
        $subjectType = (string) ($updatedInstance->subject_type ?? '');
        $subjectId = (int) ($updatedInstance->subject_id ?? 0);
        if ($subjectId > 0 && in_array($subjectType, [self::SALARY_SUBJECT_TYPE, self::OTHER_CLAIM_SUBJECT_TYPE], true)) {
            try {
                app(SalaryWorkflowNotificationService::class)->notifyWorkflowAction(
                    $request,
                    $subjectType,
                    $subjectId,
                    (string) $data['action'],
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Workflow action completed.',
            'record' => $record,
        ]);
    }

    public function createOrResetSalaryWorkflow(int $applicationId, int $makerStaffId): void
    {
        $this->createOrResetSalaryTemplateWorkflow(
            applicationId: $applicationId,
            makerStaffId: $makerStaffId,
            subjectType: self::SALARY_SUBJECT_TYPE,
            submitRemarks: 'Submitted salary application.',
        );
    }

    public function createOrResetOtherClaimWorkflow(int $applicationId, int $makerStaffId): void
    {
        $this->createOrResetSalaryTemplateWorkflow(
            applicationId: $applicationId,
            makerStaffId: $makerStaffId,
            subjectType: self::OTHER_CLAIM_SUBJECT_TYPE,
            submitRemarks: 'Submitted other claim application.',
        );
    }

    private function createOrResetSalaryTemplateWorkflow(
        int $applicationId,
        int $makerStaffId,
        string $subjectType,
        string $submitRemarks,
    ): void {
        $this->ensureDefaultTemplates();

        $template = $this->templateByKey(self::SALARY_TEMPLATE_KEY);
        $firstStep = DB::table('workflow_template_steps')
            ->where('template_id', $template->id)
            ->where('active', 1)
            ->orderBy('sort_order')
            ->first();

        $instance = DB::table('workflow_instances')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $applicationId)
            ->first();

        if ($instance) {
            DB::table('workflow_actions')->where('instance_id', $instance->id)->delete();
            DB::table('workflow_instances')->where('id', $instance->id)->update([
                'template_id' => $template->id,
                'current_step_id' => $firstStep?->id,
                'status' => self::SALARY_SUBMITTED_STATUS,
                'maker_staff_id' => $makerStaffId,
                'submitted_by' => $makerStaffId,
                'submitted_at' => now(),
                'completed_at' => null,
                'updated_at' => now(),
            ]);
            $instanceId = (int) $instance->id;
        } else {
            $instanceId = (int) DB::table('workflow_instances')->insertGetId([
                'template_id' => $template->id,
                'subject_type' => $subjectType,
                'subject_id' => $applicationId,
                'current_step_id' => $firstStep?->id,
                'status' => self::SALARY_SUBMITTED_STATUS,
                'maker_staff_id' => $makerStaffId,
                'submitted_by' => $makerStaffId,
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('workflow_actions')->insert([
            'instance_id' => $instanceId,
            'step_id' => null,
            'action' => 'submit',
            'status_from' => null,
            'status_to' => self::SALARY_SUBMITTED_STATUS,
            'actor_staff_id' => $makerStaffId,
            'remarks' => $submitRemarks,
            'acted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function ensureOtherClaimWorkflowForExistingRecord(object $record): void
    {
        $this->ensureSalaryTemplateWorkflowForExistingRecord(
            record: $record,
            subjectType: self::OTHER_CLAIM_SUBJECT_TYPE,
            tableName: 'hr_other_claim_applications',
            submitRemarks: 'Submitted other claim application.',
        );
    }

    public function ensureSalaryWorkflowForExistingRecord(object $record): void
    {
        $this->ensureSalaryTemplateWorkflowForExistingRecord(
            record: $record,
            subjectType: self::SALARY_SUBJECT_TYPE,
            tableName: 'hr_salary_applications',
            submitRemarks: 'Submitted salary application.',
        );
    }

    private function ensureSalaryTemplateWorkflowForExistingRecord(
        object $record,
        string $subjectType,
        string $tableName,
        string $submitRemarks,
    ): void {
        $this->ensureDefaultTemplates();

        $applicationId = (int) $record->id;
        $existing = DB::table('workflow_instances')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $applicationId)
            ->exists();
        if ($existing) {
            return;
        }

        $template = $this->templateByKey(self::SALARY_TEMPLATE_KEY);
        $steps = DB::table('workflow_template_steps')
            ->where('template_id', $template->id)
            ->where('active', 1)
            ->orderBy('sort_order')
            ->get()
            ->keyBy('step_key');
        $checkStep = $steps->get('check');
        $approveStep = $steps->get('approve');
        $status = (string) ($record->status ?? self::SALARY_SUBMITTED_STATUS);
        $checkedBy = (int) ($record->checked_by ?? 0);
        $approvedBy = (int) ($record->approved_by ?? 0);
        $derivedStatus = $status;
        $currentStepId = null;
        $completedAt = null;

        if (in_array($status, self::SALARY_PENDING_CHECK_STATUSES, true) && $checkedBy > 0) {
            $derivedStatus = 'Checked';
            $currentStepId = $approveStep?->id;
            DB::table($tableName)->where('id', $applicationId)->update([
                'status' => 'Checked',
                'updated_at' => now(),
            ]);
        } elseif (in_array($status, self::SALARY_PENDING_CHECK_STATUSES, true)) {
            $derivedStatus = self::SALARY_SUBMITTED_STATUS;
            $currentStepId = $checkStep?->id;
        } elseif ($status === 'Checked') {
            $currentStepId = $approveStep?->id;
        } elseif (in_array($status, ['Approved', 'Paid', 'Rejected'], true)) {
            $completedAt = $record->approved_at ?? $record->checked_at ?? $record->submitted_at ?? now();
        }

        $instanceId = (int) DB::table('workflow_instances')->insertGetId([
            'template_id' => $template->id,
            'subject_type' => $subjectType,
            'subject_id' => $applicationId,
            'current_step_id' => $currentStepId,
            'status' => $derivedStatus,
            'maker_staff_id' => (int) $record->staff_id,
            'submitted_by' => (int) $record->staff_id,
            'submitted_at' => $record->submitted_at ?? now(),
            'completed_at' => $completedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('workflow_actions')->insert([
            'instance_id' => $instanceId,
            'step_id' => null,
            'action' => 'submit',
            'status_from' => null,
            'status_to' => self::SALARY_SUBMITTED_STATUS,
            'actor_staff_id' => (int) $record->staff_id,
            'remarks' => $submitRemarks,
            'acted_at' => $record->submitted_at ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($checkedBy > 0) {
            DB::table('workflow_actions')->insert([
                'instance_id' => $instanceId,
                'step_id' => $checkStep?->id,
                'action' => 'check',
                'status_from' => self::SALARY_SUBMITTED_STATUS,
                'status_to' => $status === 'Rejected' && ! $approvedBy ? 'Rejected' : 'Checked',
                'actor_staff_id' => $checkedBy,
                'remarks' => $record->checked_remarks ?? '',
                'acted_at' => $record->checked_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($approvedBy > 0) {
            DB::table('workflow_actions')->insert([
                'instance_id' => $instanceId,
                'step_id' => $approveStep?->id,
                'action' => ($record->approved_status ?? '') === 'Rejected' ? 'reject' : 'approve',
                'status_from' => 'Checked',
                'status_to' => $status,
                'actor_staff_id' => $approvedBy,
                'remarks' => $record->approved_remarks ?? '',
                'acted_at' => $record->approved_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function salaryWorkflowPayloads(array $applicationIds, ?Request $request = null): array
    {
        return $this->workflowPayloadsForSubject(self::SALARY_SUBJECT_TYPE, $applicationIds, $request);
    }

    public function otherClaimWorkflowPayloads(array $applicationIds, ?Request $request = null): array
    {
        return $this->workflowPayloadsForSubject(self::OTHER_CLAIM_SUBJECT_TYPE, $applicationIds, $request);
    }

    private function workflowPayloadsForSubject(string $subjectType, array $applicationIds, ?Request $request = null): array
    {
        if (empty($applicationIds)) {
            return [];
        }

        $instances = DB::table('workflow_instances as instance')
            ->leftJoin('workflow_template_steps as step', 'step.id', '=', 'instance.current_step_id')
            ->where('instance.subject_type', $subjectType)
            ->whereIn('instance.subject_id', array_values(array_unique(array_map('intval', $applicationIds))))
            ->select(['instance.*', 'step.step_key', 'step.label as step_label'])
            ->get();

        $payloads = [];
        foreach ($instances as $instance) {
            $payloads[(int) $instance->subject_id] = $this->workflowPayloadForInstance($instance, $request);
        }

        return $payloads;
    }

    public function salaryWorkflowPayload(int $applicationId, ?Request $request = null): ?array
    {
        return $this->salaryWorkflowPayloads([$applicationId], $request)[$applicationId] ?? null;
    }

    public function otherClaimWorkflowPayload(int $applicationId, ?Request $request = null): ?array
    {
        return $this->otherClaimWorkflowPayloads([$applicationId], $request)[$applicationId] ?? null;
    }

    public function salaryInstanceId(int $applicationId): ?int
    {
        return $this->instanceIdForSubject(self::SALARY_SUBJECT_TYPE, $applicationId);
    }

    public function otherClaimInstanceId(int $applicationId): ?int
    {
        return $this->instanceIdForSubject(self::OTHER_CLAIM_SUBJECT_TYPE, $applicationId);
    }

    private function instanceIdForSubject(string $subjectType, int $applicationId): ?int
    {
        $id = DB::table('workflow_instances')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $applicationId)
            ->value('id');

        return $id ? (int) $id : null;
    }

    public function effectiveStepRecipients(
        string $templateKey,
        string $stepKey,
        int $levelNo = 1,
        array $defaultFallbackRoles = [],
    ): array {
        $this->ensureDefaultTemplates();

        $step = $this->templateStep($templateKey, $stepKey, $levelNo);
        $fallbackRoles = $step
            ? $this->decodeJsonArray($step->fallback_roles)
            : $defaultFallbackRoles;
        if (empty($fallbackRoles)) {
            $fallbackRoles = $defaultFallbackRoles;
        }

        $configured = $step ? $this->configuredStepRecipients((int) $step->id) : [];

        return ! empty($configured) ? $configured : $this->activeStaffForRoles($fallbackRoles);
    }

    public function effectiveStepStaffIds(
        string $templateKey,
        string $stepKey,
        int $levelNo = 1,
        array $defaultFallbackRoles = [],
    ): array {
        return array_values(array_unique(array_map(
            static fn (array $recipient): int => (int) $recipient['staff_id'],
            $this->effectiveStepRecipients($templateKey, $stepKey, $levelNo, $defaultFallbackRoles),
        )));
    }

    public function canActOnTemplateStep(
        Request $request,
        string $templateKey,
        string $stepKey,
        int $levelNo = 1,
        array $defaultFallbackRoles = [],
    ): bool {
        $actorId = $this->staffId($request);
        if ($actorId <= 0) {
            return false;
        }
        if ($this->hasAnyRole($request, ['System Admin'])) {
            return true;
        }

        $step = $this->templateStep($templateKey, $stepKey, $levelNo);
        $configured = $step ? $this->configuredStepRecipients((int) $step->id) : [];
        if (! empty($configured)) {
            return in_array($actorId, array_map(static fn (array $recipient): int => (int) $recipient['staff_id'], $configured), true);
        }

        $fallbackRoles = $step
            ? $this->decodeJsonArray($step->fallback_roles)
            : $defaultFallbackRoles;
        if (empty($fallbackRoles)) {
            $fallbackRoles = $defaultFallbackRoles;
        }

        return $this->hasAnyRole($request, $fallbackRoles);
    }

    private function applySalaryAction(Request $request, object $instance, array $data): array
    {
        $salary = DB::table('hr_salary_applications')->where('id', $instance->subject_id)->lockForUpdate()->first();
        if (! $salary) {
            abort(response()->json(['status' => 'error', 'message' => 'Salary record not found.'], 404));
        }
        if ((string) $salary->status === self::CANCELLED_STATUS) {
            abort(response()->json(['status' => 'error', 'message' => 'Salary record not found.'], 404));
        }
        if ((string) $instance->status === 'Rejected' || (string) $salary->status === 'Rejected') {
            abort(response()->json(['status' => 'error', 'message' => 'Rejected salary records cannot be actioned further.'], 422));
        }
        if (! in_array((string) $instance->status, [...self::SALARY_PENDING_CHECK_STATUSES, 'Checked'], true)) {
            abort(response()->json(['status' => 'error', 'message' => 'Salary record cannot be actioned in its current state.'], 422));
        }

        $actorId = $this->staffId($request);
        $action = (string) $data['action'];
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $step = $instance->current_step_id ? DB::table('workflow_template_steps')->where('id', $instance->current_step_id)->first() : null;
        if (! $step) {
            abort(response()->json(['status' => 'error', 'message' => 'This workflow has no current actionable step.'], 422));
        }
        if ($actorId <= 0 || $actorId === (int) $instance->maker_staff_id) {
            abort(response()->json(['status' => 'error', 'message' => 'The maker cannot check or approve their own salary application.'], 403));
        }
        if (! $this->canActOnSalaryStep($request, $step)) {
            abort(response()->json(['status' => 'error', 'message' => 'You are not assigned to this workflow step.'], 403));
        }
        if ($action === 'approve' && (string) $step->step_key !== 'approve') {
            abort(response()->json(['status' => 'error', 'message' => 'Salary record must be checked before approval.'], 422));
        }
        if ($action !== 'reject' && $action !== (string) $step->step_key) {
            abort(response()->json(['status' => 'error', 'message' => 'Action does not match the current workflow step.'], 422));
        }

        $statusFrom = (string) $instance->status;
        if ($action === 'reject') {
            $statusTo = 'Rejected';
            $nextStepId = null;
            $completedAt = now();
            $salaryPayload = [
                'status' => 'Rejected',
                'updated_at' => now(),
            ];
            if ((string) $step->step_key === 'check') {
                $salaryPayload += [
                    'checked_by' => $actorId,
                    'checked_at' => now(),
                    'checked_status' => 'Rejected',
                    'checked_remarks' => $remarks,
                ];
            } else {
                $salaryPayload += [
                    'approved_by' => $actorId,
                    'approved_at' => now(),
                    'approved_status' => 'Rejected',
                    'approved_remarks' => $remarks,
                ];
            }
        } elseif ($action === 'check') {
            $statusTo = 'Checked';
            $nextStep = $this->nextStep((int) $instance->template_id, (int) $step->sort_order);
            $nextStepId = $nextStep?->id;
            $completedAt = null;
            $salaryPayload = [
                'status' => 'Checked',
                'checked_by' => $actorId,
                'checked_at' => now(),
                'checked_status' => 'Checked',
                'checked_remarks' => $remarks,
                'updated_at' => now(),
            ];
        } else {
            $checkerId = (int) ($salary->checked_by ?? 0);
            if ($checkerId <= 0 || (string) $salary->status !== 'Checked') {
                abort(response()->json(['status' => 'error', 'message' => 'Salary record must be checked before approval.'], 422));
            }
            if ($checkerId === $actorId) {
                abort(response()->json(['status' => 'error', 'message' => 'Checker and approver must be different staff.'], 403));
            }
            $statusTo = 'Approved';
            $nextStepId = null;
            $completedAt = now();
            $salaryPayload = [
                'status' => 'Approved',
                'approved_by' => $actorId,
                'approved_at' => now(),
                'approved_status' => 'Approved',
                'approved_remarks' => $remarks,
                'updated_at' => now(),
            ];
        }

        DB::table('workflow_actions')->insert([
            'instance_id' => $instance->id,
            'step_id' => $step->id,
            'action' => $action,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'actor_staff_id' => $actorId,
            'remarks' => $remarks,
            'acted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('workflow_instances')->where('id', $instance->id)->update([
            'current_step_id' => $nextStepId,
            'status' => $statusTo,
            'completed_at' => $completedAt,
            'updated_at' => now(),
        ]);
        DB::table('hr_salary_applications')->where('id', $salary->id)->update($salaryPayload);

        return $this->salaryRecordPayload((int) $salary->id, $request);
    }

    private function applyOtherClaimAction(Request $request, object $instance, array $data): array
    {
        $claim = DB::table('hr_other_claim_applications')->where('id', $instance->subject_id)->lockForUpdate()->first();
        if (! $claim) {
            abort(response()->json(['status' => 'error', 'message' => 'Other claim record not found.'], 404));
        }
        if ((string) $claim->status === self::CANCELLED_STATUS) {
            abort(response()->json(['status' => 'error', 'message' => 'Other claim record not found.'], 404));
        }
        if ((string) $instance->status === 'Rejected' || (string) $claim->status === 'Rejected') {
            abort(response()->json(['status' => 'error', 'message' => 'Rejected other claim records cannot be actioned further.'], 422));
        }
        if (Schema::hasColumn('hr_other_claim_applications', 'record_version')) {
            $expectedVersion = (int) ($data['record_version'] ?? 0);
            if ($expectedVersion <= 0) {
                abort(response()->json([
                    'status' => 'error',
                    'message' => 'Refresh this claim before taking action.',
                    'errors' => ['record_version' => ['The claim version is required.']],
                ], 422));
            }
            if ($expectedVersion !== (int) ($claim->record_version ?? 1)) {
                abort(response()->json([
                    'status' => 'error',
                    'message' => 'This claim changed or was withdrawn. Refresh before taking action.',
                ], 409));
            }
        }
        if (! in_array((string) $instance->status, [...self::SALARY_PENDING_CHECK_STATUSES, 'Checked'], true)) {
            abort(response()->json(['status' => 'error', 'message' => 'Other claim record cannot be actioned in its current state.'], 422));
        }

        $actorId = $this->staffId($request);
        $action = (string) $data['action'];
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $step = $instance->current_step_id ? DB::table('workflow_template_steps')->where('id', $instance->current_step_id)->first() : null;
        if (! $step) {
            abort(response()->json(['status' => 'error', 'message' => 'This workflow has no current actionable step.'], 422));
        }
        if ($actorId <= 0 || $actorId === (int) $instance->maker_staff_id) {
            abort(response()->json(['status' => 'error', 'message' => 'The maker cannot check or approve their own other claim application.'], 403));
        }
        if (! $this->canActOnStep($request, $step)) {
            abort(response()->json(['status' => 'error', 'message' => 'You are not assigned to this workflow step.'], 403));
        }
        if ($action === 'approve' && (string) $step->step_key !== 'approve') {
            abort(response()->json(['status' => 'error', 'message' => 'Other claim record must be checked before approval.'], 422));
        }
        if ($action !== 'reject' && $action !== (string) $step->step_key) {
            abort(response()->json(['status' => 'error', 'message' => 'Action does not match the current workflow step.'], 422));
        }

        $statusFrom = (string) $instance->status;
        if ($action === 'reject') {
            $statusTo = 'Rejected';
            $nextStepId = null;
            $completedAt = now();
            $claimPayload = [
                'status' => 'Rejected',
                'updated_at' => now(),
            ];
            if ((string) $step->step_key === 'check') {
                $claimPayload += [
                    'checked_by' => $actorId,
                    'checked_at' => now(),
                    'checked_status' => 'Rejected',
                    'checked_remarks' => $remarks,
                ];
            } else {
                $claimPayload += [
                    'approved_by' => $actorId,
                    'approved_at' => now(),
                    'approved_status' => 'Rejected',
                    'approved_remarks' => $remarks,
                ];
            }
        } elseif ($action === 'check') {
            $statusTo = 'Checked';
            $nextStep = $this->nextStep((int) $instance->template_id, (int) $step->sort_order);
            $nextStepId = $nextStep?->id;
            $completedAt = null;
            $claimPayload = [
                'status' => 'Checked',
                'checked_by' => $actorId,
                'checked_at' => now(),
                'checked_status' => 'Checked',
                'checked_remarks' => $remarks,
                'updated_at' => now(),
            ];
        } else {
            $checkerId = (int) ($claim->checked_by ?? 0);
            if ($checkerId <= 0 || (string) $claim->status !== 'Checked') {
                abort(response()->json(['status' => 'error', 'message' => 'Other claim record must be checked before approval.'], 422));
            }
            if ($checkerId === $actorId) {
                abort(response()->json(['status' => 'error', 'message' => 'Checker and approver must be different staff.'], 403));
            }
            $statusTo = 'Approved';
            $nextStepId = null;
            $completedAt = now();
            $claimPayload = [
                'status' => 'Approved',
                'approved_by' => $actorId,
                'approved_at' => now(),
                'approved_status' => 'Approved',
                'approved_remarks' => $remarks,
                'updated_at' => now(),
            ];
        }

        DB::table('workflow_actions')->insert([
            'instance_id' => $instance->id,
            'step_id' => $step->id,
            'action' => $action,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'actor_staff_id' => $actorId,
            'remarks' => $remarks,
            'acted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('workflow_instances')->where('id', $instance->id)->update([
            'current_step_id' => $nextStepId,
            'status' => $statusTo,
            'completed_at' => $completedAt,
            'updated_at' => now(),
        ]);
        if (Schema::hasColumn('hr_other_claim_applications', 'record_version')) {
            $claimPayload['record_version'] = DB::raw('record_version + 1');
        }
        DB::table('hr_other_claim_applications')->where('id', $claim->id)->update($claimPayload);

        return $this->otherClaimRecordPayload((int) $claim->id, $request);
    }

    private function workflowPayloadForInstance(object $instance, ?Request $request = null): array
    {
        $step = $instance->current_step_id ? DB::table('workflow_template_steps')->where('id', $instance->current_step_id)->first() : null;
        $history = DB::table('workflow_actions as action')
            ->leftJoin('workflow_template_steps as step', 'step.id', '=', 'action.step_id')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'action.actor_staff_id')
            ->where('action.instance_id', $instance->id)
            ->orderBy('action.acted_at')
            ->orderBy('action.id')
            ->select([
                'action.*',
                'step.label as step_label',
                'staff.full_name as actor_name',
                'staff.name_code as actor_code',
            ])
            ->get()
            ->map(fn (object $action): array => [
                'id' => (int) $action->id,
                'action' => (string) $action->action,
                'label' => (string) ($action->step_label ?: ucfirst((string) $action->action)),
                'statusFrom' => (string) ($action->status_from ?? ''),
                'statusTo' => (string) ($action->status_to ?? ''),
                'actorStaffId' => $action->actor_staff_id ? (int) $action->actor_staff_id : null,
                'actorName' => (string) ($action->actor_name ?? ''),
                'actorCode' => (string) ($action->actor_code ?? ''),
                'remarks' => (string) ($action->remarks ?? ''),
                'actedAt' => $action->acted_at,
            ])
            ->all();

        return [
            'instanceId' => (int) $instance->id,
            'templateKey' => self::SALARY_TEMPLATE_KEY,
            'subjectType' => (string) $instance->subject_type,
            'subjectId' => (int) $instance->subject_id,
            'status' => (string) $instance->status,
            'currentStepKey' => (string) ($step->step_key ?? $instance->step_key ?? ''),
            'currentStepLabel' => (string) ($step->label ?? $instance->step_label ?? ''),
            'makerStaffId' => $instance->maker_staff_id ? (int) $instance->maker_staff_id : null,
            'submittedAt' => $instance->submitted_at,
            'history' => $history,
            'availableActions' => $this->availableActionsForInstance($request, $instance, $step),
        ];
    }

    private function availableActionsForInstance(?Request $request, object $instance, ?object $step): array
    {
        if (! $request || ! $step || ! in_array((string) $instance->status, [...self::SALARY_PENDING_CHECK_STATUSES, 'Checked'], true)) {
            return [];
        }
        $actorId = $this->staffId($request);
        $isSalary = (string) $instance->subject_type === self::SALARY_SUBJECT_TYPE;
        $canActOnStep = $isSalary
            ? $this->canActOnSalaryStep($request, $step)
            : $this->canActOnStep($request, $step);
        if ($actorId <= 0 || $actorId === (int) $instance->maker_staff_id || ! $canActOnStep) {
            return [];
        }

        if ((string) $step->step_key === 'approve') {
            $checkerTable = (string) $instance->subject_type === self::OTHER_CLAIM_SUBJECT_TYPE
                ? 'hr_other_claim_applications'
                : 'hr_salary_applications';
            $checkerId = DB::table($checkerTable)->where('id', $instance->subject_id)->value('checked_by');
            if ((int) $checkerId === $actorId) {
                return [];
            }
        }

        return [
            ['action' => (string) $step->step_key, 'label' => (string) $step->action_label, 'tone' => (string) $step->step_key === 'approve' ? 'success' : 'info'],
            ['action' => 'reject', 'label' => 'Reject', 'tone' => 'danger'],
        ];
    }

    private function canActOnStep(Request $request, object $step): bool
    {
        $actorId = $this->staffId($request);
        if ($actorId <= 0) {
            return false;
        }

        if ($this->hasAnyRole($request, ['System Admin'])) {
            return true;
        }

        $recipients = DB::table('workflow_step_recipients')
            ->where('step_id', $step->id)
            ->where('active', 1)
            ->pluck('staff_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if (! empty($recipients)) {
            return in_array($actorId, $recipients, true);
        }

        return $this->hasAnyRole($request, $this->decodeJsonArray($step->fallback_roles));
    }

    private function canActOnSalaryStep(Request $request, object $step): bool
    {
        $actorId = $this->staffId($request);
        if ($actorId <= 0) {
            return false;
        }

        $recipients = DB::table('workflow_step_recipients')
            ->where('step_id', $step->id)
            ->where('active', 1)
            ->pluck('staff_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (! empty($recipients)) {
            return in_array($actorId, $recipients, true);
        }

        return $this->hasAnyRole($request, $this->decodeJsonArray($step->fallback_roles));
    }

    private function nextStep(int $templateId, int $sortOrder): ?object
    {
        return DB::table('workflow_template_steps')
            ->where('template_id', $templateId)
            ->where('active', 1)
            ->where('sort_order', '>', $sortOrder)
            ->orderBy('sort_order')
            ->first();
    }

    private function salaryRecordPayload(int $id, ?Request $request): array
    {
        $record = DB::table('hr_salary_applications as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->leftJoin('staff_general as checker', 'checker.staff_id', '=', 'application.checked_by')
            ->leftJoin('staff_general as approver', 'approver.staff_id', '=', 'application.approved_by')
            ->select([
                'application.*',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
                'checker.full_name as checker_name',
                'checker.name_code as checker_code',
                'approver.full_name as approver_name',
                'approver.name_code as approver_code',
            ])
            ->where('application.id', $id)
            ->first();

        $payload = [
            'id' => (int) $record->id,
            'staffId' => (int) $record->staff_id,
            'salaryMonth' => (string) $record->salary_month_label,
            'salaryMonthValue' => (string) $record->salary_month,
            'basicSalary' => (float) $record->basic_salary,
            'claimsTotal' => (float) $record->claims_total,
            'employeeDeductions' => (float) $record->employee_deductions,
            'employerContributions' => (float) $record->employer_contributions,
            'payableSalary' => (float) $record->payable_salary,
            'status' => $this->salaryDisplayStatus((string) $record->status),
            'submittedAt' => $record->submitted_at,
            'staffName' => (string) ($record->staff_name ?? ''),
            'staffCode' => (string) ($record->staff_code ?? ''),
            'checkedBy' => $record->checked_by ? (int) $record->checked_by : null,
            'checkedAt' => $record->checked_at ?? null,
            'checkedStatus' => (string) ($record->checked_status ?? ''),
            'checkedRemarks' => (string) ($record->checked_remarks ?? ''),
            'checkerName' => (string) ($record->checker_name ?? ''),
            'checkerCode' => (string) ($record->checker_code ?? ''),
            'approvedBy' => $record->approved_by ? (int) $record->approved_by : null,
            'approvedAt' => $record->approved_at ?? null,
            'approvedStatus' => (string) ($record->approved_status ?? ''),
            'approvedRemarks' => (string) ($record->approved_remarks ?? ''),
            'approverName' => (string) ($record->approver_name ?? ''),
            'approverCode' => (string) ($record->approver_code ?? ''),
        ];
        $payload['workflow'] = $this->salaryWorkflowPayload((int) $record->id, $request);

        return $payload;
    }

    private function otherClaimRecordPayload(int $id, ?Request $request): array
    {
        $record = DB::table('hr_other_claim_applications as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->leftJoin('staff_general as checker', 'checker.staff_id', '=', 'application.checked_by')
            ->leftJoin('staff_general as approver', 'approver.staff_id', '=', 'application.approved_by')
            ->select([
                'application.*',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
                'checker.full_name as checker_name',
                'checker.name_code as checker_code',
                'approver.full_name as approver_name',
                'approver.name_code as approver_code',
            ])
            ->where('application.id', $id)
            ->first();

        $payload = [
            'id' => (int) $record->id,
            'staffId' => (int) $record->staff_id,
            'claimMonth' => (string) $record->claim_month_label,
            'claimMonthValue' => (string) $record->claim_month,
            'claimsTotal' => (float) $record->claims_total,
            'status' => $this->salaryDisplayStatus((string) $record->status),
            'submittedAt' => $record->submitted_at,
            'staffName' => (string) ($record->staff_name ?? ''),
            'staffCode' => (string) ($record->staff_code ?? ''),
            'checkedBy' => $record->checked_by ? (int) $record->checked_by : null,
            'checkedAt' => $record->checked_at ?? null,
            'checkedStatus' => (string) ($record->checked_status ?? ''),
            'checkedRemarks' => (string) ($record->checked_remarks ?? ''),
            'checkerName' => (string) ($record->checker_name ?? ''),
            'checkerCode' => (string) ($record->checker_code ?? ''),
            'approvedBy' => $record->approved_by ? (int) $record->approved_by : null,
            'approvedAt' => $record->approved_at ?? null,
            'approvedStatus' => (string) ($record->approved_status ?? ''),
            'approvedRemarks' => (string) ($record->approved_remarks ?? ''),
            'approverName' => (string) ($record->approver_name ?? ''),
            'approverCode' => (string) ($record->approver_code ?? ''),
        ];
        $payload['workflow'] = $this->otherClaimWorkflowPayload((int) $record->id, $request);

        return $payload;
    }

    private function salaryDisplayStatus(string $status): string
    {
        return match ($status) {
            'Prepared' => self::SALARY_SUBMITTED_STATUS,
            default => $status,
        };
    }

    private function salaryTemplatePayload(object $template): array
    {
        return [
            ...$this->templateSummary($template),
            'steps' => $this->salarySteps((int) $template->id),
        ];
    }

    private function salarySteps(int $templateId): array
    {
        return $this->centralSteps($templateId, false);
    }

    private function centralSteps(int $templateId, bool $activeOnly = false): array
    {
        $steps = DB::table('workflow_template_steps')
            ->where('template_id', $templateId)
            ->when($activeOnly, fn ($query) => $query->where('active', 1))
            ->orderBy('sort_order')
            ->get();
        $recipients = DB::table('workflow_step_recipients as recipient')
            ->join('staff_general as staff', 'staff.staff_id', '=', 'recipient.staff_id')
            ->whereIn('recipient.step_id', $steps->pluck('id')->all())
            ->where('recipient.active', 1)
            ->select([
                'recipient.step_id',
                'recipient.staff_id',
                'recipient.sort_order',
                'staff.full_name',
                'staff.name_code',
                Schema::hasColumn('staff_general', 'email') ? DB::raw('staff.email as email') : DB::raw('NULL as email'),
            ])
            ->orderBy('recipient.sort_order')
            ->get()
            ->groupBy('step_id');

        return $steps->map(function (object $step) use ($recipients): array {
            $fallbackRoles = $this->decodeJsonArray($step->fallback_roles);
            $configured = ($recipients[$step->id] ?? collect())->map(fn (object $row): array => $this->formatStaff($row))->values()->all();

            return [
                'id' => (int) $step->id,
                'stepKey' => (string) $step->step_key,
                'levelNo' => (int) $step->level_no,
                'label' => (string) $step->label,
                'actionLabel' => (string) $step->action_label,
                'fallbackRoles' => $fallbackRoles,
                'fallbackLabel' => implode(', ', $fallbackRoles),
                'description' => '',
                'recipients' => $configured,
                'effectiveRecipients' => ! empty($configured) ? $configured : $this->activeStaffForRoles($fallbackRoles),
                'usingDefault' => empty($configured),
                'active' => (bool) $step->active,
            ];
        })->all();
    }

    private function configuredStepRecipients(int $stepId): array
    {
        if (! Schema::hasTable('staff_general')) {
            return [];
        }

        return DB::table('workflow_step_recipients as recipient')
            ->join('staff_general as staff', 'staff.staff_id', '=', 'recipient.staff_id')
            ->where('recipient.step_id', $stepId)
            ->where('recipient.active', 1)
            ->whereNull('staff.deleted_at')
            ->whereRaw("LOWER(COALESCE(staff.status, '')) = 'active'")
            ->select([
                'recipient.staff_id',
                'recipient.sort_order',
                'staff.full_name',
                'staff.name_code',
                Schema::hasColumn('staff_general', 'email') ? DB::raw('staff.email as email') : DB::raw('NULL as email'),
            ])
            ->orderBy('recipient.sort_order')
            ->get()
            ->map(fn (object $row): array => $this->formatStaff($row))
            ->values()
            ->all();
    }

    private function vendorSettingsFromSteps(array $steps): array
    {
        $reviewLevels = collect($steps)
            ->filter(fn (array $step): bool => ($step['stepKey'] ?? '') === 'review')
            ->max(fn (array $step): int => (int) ($step['levelNo'] ?? 0)) ?: 0;
        $approvalLevels = collect($steps)
            ->filter(fn (array $step): bool => ($step['stepKey'] ?? '') === 'approval')
            ->max(fn (array $step): int => (int) ($step['levelNo'] ?? 0)) ?: 0;

        return [
            'review_enabled' => $reviewLevels > 0,
            'review_levels' => $reviewLevels,
            'approval_enabled' => $approvalLevels > 0,
            'approval_levels' => $approvalLevels,
        ];
    }

    private function syncVendorTemplateSteps(int $templateId, array $settings): void
    {
        $now = now();
        DB::table('workflow_template_steps')->where('template_id', $templateId)->update([
            'active' => 0,
            'updated_at' => $now,
        ]);

        $rows = [];
        if ((bool) $settings['review_enabled']) {
            $levels = max(1, min(5, (int) $settings['review_levels']));
            for ($level = 1; $level <= $levels; $level++) {
                $rows[] = [
                    'step_key' => 'review',
                    'level_no' => $level,
                    'sort_order' => 10 + $level,
                    'label' => $levels > 1 ? "Review Level {$level}" : 'Review',
                    'action_label' => 'Review',
                    'fallback_roles' => json_encode(self::VENDOR_FALLBACK_ROLES['review']),
                ];
            }
        }
        if ((bool) $settings['approval_enabled']) {
            $levels = max(1, min(5, (int) $settings['approval_levels']));
            for ($level = 1; $level <= $levels; $level++) {
                $rows[] = [
                    'step_key' => 'approval',
                    'level_no' => $level,
                    'sort_order' => 30 + $level,
                    'label' => $levels > 1 ? "Approval Level {$level}" : 'Approval',
                    'action_label' => 'Approve',
                    'fallback_roles' => json_encode(self::VENDOR_FALLBACK_ROLES['approval']),
                ];
            }
        }
        $rows[] = [
            'step_key' => 'finance',
            'level_no' => 1,
            'sort_order' => 60,
            'label' => 'Finance',
            'action_label' => 'Mark Paid',
            'fallback_roles' => json_encode(self::VENDOR_FALLBACK_ROLES['finance']),
        ];

        foreach ($rows as $row) {
            DB::table('workflow_template_steps')->updateOrInsert(
                [
                    'template_id' => $templateId,
                    'step_key' => $row['step_key'],
                    'level_no' => $row['level_no'],
                ],
                [
                    ...$row,
                    'template_id' => $templateId,
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    private function syncStepRecipients(int $templateId, array $stepsPayload): void
    {
        $stepIds = DB::table('workflow_template_steps')
            ->where('template_id', $templateId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (! empty($stepIds)) {
            DB::table('workflow_step_recipients')->whereIn('step_id', $stepIds)->update([
                'active' => 0,
                'updated_at' => now(),
            ]);
        }

        $steps = DB::table('workflow_template_steps')
            ->where('template_id', $templateId)
            ->get()
            ->keyBy(fn (object $step): string => $step->step_key.'.'.(int) $step->level_no);

        foreach ($stepsPayload as $step) {
            $stepKey = (string) ($step['stepKey'] ?? '');
            $level = $stepKey === 'finance' ? 1 : (int) ($step['levelNo'] ?? 1);
            $templateStep = $steps->get($stepKey.'.'.$level);
            if (! $templateStep || ! (bool) $templateStep->active) {
                continue;
            }

            foreach ($this->validStaffIds($step['recipient_staff_ids'] ?? []) as $index => $staffId) {
                DB::table('workflow_step_recipients')->updateOrInsert(
                    ['step_id' => (int) $templateStep->id, 'staff_id' => $staffId],
                    [
                        'sort_order' => $index,
                        'active' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    private function vendorTemplatePayload(Request $request): array
    {
        $template = $this->templateByKey(self::VENDOR_TEMPLATE_KEY);
        $steps = $template ? $this->centralSteps((int) $template->id, true) : [];

        return [
            'status' => 'success',
            'template' => [
                'key' => self::VENDOR_TEMPLATE_KEY,
                'processKey' => self::VENDOR_TEMPLATE_KEY,
                'label' => 'Vendor Payment',
                'moduleKey' => 'vendor',
                'routePattern' => '/vendor/payment-records/{id}',
                'enabled' => true,
                'adapter' => 'vendor',
                'settings' => $this->vendorSettingsFromSteps($steps),
                'steps' => $steps,
            ],
            'active_staff' => $this->activeStaff(),
            'can_edit' => $this->hasAnyRole($request, self::MANAGE_ROLES),
        ];
    }

    private function updateVendorTemplate(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'settings.review_enabled' => ['required', 'boolean'],
            'settings.review_levels' => ['required', 'integer', 'min:0', 'max:5'],
            'settings.approval_enabled' => ['required', 'boolean'],
            'settings.approval_levels' => ['required', 'integer', 'min:0', 'max:5'],
            'steps' => ['nullable', 'array'],
            'steps.*.stepKey' => ['required', 'string', 'in:review,approval,finance'],
            'steps.*.levelNo' => ['required', 'integer', 'min:1', 'max:5'],
            'steps.*.recipient_staff_ids' => ['nullable', 'array'],
            'steps.*.recipient_staff_ids.*' => ['integer', 'min:1'],
        ])->validate();
        $settings = $data['settings'];
        if ($settings['review_enabled'] && (int) $settings['review_levels'] < 1) {
            throw ValidationException::withMessages(['settings.review_levels' => 'Review levels must be at least 1 when review is enabled.']);
        }
        if ($settings['approval_enabled'] && (int) $settings['approval_levels'] < 1) {
            throw ValidationException::withMessages(['settings.approval_levels' => 'Approval levels must be at least 1 when approval is enabled.']);
        }

        DB::transaction(function () use ($data, $settings): void {
            $template = $this->templateByKey(self::VENDOR_TEMPLATE_KEY);
            $this->syncVendorTemplateSteps((int) $template->id, $settings);
            $this->syncStepRecipients((int) $template->id, $data['steps'] ?? []);
        });

        return [
            ...$this->vendorTemplatePayload($request),
            'message' => 'Vendor payment workflow settings saved.',
        ];
    }

    private function leaveTemplatePayload(Request $request): array
    {
        $template = $this->templateByKey(self::LEAVE_TEMPLATE_KEY);

        return [
            'status' => 'success',
            'template' => [
                'key' => self::LEAVE_TEMPLATE_KEY,
                'processKey' => self::LEAVE_TEMPLATE_KEY,
                'label' => 'Leave Application',
                'moduleKey' => 'leave',
                'routePattern' => '/staff/leaves/records/{id}',
                'enabled' => true,
                'adapter' => 'leave',
                'steps' => $template ? $this->centralSteps((int) $template->id, true) : [],
            ],
            'active_staff' => $this->activeStaff(),
            'can_edit' => $this->hasAnyRole($request, self::MANAGE_ROLES),
        ];
    }

    private function updateLeaveTemplate(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'steps' => ['required', 'array'],
            'steps.*.stepKey' => ['required', 'string'],
            'steps.*.recipient_staff_ids' => ['nullable', 'array'],
            'steps.*.recipient_staff_ids.*' => ['integer', 'min:1'],
        ])->validate();
        DB::transaction(function () use ($data): void {
            $template = $this->templateByKey(self::LEAVE_TEMPLATE_KEY);
            $this->syncStepRecipients((int) $template->id, $data['steps']);
        });

        return [
            ...$this->leaveTemplatePayload($request),
            'message' => 'Leave workflow recipients saved.',
        ];
    }

    private function genericTemplatePayload(Request $request, string $key): array
    {
        $template = $this->templateByKey($key);
        if (! $template) {
            return ['status' => 'error', 'message' => 'Workflow template not found.'];
        }

        return [
            'status' => 'success',
            'template' => [
                ...$this->templateSummary($template),
                'steps' => $this->centralSteps((int) $template->id, true),
            ],
            'active_staff' => $this->activeStaff(),
            'can_edit' => $this->hasAnyRole($request, self::MANAGE_ROLES),
        ];
    }

    private function updateGenericTemplate(Request $request, string $key, string $message): array
    {
        $data = Validator::make($request->all(), [
            'steps' => ['required', 'array'],
            'steps.*.id' => ['required', 'integer', 'min:1'],
            'steps.*.recipient_staff_ids' => ['nullable', 'array'],
            'steps.*.recipient_staff_ids.*' => ['integer', 'min:1'],
        ])->validate();

        $template = $this->templateByKey($key);
        if (! $template) {
            return ['status' => 'error', 'message' => 'Workflow template not found.'];
        }

        $stepsPayload = $data['steps'];
        $validStepIds = DB::table('workflow_template_steps')
            ->where('template_id', $template->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $validStaffIds = $this->validStaffIds(collect($stepsPayload)->flatMap(
            fn ($step): array => (array) ($step['recipient_staff_ids'] ?? []),
        )->all());

        DB::transaction(function () use ($stepsPayload, $validStepIds, $validStaffIds): void {
            foreach ($stepsPayload as $step) {
                $stepId = (int) $step['id'];
                if (! in_array($stepId, $validStepIds, true)) {
                    continue;
                }
                DB::table('workflow_step_recipients')->where('step_id', $stepId)->update([
                    'active' => 0,
                    'updated_at' => now(),
                ]);
                foreach (array_values(array_intersect(
                    array_map('intval', (array) ($step['recipient_staff_ids'] ?? [])),
                    $validStaffIds,
                )) as $index => $staffId) {
                    DB::table('workflow_step_recipients')->updateOrInsert(
                        ['step_id' => $stepId, 'staff_id' => $staffId],
                        [
                            'sort_order' => $index,
                            'active' => 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                }
            }
        });

        return [
            ...$this->genericTemplatePayload($request, $key),
            'message' => $message,
        ];
    }

    private function templateSummary(object $template): array
    {
        return [
            'id' => (int) $template->id,
            'key' => (string) $template->process_key,
            'processKey' => (string) $template->process_key,
            'label' => (string) $template->label,
            'moduleKey' => (string) $template->module_key,
            'routePattern' => (string) ($template->route_pattern ?? ''),
            'enabled' => (bool) $template->enabled,
        ];
    }

    private function templateByKey(string $key): ?object
    {
        return DB::table('workflow_templates')->where('process_key', $key)->first();
    }

    private function ensureDefaultTemplates(): void
    {
        if (! Schema::hasTable('workflow_templates')) {
            return;
        }

        $now = now();
        DB::table('workflow_templates')->updateOrInsert(
            ['process_key' => self::SALARY_TEMPLATE_KEY],
            [
                'label' => 'Salary',
                'module_key' => 'salary',
                'route_pattern' => '/financial/salary-records',
                'enabled' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        $salaryTemplateId = (int) DB::table('workflow_templates')
            ->where('process_key', self::SALARY_TEMPLATE_KEY)
            ->value('id');
        foreach ([
            [
                'step_key' => 'check',
                'level_no' => 1,
                'sort_order' => 10,
                'label' => 'Check',
                'action_label' => 'Check',
                'fallback_roles' => json_encode(['Finance', 'Account', 'HR', 'Manager', 'System Admin']),
            ],
            [
                'step_key' => 'approve',
                'level_no' => 1,
                'sort_order' => 20,
                'label' => 'Approve',
                'action_label' => 'Approve',
                'fallback_roles' => json_encode(['Manager', 'Finance', 'System Admin']),
            ],
        ] as $step) {
            DB::table('workflow_template_steps')->updateOrInsert(
                [
                    'template_id' => $salaryTemplateId,
                    'step_key' => $step['step_key'],
                    'level_no' => $step['level_no'],
                ],
                [
                    ...$step,
                    'template_id' => $salaryTemplateId,
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
        foreach ([
            ['vendor-payment', 'Vendor Payment', 'vendor', '/vendor/payment-records/{id}'],
            ['leave-application', 'Leave Application', 'leave', '/staff/leaves/records/{id}'],
            ['quote-price-exception', 'Negotiation', 'crm', '/crm/price-exceptions/{id}'],
            [self::QUOTE_APPROVAL_TEMPLATE_KEY, 'Quotation Approval', 'crm', '/crm/records?approval_scope=mine'],
        ] as [$key, $label, $module, $route]) {
            DB::table('workflow_templates')->updateOrInsert(
                ['process_key' => $key],
                [
                    'label' => $label,
                    'module_key' => $module,
                    'route_pattern' => $route,
                    'enabled' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $vendorTemplateId = (int) DB::table('workflow_templates')
            ->where('process_key', self::VENDOR_TEMPLATE_KEY)
            ->value('id');
        if (
            $vendorTemplateId > 0
            && ! DB::table('workflow_template_steps')->where('template_id', $vendorTemplateId)->exists()
        ) {
            $this->syncVendorTemplateSteps($vendorTemplateId, [
                'review_enabled' => true,
                'review_levels' => 1,
                'approval_enabled' => true,
                'approval_levels' => 1,
            ]);
        }

        $leaveTemplateId = (int) DB::table('workflow_templates')
            ->where('process_key', self::LEAVE_TEMPLATE_KEY)
            ->value('id');
        if ($leaveTemplateId > 0) {
            foreach ([
                [
                    'step_key' => 'leave.submitted.recommenders',
                    'label' => 'New Application',
                    'action_label' => 'Recommend',
                    'fallback_roles' => json_encode(self::LEAVE_FALLBACK_ROLES['leave.submitted.recommenders']),
                    'sort_order' => 10,
                ],
                [
                    'step_key' => 'leave.recommended.approvers',
                    'label' => 'Recommended Leave',
                    'action_label' => 'Approve',
                    'fallback_roles' => json_encode(self::LEAVE_FALLBACK_ROLES['leave.recommended.approvers']),
                    'sort_order' => 20,
                ],
                [
                    'step_key' => 'leave.approved.notify',
                    'label' => 'Approved Leave',
                    'action_label' => 'Notify',
                    'fallback_roles' => json_encode([]),
                    'sort_order' => 30,
                ],
                [
                    'step_key' => 'leave.rejected.notify',
                    'label' => 'Rejected Leave',
                    'action_label' => 'Notify',
                    'fallback_roles' => json_encode([]),
                    'sort_order' => 40,
                ],
                [
                    'step_key' => 'leave.cancelled.notify',
                    'label' => 'Cancelled Leave',
                    'action_label' => 'Notify',
                    'fallback_roles' => json_encode([]),
                    'sort_order' => 50,
                ],
                [
                    'step_key' => 'leave.revoked.notify',
                    'label' => 'Revoked Leave',
                    'action_label' => 'Notify',
                    'fallback_roles' => json_encode([]),
                    'sort_order' => 60,
                ],
            ] as $step) {
                DB::table('workflow_template_steps')->updateOrInsert(
                    [
                        'template_id' => $leaveTemplateId,
                        'step_key' => $step['step_key'],
                        'level_no' => 1,
                    ],
                    [
                        ...$step,
                        'template_id' => $leaveTemplateId,
                        'level_no' => 1,
                        'active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }

        $negotiationTemplateId = (int) DB::table('workflow_templates')
            ->where('process_key', self::NEGOTIATION_TEMPLATE_KEY)
            ->value('id');
        if ($negotiationTemplateId > 0) {
            DB::table('workflow_template_steps')->updateOrInsert(
                [
                    'template_id' => $negotiationTemplateId,
                    'step_key' => 'approve',
                    'level_no' => 1,
                ],
                [
                    'template_id' => $negotiationTemplateId,
                    'step_key' => 'approve',
                    'level_no' => 1,
                    'sort_order' => 10,
                    'label' => 'Approval',
                    'action_label' => 'Approve',
                    'fallback_roles' => json_encode(self::NEGOTIATION_FALLBACK_ROLES['approve']),
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $quoteApprovalTemplateId = (int) DB::table('workflow_templates')
            ->where('process_key', self::QUOTE_APPROVAL_TEMPLATE_KEY)
            ->value('id');
        if ($quoteApprovalTemplateId > 0) {
            foreach ([
                ['step_key' => 'hod', 'sort_order' => 10, 'label' => 'HOD Approval'],
                ['step_key' => 'bd', 'sort_order' => 20, 'label' => 'BD Final Approval'],
            ] as $step) {
                DB::table('workflow_template_steps')->updateOrInsert(
                    [
                        'template_id' => $quoteApprovalTemplateId,
                        'step_key' => $step['step_key'],
                        'level_no' => 1,
                    ],
                    [
                        ...$step,
                        'template_id' => $quoteApprovalTemplateId,
                        'level_no' => 1,
                        'action_label' => 'Approve',
                        'fallback_roles' => json_encode([]),
                        'active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    private function templateStep(string $templateKey, string $stepKey, int $levelNo = 1): ?object
    {
        if (! Schema::hasTable('workflow_templates') || ! Schema::hasTable('workflow_template_steps')) {
            return null;
        }

        $this->ensureDefaultTemplates();

        return DB::table('workflow_template_steps as step')
            ->join('workflow_templates as template', 'template.id', '=', 'step.template_id')
            ->where('template.process_key', $templateKey)
            ->where('step.step_key', $stepKey)
            ->where('step.level_no', $levelNo)
            ->where('step.active', 1)
            ->select('step.*')
            ->first();
    }

    private function activeStaff(): array
    {
        if (! Schema::hasTable('staff_general')) {
            return [];
        }

        return DB::table('staff_general')
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->select([
                'staff_id',
                'full_name',
                'name_code',
                Schema::hasColumn('staff_general', 'email') ? DB::raw('email as email') : DB::raw('NULL as email'),
            ])
            ->orderBy('full_name')
            ->get()
            ->map(fn (object $row): array => $this->formatStaff($row))
            ->values()
            ->all();
    }

    private function activeStaffForRoles(array $roles): array
    {
        if (empty($roles) || ! Schema::hasTable('system_users') || ! Schema::hasTable('staff_general')) {
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
                Schema::hasColumn('staff_general', 'email') ? DB::raw('COALESCE(NULLIF(su.email, ""), sg.email) as email') : DB::raw('su.email as email'),
                'su.role',
            ])
            ->get()
            ->filter(fn (object $row): bool => $this->rolesMatch($row->role ?? null, $allowed))
            ->map(fn (object $row): array => $this->formatStaff($row))
            ->unique('staff_id')
            ->values()
            ->all();
    }

    private function validStaffIds(array $ids): array
    {
        $staffIds = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($staffIds) || ! Schema::hasTable('staff_general')) {
            return [];
        }

        return DB::table('staff_general')
            ->whereIn('staff_id', $staffIds)
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->pluck('staff_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function staffId(Request $request): int
    {
        return (int) $request->session()->get('staff_id', 0);
    }

    private function hasAnyRole(Request $request, array $allowedRoles): bool
    {
        $roles = $request->session()->get('roles', []);
        $roles = is_array($roles) ? $roles : [$roles];
        $roleKeys = array_map(static fn ($role): string => strtolower(trim((string) $role)), $roles);
        if (in_array('system admin', $roleKeys, true)) {
            return true;
        }

        $allowed = array_map(static fn ($role): string => strtolower(trim((string) $role)), $allowedRoles);

        foreach ($roleKeys as $role) {
            if (in_array($role, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    private function rolesMatch(mixed $rawRoles, array $allowedRoles): bool
    {
        $decoded = is_string($rawRoles) ? json_decode($rawRoles, true) : null;
        $roles = is_array($decoded) ? $decoded : (is_array($rawRoles) ? $rawRoles : [$rawRoles]);
        $normalized = array_map(static fn ($role): string => strtolower(trim((string) $role)), $roles);

        return ! empty(array_intersect($allowedRoles, $normalized));
    }

    private function decodeJsonArray(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function formatStaff(object $row): array
    {
        return [
            'staff_id' => (int) $row->staff_id,
            'full_name' => (string) ($row->full_name ?? ''),
            'name_code' => (string) ($row->name_code ?? ''),
            'email' => (string) ($row->email ?? ''),
        ];
    }

    private function staffLabel(mixed $name, mixed $code, mixed $staffId): string
    {
        $name = trim((string) $name);
        $code = trim((string) $code);
        if ($name !== '' && $code !== '') {
            return "{$name} ({$code})";
        }

        return $name ?: ($code ?: 'Staff #'.(int) $staffId);
    }
}
