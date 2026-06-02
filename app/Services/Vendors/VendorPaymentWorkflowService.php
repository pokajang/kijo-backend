<?php

namespace App\Services\Vendors;

use App\Jobs\SendHtmlMailJob;
use App\Services\AppNotificationService;
use App\Services\Mail\SystemEmailBodyBuilder;
use App\Services\Mail\SystemEmailUrlBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VendorPaymentWorkflowService
{
    public const STAGE_REVIEW = 'review';

    public const STAGE_APPROVAL = 'approval';

    public const STAGE_FINANCE = 'finance';

    private const TEMPLATE_KEY = 'vendor-payment';

    private const LEGACY_SETTINGS_TABLE = 'vendor_payment_workflow_settings';

    private const LEGACY_RECIPIENTS_TABLE = 'vendor_payment_workflow_recipients';

    private const FALLBACK_ROLES = ['Manager', 'System Admin'];

    private const FINANCE_FALLBACK_ROLES = ['Finance', 'Account', 'Bank', 'Manager', 'System Admin'];

    private const DEFAULT_SETTINGS = [
        'review_enabled' => true,
        'review_levels' => 1,
        'approval_enabled' => true,
        'approval_levels' => 1,
    ];

    public function settings(): array
    {
        if (! $this->centralTablesAvailable()) {
            return $this->legacySettings();
        }

        $templateId = $this->ensureCentralTemplate();
        $steps = DB::table('workflow_template_steps')
            ->where('template_id', $templateId)
            ->where('active', 1)
            ->select(['step_key', 'level_no'])
            ->get();
        $reviewLevels = $steps->where('step_key', self::STAGE_REVIEW)->max(
            fn ($step): int => (int) $step->level_no,
        ) ?: 0;
        $approvalLevels = $steps->where('step_key', self::STAGE_APPROVAL)->max(
            fn ($step): int => (int) $step->level_no,
        ) ?: 0;

        return [
            'review_enabled' => $reviewLevels > 0,
            'review_levels' => $reviewLevels,
            'approval_enabled' => $approvalLevels > 0,
            'approval_levels' => $approvalLevels,
        ];
    }

    public function stageEnabled(string $stageType): bool
    {
        if ($stageType === self::STAGE_FINANCE) {
            return true;
        }

        $settings = $this->settings();

        return $stageType === self::STAGE_REVIEW
            ? (bool) $settings['review_enabled'] && (int) $settings['review_levels'] > 0
            : (bool) $settings['approval_enabled'] && (int) $settings['approval_levels'] > 0;
    }

    public function stageLevels(string $stageType): int
    {
        if ($stageType === self::STAGE_FINANCE) {
            return 1;
        }

        $settings = $this->settings();

        return $stageType === self::STAGE_REVIEW
            ? ($settings['review_enabled'] ? (int) $settings['review_levels'] : 0)
            : ($settings['approval_enabled'] ? (int) $settings['approval_levels'] : 0);
    }

    public function initialStatus(): string
    {
        if ($this->stageEnabled(self::STAGE_REVIEW)) {
            return 'Pending';
        }

        return $this->stageEnabled(self::STAGE_APPROVAL) ? 'Checked' : 'Approved';
    }

    public function snapshot(): string
    {
        return json_encode([
            'settings' => $this->settings(),
            'stages' => $this->serializedStages(),
        ]);
    }

    public function currentLevel(object $payment, string $stageType): int
    {
        if ($stageType === self::STAGE_FINANCE) {
            return 1;
        }

        $column = $stageType === self::STAGE_REVIEW ? 'current_review_level' : 'current_approval_level';

        return max(1, (int) ($payment->{$column} ?? 1));
    }

    public function canAct(Request $request, string $stageType, int $level): bool
    {
        $actorId = (int) $request->session()->get('staff_id', 0);
        if ($actorId <= 0) {
            return false;
        }
        if ($this->hasFallbackRole($request, ['System Admin'])) {
            return true;
        }

        $configured = $this->configuredStaffIds($stageType, $level);
        if (! empty($configured)) {
            return in_array($actorId, $configured, true);
        }

        return $this->hasFallbackRole($request, $this->fallbackRolesForStage($stageType));
    }

    public function hasConfiguredRecipients(string $stageType, int $level): bool
    {
        return ! empty($this->configuredStaffIds($stageType, $level));
    }

    public function actorAlreadyCompleted(object $payment, int $staffId, string $stageType): bool
    {
        $progress = $this->progress($payment);
        foreach ($progress as $entry) {
            if (
                ($entry['stage_type'] ?? '') === $stageType
                && (int) ($entry['staff_id'] ?? 0) === $staffId
            ) {
                return true;
            }
        }

        return false;
    }

    public function appendProgress(object $payment, string $stageType, int $level, int $staffId, ?string $remarks): string
    {
        $progress = $this->progress($payment);
        $progress[] = [
            'stage_type' => $stageType,
            'level_no' => $level,
            'staff_id' => $staffId,
            'remarks' => $remarks,
            'completed_at' => now()->toDateTimeString(),
        ];

        return json_encode($progress);
    }

    public function notifyStage(Request $request, int $paymentId, string $stageType, int $level, array $payload): void
    {
        $actorId = (int) $request->session()->get('staff_id', 0);
        $staffIds = array_values(array_diff($this->staffIdsForStage($stageType, $level), [$actorId]));
        if (empty($staffIds)) {
            return;
        }

        app(AppNotificationService::class)->createForStaff($staffIds, array_merge([
            'module_key' => 'vendor.payments',
            'entity_type' => 'vendor_payment',
            'entity_id' => $paymentId,
            'actor_staff_id' => $actorId ?: null,
            'route' => "/vendor/payment-records/{$paymentId}",
            'severity' => 'warning',
        ], $payload));

        $this->sendStageEmail($staffIds, $paymentId, $payload);
    }

    public function staffIdsForStage(string $stageType, int $level): array
    {
        $configured = $this->configuredStaffIds($stageType, $level);
        if (! empty($configured)) {
            return $configured;
        }

        return app(AppNotificationService::class)->staffIdsForRoles($this->fallbackRolesForStage($stageType));
    }

    private function serializedStages(): array
    {
        $settings = $this->settings();
        $recipients = $this->configuredRecipientsByStage();

        return array_map(function (array $stage) use ($recipients): array {
            $key = $stage['stage_type'].'.'.$stage['level_no'];
            $configured = $recipients[$key] ?? [];
            $fallback = $this->activeStaffForRoles($this->fallbackRolesForStage($stage['stage_type']));

            return [
                'key' => $key,
                'stage_type' => $stage['stage_type'],
                'level_no' => $stage['level_no'],
                'label' => $this->stageLabel($stage['stage_type'], (int) $stage['level_no']),
                'recipients' => $configured,
                'effective_recipients' => ! empty($configured) ? $configured : $fallback,
                'using_default' => empty($configured),
            ];
        }, $this->stageDefinitions($settings));
    }

    private function stageDefinitions(array $settings): array
    {
        $stages = [];
        if ((bool) $settings['review_enabled']) {
            for ($level = 1; $level <= (int) $settings['review_levels']; $level++) {
                $stages[] = ['stage_type' => self::STAGE_REVIEW, 'level_no' => $level];
            }
        }
        if ((bool) $settings['approval_enabled']) {
            for ($level = 1; $level <= (int) $settings['approval_levels']; $level++) {
                $stages[] = ['stage_type' => self::STAGE_APPROVAL, 'level_no' => $level];
            }
        }
        $stages[] = ['stage_type' => self::STAGE_FINANCE, 'level_no' => 1];

        return $stages;
    }

    private function configuredStaffIds(string $stageType, int $level): array
    {
        if (! $this->centralTablesAvailable()) {
            return $this->legacyConfiguredStaffIds($stageType, $level);
        }

        $query = DB::table('workflow_step_recipients as r')
            ->join('workflow_template_steps as step', 'step.id', '=', 'r.step_id')
            ->join('workflow_templates as template', 'template.id', '=', 'step.template_id')
            ->where('template.process_key', self::TEMPLATE_KEY)
            ->where('step.step_key', $stageType)
            ->where('step.level_no', $stageType === self::STAGE_FINANCE ? 1 : $level)
            ->where('step.active', 1)
            ->where('r.active', 1);

        if (Schema::hasTable('staff_general')) {
            $query->join('staff_general as sg', 'sg.staff_id', '=', 'r.staff_id')
                ->whereNull('sg.deleted_at')
                ->whereRaw("LOWER(COALESCE(sg.status, '')) = 'active'");
        }

        if (Schema::hasTable('system_users')) {
            $query->join('system_users as su', 'su.staff_id', '=', 'r.staff_id')
                ->where('su.is_active', 1);
        }

        $ids = $query
            ->orderBy('r.sort_order')
            ->pluck('r.staff_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $ids;
    }

    private function configuredRecipientsByStage(): array
    {
        if (! $this->centralTablesAvailable() || ! Schema::hasTable('staff_general')) {
            return $this->legacyConfiguredRecipientsByStage();
        }

        $central = DB::table('workflow_step_recipients as r')
            ->join('workflow_template_steps as step', 'step.id', '=', 'r.step_id')
            ->join('workflow_templates as template', 'template.id', '=', 'step.template_id')
            ->join('staff_general as sg', 'sg.staff_id', '=', 'r.staff_id')
            ->where('template.process_key', self::TEMPLATE_KEY)
            ->where('step.active', 1)
            ->where('r.active', 1)
            ->whereNull('sg.deleted_at')
            ->whereRaw("LOWER(COALESCE(sg.status, '')) = 'active'")
            ->select([
                'step.step_key as stage_type',
                'step.level_no',
                'r.staff_id',
                'r.sort_order',
                'sg.full_name',
                'sg.name_code',
                Schema::hasColumn('staff_general', 'email')
                    ? DB::raw('sg.email as email')
                    : DB::raw('NULL as email'),
            ])
            ->orderBy('step.sort_order')
            ->orderBy('r.sort_order')
            ->get()
            ->groupBy(fn ($row) => $row->stage_type.'.'.$row->level_no)
            ->map(fn ($rows) => $rows->map(fn ($row) => $this->formatStaff($row))->values()->all())
            ->all();
        return $central;
    }

    private function activeStaffForRoles(array $roles): array
    {
        if (! Schema::hasTable('system_users') || ! Schema::hasTable('staff_general')) {
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
                Schema::hasColumn('staff_general', 'email')
                    ? DB::raw('COALESCE(NULLIF(su.email, ""), sg.email) as email')
                    : DB::raw('su.email as email'),
                'su.role',
            ])
            ->get()
            ->filter(fn ($row) => $this->rolesMatch($row->role ?? null, $allowed))
            ->map(fn ($row) => $this->formatStaff($row))
            ->unique('staff_id')
            ->values()
            ->all();
    }

    private function sendStageEmail(array $staffIds, int $paymentId, array $payload): void
    {
        $recipients = $this->emailRecipientsForStaffIds($staffIds);
        if (empty($recipients)) {
            return;
        }

        $subject = (string) ($payload['email_subject'] ?? $payload['title'] ?? 'Vendor payment notification');
        $body = $this->stageEmailBody($paymentId, $payload);
        $fromAddress = (string) config('mail.from.address', 'kijo@work.amiosh.com');
        $fromName = (string) config('mail.from.name', 'Kijo Alert');
        $presentation = $this->emailBody()->presentation('Vendor Payment', $subject, 'Workflow update', $subject);

        foreach ($recipients as $recipient) {
            try {
                SendHtmlMailJob::dispatchSync(
                    $recipient['email'],
                    $recipient['name'],
                    $subject,
                    $body,
                    [],
                    $fromAddress,
                    $fromName,
                    $presentation,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function emailRecipientsForStaffIds(array $staffIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $staffIds))));
        if (empty($ids)) {
            return [];
        }

        if (! Schema::hasTable('staff_general')) {
            return [];
        }

        $query = DB::table('staff_general as sg')
            ->whereIn('sg.staff_id', $ids)
            ->whereNull('sg.deleted_at')
            ->whereRaw("LOWER(COALESCE(sg.status, '')) = 'active'");

        if (Schema::hasTable('system_users')) {
            $query->leftJoin('system_users as su', 'su.staff_id', '=', 'sg.staff_id')
                ->where(function ($query): void {
                    $query->whereNull('su.id')->orWhere('su.is_active', 1);
                });
        }

        return $query
            ->select([
                'sg.staff_id',
                'sg.full_name',
                'sg.name_code',
                Schema::hasTable('system_users')
                    ? DB::raw('COALESCE(NULLIF(su.email, ""), sg.email) as email')
                    : DB::raw('sg.email as email'),
            ])
            ->get()
            ->filter(fn ($row) => filter_var(trim((string) ($row->email ?? '')), FILTER_VALIDATE_EMAIL) !== false)
            ->map(fn ($row) => [
                'name' => (string) ($row->full_name ?: $row->name_code ?: 'Recipient'),
                'email' => trim((string) $row->email),
            ])
            ->unique('email')
            ->values()
            ->all();
    }

    private function stageEmailBody(int $paymentId, array $payload): string
    {
        $title = (string) ($payload['title'] ?? 'Vendor payment notification');
        $message = (string) ($payload['message'] ?? 'A vendor payment needs your attention.');
        $route = (string) ($payload['route'] ?? "/vendor/payment-records/{$paymentId}");
        $url = $this->emailUrls()->frontendUrl($route);

        return $this->emailBody()->render([
            'intro' => [
                'Dear recipient,',
                $title,
                $message,
            ],
            'status' => ['label' => 'Action Required', 'tone' => 'warning'],
            'detailsHeading' => 'Vendor Payment Details',
            'details' => [
                'Payment ID' => '#'.$paymentId,
                'Status' => (string) ($payload['title'] ?? 'Vendor payment notification'),
            ],
            'actionUrl' => $url,
            'actionLabel' => "Open vendor payment #{$paymentId}",
        ]);
    }

    private function progress(object $payment): array
    {
        $raw = $payment->workflow_progress_json ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);

        return is_array($decoded) ? $decoded : [];
    }

    private function hasFallbackRole(Request $request, array $fallbackRoles): bool
    {
        $roles = $request->session()->get('roles', []);
        $roles = is_array($roles) ? $roles : [$roles];
        $roleKeys = array_map(static fn ($role): string => strtolower(trim((string) $role)), $roles);
        if (in_array('system admin', $roleKeys, true)) {
            return true;
        }

        $allowed = array_map(static fn ($role) => strtolower($role), $fallbackRoles);

        foreach ($roleKeys as $role) {
            if (in_array($role, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    private function fallbackRolesForStage(string $stageType): array
    {
        return $stageType === self::STAGE_FINANCE ? self::FINANCE_FALLBACK_ROLES : self::FALLBACK_ROLES;
    }

    private function stageLabel(string $stageType, int $level): string
    {
        if ($stageType === self::STAGE_FINANCE) {
            return 'Finance';
        }

        return $stageType === self::STAGE_REVIEW
            ? 'Review Level '.$level
            : 'Approval Level '.$level;
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

    private function rolesMatch(mixed $rawRoles, array $allowedRoles): bool
    {
        $decoded = is_string($rawRoles) ? json_decode($rawRoles, true) : null;
        $roles = is_array($decoded) ? $decoded : (is_array($rawRoles) ? $rawRoles : [$rawRoles]);
        $normalized = array_map(static fn ($role): string => strtolower(trim((string) $role)), $roles);

        return ! empty(array_intersect($allowedRoles, $normalized));
    }

    private function legacySettings(): array
    {
        if (! Schema::hasTable(self::LEGACY_SETTINGS_TABLE)) {
            return self::DEFAULT_SETTINGS;
        }

        $stored = DB::table(self::LEGACY_SETTINGS_TABLE)
            ->pluck('setting_value', 'setting_key')
            ->all();

        return [
            'review_enabled' => $this->boolValue($stored['review_enabled'] ?? self::DEFAULT_SETTINGS['review_enabled']),
            'review_levels' => max(0, min(5, (int) ($stored['review_levels'] ?? self::DEFAULT_SETTINGS['review_levels']))),
            'approval_enabled' => $this->boolValue($stored['approval_enabled'] ?? self::DEFAULT_SETTINGS['approval_enabled']),
            'approval_levels' => max(0, min(5, (int) ($stored['approval_levels'] ?? self::DEFAULT_SETTINGS['approval_levels']))),
        ];
    }

    private function legacyConfiguredStaffIds(string $stageType, int $level): array
    {
        if (! Schema::hasTable(self::LEGACY_RECIPIENTS_TABLE)) {
            return [];
        }

        $query = DB::table(self::LEGACY_RECIPIENTS_TABLE.' as r')
            ->where('r.stage_type', $stageType)
            ->where('r.level_no', $level)
            ->where('r.is_active', 1);

        if (Schema::hasTable('staff_general')) {
            $query->join('staff_general as sg', 'sg.staff_id', '=', 'r.staff_id')
                ->whereNull('sg.deleted_at')
                ->whereRaw("LOWER(COALESCE(sg.status, '')) = 'active'");
        }

        if (Schema::hasTable('system_users')) {
            $query->join('system_users as su', 'su.staff_id', '=', 'r.staff_id')
                ->where('su.is_active', 1);
        }

        return $query
            ->orderBy('r.sort_order')
            ->pluck('r.staff_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function legacyConfiguredRecipientsByStage(): array
    {
        if (! Schema::hasTable(self::LEGACY_RECIPIENTS_TABLE) || ! Schema::hasTable('staff_general')) {
            return [];
        }

        return DB::table(self::LEGACY_RECIPIENTS_TABLE.' as r')
            ->join('staff_general as sg', 'sg.staff_id', '=', 'r.staff_id')
            ->where('r.is_active', 1)
            ->whereNull('sg.deleted_at')
            ->whereRaw("LOWER(COALESCE(sg.status, '')) = 'active'")
            ->select([
                'r.stage_type',
                'r.level_no',
                'r.staff_id',
                'r.sort_order',
                'sg.full_name',
                'sg.name_code',
                Schema::hasColumn('staff_general', 'email')
                    ? DB::raw('sg.email as email')
                    : DB::raw('NULL as email'),
            ])
            ->orderBy('r.stage_type')
            ->orderBy('r.level_no')
            ->orderBy('r.sort_order')
            ->get()
            ->groupBy(fn ($row) => $row->stage_type.'.'.$row->level_no)
            ->map(fn ($rows) => $rows->map(fn ($row) => $this->formatStaff($row))->values()->all())
            ->all();
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function centralTablesAvailable(): bool
    {
        return Schema::hasTable('workflow_templates')
            && Schema::hasTable('workflow_template_steps')
            && Schema::hasTable('workflow_step_recipients');
    }

    private function ensureCentralTemplate(): int
    {
        $now = now();
        DB::table('workflow_templates')->updateOrInsert(
            ['process_key' => self::TEMPLATE_KEY],
            [
                'label' => 'Vendor Payment',
                'module_key' => 'vendor',
                'route_pattern' => '/vendor/payment-records/{id}',
                'enabled' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        $templateId = (int) DB::table('workflow_templates')
            ->where('process_key', self::TEMPLATE_KEY)
            ->value('id');

        if (! DB::table('workflow_template_steps')->where('template_id', $templateId)->exists()) {
            foreach ($this->stageDefinitions(self::DEFAULT_SETTINGS) as $stage) {
                $this->upsertCentralStep(
                    $templateId,
                    $stage['stage_type'],
                    (int) $stage['level_no'],
                    $this->stageLabel($stage['stage_type'], (int) $stage['level_no']),
                    $now,
                );
            }
        }

        return $templateId;
    }

    private function upsertCentralStep(
        int $templateId,
        string $stageType,
        int $level,
        string $label,
        mixed $now,
    ): int {
        $level = $stageType === self::STAGE_FINANCE ? 1 : $level;
        $sortOrder = match ($stageType) {
            self::STAGE_REVIEW => 10 + $level,
            self::STAGE_APPROVAL => 30 + $level,
            default => 60,
        };
        DB::table('workflow_template_steps')->updateOrInsert(
            [
                'template_id' => $templateId,
                'step_key' => $stageType,
                'level_no' => $level,
            ],
            [
                'template_id' => $templateId,
                'step_key' => $stageType,
                'level_no' => $level,
                'sort_order' => $sortOrder,
                'label' => $label,
                'action_label' => $stageType === self::STAGE_FINANCE
                    ? 'Mark Paid'
                    : ($stageType === self::STAGE_REVIEW ? 'Review' : 'Approve'),
                'fallback_roles' => json_encode($this->fallbackRolesForStage($stageType)),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return (int) DB::table('workflow_template_steps')
            ->where('template_id', $templateId)
            ->where('step_key', $stageType)
            ->where('level_no', $level)
            ->value('id');
    }

    private function emailBody(): SystemEmailBodyBuilder
    {
        return app(SystemEmailBodyBuilder::class);
    }

    private function emailUrls(): SystemEmailUrlBuilder
    {
        return app(SystemEmailUrlBuilder::class);
    }
}
