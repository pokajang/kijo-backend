<?php

namespace App\Console\Commands;

use App\Jobs\SendHtmlMailJob;
use App\Services\AppNotificationService;
use App\Services\Mail\SystemEmailUrlBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SendSalaryWorkflowDigest extends Command
{
    protected $signature = 'salary:send-workflow-digest
        {--dry-run : Report eligible digest deliveries without sending email}
        {--limit= : Maximum number of workflow records to inspect}';

    protected $description = 'Send one pending Salary/Other Claim workflow digest per responsible recipient';

    private const SALARY_SUBJECT_TYPE = 'salary_application';
    private const OTHER_CLAIM_SUBJECT_TYPE = 'other_claim_application';
    private const FINANCIAL_ROUTES = [
        self::SALARY_SUBJECT_TYPE => '/financial/salary-records',
        self::OTHER_CLAIM_SUBJECT_TYPE => '/financial/other-claim-records',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->resolveLimit();

        if (! $dryRun && ! $this->mailSenderIsConfigured()) {
            $this->error('System email sender is not configured. Check MAIL_MAILER and MAIL_FROM_ADDRESS.');
            return self::FAILURE;
        }

        $rows = $this->candidateRows($limit);
        if ($rows->isEmpty()) {
            $this->info('Salary/claim workflow digest: no pending workflow actions found.');
            return self::SUCCESS;
        }

        [$deliveries, $skipped] = $this->buildDeliveries($rows);
        if (empty($deliveries)) {
            $this->info("Salary/claim workflow digest: no deliverable recipients found. Skipped={$skipped}");
            return self::SUCCESS;
        }

        if ($dryRun) {
            $items = 0;
            foreach ($deliveries as $delivery) {
                $count = count($delivery['items']);
                $items += $count;
                $this->line("[dry-run] {$delivery['email']} would receive {$count} pending salary/claim workflow item(s).");
            }
            $this->info("Salary/claim workflow digest finished. Mode=dry-run, Deliveries=".count($deliveries).", Items={$items}, Skipped={$skipped}");
            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        foreach ($deliveries as $delivery) {
            try {
                $this->sendDigestMail($delivery);
                $sent++;
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                $this->warn("Failed salary/claim workflow digest for {$delivery['email']}: {$e->getMessage()}");
            }
        }

        $this->info("Salary/claim workflow digest finished. Mode=sent, Sent={$sent}, Failed={$failed}, Skipped={$skipped}");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function candidateRows(?int $limit): Collection
    {
        foreach ([
            'workflow_instances',
            'workflow_templates',
            'workflow_template_steps',
            'workflow_step_recipients',
            'hr_salary_applications',
            'hr_other_claim_applications',
            'staff_general',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("Salary/claim workflow digest skipped: {$table} table is missing.");
                return collect();
            }
        }

        $query = DB::table('workflow_instances as instance')
            ->join('workflow_templates as template', 'template.id', '=', 'instance.template_id')
            ->join('workflow_template_steps as step', 'step.id', '=', 'instance.current_step_id')
            ->leftJoin('hr_salary_applications as salary', function ($join): void {
                $join->on('salary.id', '=', 'instance.subject_id')
                    ->where('instance.subject_type', self::SALARY_SUBJECT_TYPE);
            })
            ->leftJoin('hr_other_claim_applications as other_claim', function ($join): void {
                $join->on('other_claim.id', '=', 'instance.subject_id')
                    ->where('instance.subject_type', self::OTHER_CLAIM_SUBJECT_TYPE);
            })
            ->leftJoin('staff_general as salary_staff', 'salary_staff.staff_id', '=', 'salary.staff_id')
            ->leftJoin('staff_general as other_staff', 'other_staff.staff_id', '=', 'other_claim.staff_id')
            ->where('template.process_key', 'salary-application')
            ->whereIn('instance.subject_type', [self::SALARY_SUBJECT_TYPE, self::OTHER_CLAIM_SUBJECT_TYPE])
            ->where(function ($query): void {
                $query
                    ->where(function ($checkQuery): void {
                        $checkQuery
                            ->whereIn('instance.status', ['Submitted', 'Prepared'])
                            ->where('step.step_key', 'check');
                    })
                    ->orWhere(function ($approveQuery): void {
                        $approveQuery
                            ->where('instance.status', 'Checked')
                            ->where('step.step_key', 'approve');
                    });
            })
            ->where('step.active', 1)
            ->where(function ($query): void {
                $query
                    ->where(function ($salaryQuery): void {
                        $salaryQuery
                            ->where('instance.subject_type', self::SALARY_SUBJECT_TYPE)
                            ->whereNotNull('salary.id')
                            ->where('salary.status', '<>', 'Cancelled');
                    })
                    ->orWhere(function ($otherClaimQuery): void {
                        $otherClaimQuery
                            ->where('instance.subject_type', self::OTHER_CLAIM_SUBJECT_TYPE)
                            ->whereNotNull('other_claim.id')
                            ->where('other_claim.status', '<>', 'Cancelled');
                    });
            })
            ->select([
                'instance.id as instance_id',
                'instance.subject_type',
                'instance.subject_id',
                'instance.status',
                'instance.maker_staff_id',
                'instance.submitted_at',
                'step.id as step_id',
                'step.step_key',
                'step.fallback_roles',
                'salary.staff_id as salary_staff_id',
                'salary.salary_month_label',
                'salary.salary_month',
                'salary.payable_salary',
                'salary.claims_total as salary_claims_total',
                'salary.checked_by as salary_checked_by',
                'salary_staff.full_name as salary_staff_name',
                'salary_staff.name_code as salary_staff_code',
                'other_claim.staff_id as other_staff_id',
                'other_claim.claim_month_label',
                'other_claim.claim_month',
                'other_claim.claims_total as other_claims_total',
                'other_claim.checked_by as other_checked_by',
                'other_staff.full_name as other_staff_name',
                'other_staff.name_code as other_staff_code',
            ])
            ->orderBy('instance.submitted_at')
            ->orderBy('instance.id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    private function buildDeliveries(Collection $rows): array
    {
        $deliveries = [];
        $skipped = 0;
        $recipientCache = [];

        foreach ($rows as $row) {
            $recipientIds = $this->recipientIdsForRow($row);
            if (empty($recipientIds)) {
                $skipped++;
                continue;
            }

            $recipientMap = $this->recipientMap($recipientIds, $recipientCache);
            if (empty($recipientMap)) {
                $skipped++;
                continue;
            }

            $item = $this->digestItem($row);
            foreach ($recipientMap as $staffId => $recipient) {
                $deliveryKey = strtolower($recipient['email']);
                if (! isset($deliveries[$deliveryKey])) {
                    $deliveries[$deliveryKey] = [
                        'staff_id' => $staffId,
                        'name' => $recipient['name'],
                        'email' => $recipient['email'],
                        'items' => [],
                    ];
                }

                $deliveries[$deliveryKey]['items'][$item['dedupe_key']] = $item;
            }
        }

        foreach ($deliveries as &$delivery) {
            $delivery['items'] = array_values($delivery['items']);
        }
        unset($delivery);

        return [$deliveries, $skipped];
    }

    private function recipientIdsForRow(object $row): array
    {
        $recipientIds = DB::table('workflow_step_recipients')
            ->where('step_id', $row->step_id)
            ->where('active', 1)
            ->pluck('staff_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($recipientIds)) {
            $recipientIds = app(AppNotificationService::class)->staffIdsForRoles(
                $this->decodeJsonArray($row->fallback_roles),
            );
        }

        $exclude = array_filter([
            (int) ($row->maker_staff_id ?? 0),
            $this->applicantId($row),
            (string) $row->step_key === 'approve' ? $this->checkerId($row) : 0,
        ]);

        return array_values(array_diff(array_unique(array_filter($recipientIds)), $exclude));
    }

    private function recipientMap(array $staffIds, array &$cache): array
    {
        $missing = array_values(array_diff($staffIds, array_keys($cache)));
        if ($missing !== []) {
            $rows = DB::table('staff_general')
                ->whereIn('staff_id', $missing)
                ->get(['staff_id', 'full_name', 'name_code', 'email']);

            foreach ($rows as $row) {
                $email = trim((string) ($row->email ?? ''));
                $cache[(int) $row->staff_id] = $this->isValidEmail($email)
                    ? [
                        'name' => $this->staffLabel((string) ($row->full_name ?? ''), (string) ($row->name_code ?? ''), (int) $row->staff_id),
                        'email' => $email,
                    ]
                    : null;
            }

            foreach ($missing as $staffId) {
                $cache[$staffId] ??= null;
            }
        }

        return collect($staffIds)
            ->mapWithKeys(fn (int $staffId): array => $cache[$staffId] ? [$staffId => $cache[$staffId]] : [])
            ->all();
    }

    private function digestItem(object $row): array
    {
        $subjectType = (string) $row->subject_type;
        $isOtherClaim = $subjectType === self::OTHER_CLAIM_SUBJECT_TYPE;
        $stepKey = (string) $row->step_key;

        return [
            'dedupe_key' => $subjectType.'-'.$row->subject_id.'-'.$stepKey,
            'section' => $this->sectionKey($subjectType, $stepKey),
            'module' => $isOtherClaim ? 'Other Claim' : 'Salary',
            'action' => $stepKey === 'approve' ? 'Approve' : 'Check',
            'applicant' => $isOtherClaim
                ? $this->staffLabel((string) ($row->other_staff_name ?? ''), (string) ($row->other_staff_code ?? ''), (int) ($row->other_staff_id ?? 0))
                : $this->staffLabel((string) ($row->salary_staff_name ?? ''), (string) ($row->salary_staff_code ?? ''), (int) ($row->salary_staff_id ?? 0)),
            'period' => $isOtherClaim
                ? $this->formatDateTime($row->submitted_at)
                : (string) ($row->salary_month_label ?: $row->salary_month ?: '-'),
            'amount' => 'RM '.number_format((float) ($isOtherClaim ? $row->other_claims_total : $row->payable_salary), 2),
            'status' => $this->displayStatus((string) $row->status),
            'status_action' => $this->displayStatus((string) $row->status).' / '.($stepKey === 'approve' ? 'Approve' : 'Check'),
            'submitted_at' => $this->formatDateTime($row->submitted_at),
            'route' => self::FINANCIAL_ROUTES[$subjectType],
        ];
    }

    private function sendDigestMail(array $delivery): void
    {
        $count = count($delivery['items']);
        $subject = "KIJO Salary/Claims Pending Actions: {$count}";
        $body = $this->digestBody($delivery);

        SendHtmlMailJob::dispatchSync(
            $delivery['email'],
            $delivery['name'],
            $subject,
            $body,
            [],
            null,
            null,
            [
                'headerLabel' => 'Salary & Claims',
                'headerTitle' => 'Pending Workflow Actions',
                'headerSubtitle' => "{$count} item".($count === 1 ? '' : 's').' need your action',
                'preheader' => 'Salary and claim workflow items are waiting for your review in KIJO.',
            ],
        );
    }

    private function digestBody(array $delivery): string
    {
        $items = collect($delivery['items']);
        $sections = [
            'salary-check' => 'Salary pending check',
            'salary-approve' => 'Salary pending approval',
            'other-check' => 'Other Claim pending check',
            'other-approve' => 'Other Claim pending approval',
        ];

        $html = '<p style="margin:0 0 14px;">Hi '.e($this->displayName($delivery['name'], 'there')).',</p>';
        $html .= '<p style="margin:0 0 14px;">The following Salary/Claims workflow items are pending your action in KIJO.</p>';

        foreach ($sections as $sectionKey => $heading) {
            $sectionItems = $items->where('section', $sectionKey)->values();
            if ($sectionItems->isEmpty()) {
                continue;
            }

            $html .= '<h3 style="margin:20px 0 8px;font-size:15px;color:#10233f;">'.e($heading).'</h3>';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #d8dee6;border-radius:8px;overflow:hidden;margin:0 0 14px;">';
            $html .= '<thead><tr style="background:#f3f5f8;">';
            foreach (['Applicant', 'Period / Claim Date', 'Amount', 'Status / Action', 'Submitted'] as $label) {
                $html .= '<th align="left" style="padding:9px 10px;border-bottom:1px solid #d8dee6;font-size:12px;color:#495057;">'.e($label).'</th>';
            }
            $html .= '</tr></thead><tbody>';

            foreach ($sectionItems as $item) {
                $html .= '<tr>';
                foreach (['applicant', 'period', 'amount', 'status_action', 'submitted_at'] as $key) {
                    $html .= '<td style="padding:9px 10px;border-bottom:1px solid #edf0f4;font-size:13px;color:#10233f;">'.e((string) $item[$key]).'</td>';
                }
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        }

        $routeLabels = collect($delivery['items'])
            ->pluck('route')
            ->unique()
            ->values()
            ->map(fn (string $route): string => '<a href="'.e(app(SystemEmailUrlBuilder::class)->frontendUrl($route)).'" style="display:inline-block;margin:0 8px 8px 0;padding:10px 14px;background:#4f46e5;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">'.e($route === '/financial/other-claim-records' ? 'Open Other Claim Records' : 'Open Salary Records').'</a>')
            ->implode('');

        $html .= '<div style="margin:18px 0 8px;">'.$routeLabels.'</div>';
        $html .= '<p style="margin:12px 0 0;color:#6c757d;font-size:12px;">This digest is sent only while workflow items are pending your action.</p>';

        return $html;
    }

    private function sectionKey(string $subjectType, string $stepKey): string
    {
        $prefix = $subjectType === self::OTHER_CLAIM_SUBJECT_TYPE ? 'other' : 'salary';
        return $prefix.'-'.($stepKey === 'approve' ? 'approve' : 'check');
    }

    private function applicantId(object $row): int
    {
        return (string) $row->subject_type === self::OTHER_CLAIM_SUBJECT_TYPE
            ? (int) ($row->other_staff_id ?? 0)
            : (int) ($row->salary_staff_id ?? 0);
    }

    private function checkerId(object $row): int
    {
        return (string) $row->subject_type === self::OTHER_CLAIM_SUBJECT_TYPE
            ? (int) ($row->other_checked_by ?? 0)
            : (int) ($row->salary_checked_by ?? 0);
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

    private function displayStatus(string $status): string
    {
        return match ($status) {
            'Prepared' => 'Submitted',
            'Paid' => 'Approved',
            default => $status !== '' ? $status : '-',
        };
    }

    private function formatDateTime(mixed $value): string
    {
        if (! $value) {
            return '-';
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('d M Y H:i');
        } catch (\Throwable) {
            return substr((string) $value, 0, 16);
        }
    }

    private function resolveLimit(): ?int
    {
        $raw = $this->option('limit');
        if ($raw === null || $raw === '') {
            return null;
        }

        $limit = (int) $raw;
        return $limit > 0 ? $limit : null;
    }

    private function mailSenderIsConfigured(): bool
    {
        $mailer = (string) config('mail.default', '');
        $fromAddress = trim((string) config('mail.from.address', ''));

        return $mailer !== ''
            && ! in_array($mailer, ['array', 'log'], true)
            && $fromAddress !== ''
            && ! str_contains(strtolower($fromAddress), 'example.com');
    }

    private function isValidEmail(string $email): bool
    {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function staffLabel(string $name, string $code, ?int $fallbackId = null): string
    {
        $name = trim($name);
        $code = trim($code);
        if ($name !== '' && $code !== '') {
            return "{$name} ({$code})";
        }
        if ($name !== '') {
            return $name;
        }
        if ($code !== '') {
            return $code;
        }

        return $fallbackId ? "Staff #{$fallbackId}" : 'Staff';
    }

    private function displayName(string $name, string $fallback): string
    {
        $name = trim($name);
        return $name !== '' ? $name : $fallback;
    }
}
