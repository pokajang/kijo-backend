<?php

namespace App\Services\QuoteApprovals;

use App\Jobs\SendHtmlMailJob;
use App\Services\AppNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuoteApprovalNotificationService
{
    private const MODULE = 'crm.quote-approvals';

    private const ENTITY = 'quote_approval_request';

    public function pending(object $approval): void
    {
        $step = (string) $approval->required_step;
        $recipients = app(QuoteApprovalRecipientService::class)->notificationRecipients($step);
        $staffIds = collect($recipients)->pluck('staff_id')->map(fn ($id): int => (int) $id)->filter()->all();
        $route = $this->route($approval);

        app(AppNotificationService::class)->createForStaff($staffIds, [
            'actor_staff_id' => $approval->requested_by_id,
            'module_key' => self::MODULE,
            'entity_type' => self::ENTITY,
            'entity_id' => (int) $approval->id,
            'type' => 'quote.approval.pending',
            'title' => strtoupper($step).' quotation approval required',
            'message' => ($approval->quote_ref_no ?: 'Quotation').' is '.strtoupper($approval->zone).' and needs your decision.',
            'route' => $route,
            'severity' => $approval->zone === 'red' ? 'danger' : 'warning',
            'metadata' => ['service' => $approval->service, 'quote_id' => (int) $approval->quote_id],
        ]);

        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $name = (string) (($recipient['full_name'] ?? '') ?: ($recipient['name_code'] ?? '') ?: 'Approver');
            $this->send(
                $email,
                $name,
                'Quotation approval required: '.($approval->quote_ref_no ?: '#'.$approval->quote_id),
                '<p>A quotation is waiting for your approval.</p>'.$this->details($approval).$this->button($route, 'Review quotation'),
            );
        }
    }

    public function decided(object $approval): void
    {
        $this->resolve((int) $approval->id);

        $requesterId = (int) ($approval->requested_by_id ?? 0);
        if ($requesterId <= 0) {
            report(new \RuntimeException('Quotation approval result has no preparer recipient for request #'.$approval->id.'.'));

            return;
        }

        app(AppNotificationService::class)->createForStaff([$requesterId], [
            'actor_staff_id' => $approval->decided_by_id ?? null,
            'module_key' => self::MODULE,
            'entity_type' => self::ENTITY,
            'entity_id' => (int) $approval->id,
            'type' => 'quote.approval.'.strtolower((string) $approval->status),
            'title' => 'Quotation '.ucfirst((string) $approval->status),
            'message' => ($approval->quote_ref_no ?: 'Quotation').' was '.strtolower((string) $approval->status).'.',
            'route' => $this->route($approval, false),
            'severity' => $approval->status === 'approved' ? 'success' : 'danger',
        ]);

        $staff = DB::table('staff_general')->where('staff_id', $requesterId)->first();
        $email = trim((string) ($staff->email ?? ''));
        if ($email === '' && Schema::hasTable('system_users')) {
            $accountQuery = DB::table('system_users')
                ->where('staff_id', $requesterId)
                ->whereNotNull('email');
            if (Schema::hasColumn('system_users', 'is_active')) {
                $accountQuery->where('is_active', true);
            }
            $email = trim((string) $accountQuery->value('email'));
        }
        if ($email === '') {
            report(new \RuntimeException('Quotation approval result preparer has no email for request #'.$approval->id.'.'));

            return;
        }
        $status = strtolower((string) $approval->status);
        $this->send(
            $email,
            (string) ($staff->full_name ?? 'Requester'),
            'Quotation '.ucfirst($status).': '.($approval->quote_ref_no ?: '#'.$approval->quote_id),
            '<p>Your quotation approval request has been <strong>'.e($status).'</strong>.</p>'
                .$this->details($approval)
                .'<p><strong>Remarks:</strong> '.e((string) ($approval->decision_remarks ?: '-')).'</p>'
                .$this->button($this->route($approval, false), 'Open quotation records'),
        );
    }

    public function resolve(int $approvalId): void
    {
        app(AppNotificationService::class)->resolveActive(
            self::MODULE,
            self::ENTITY,
            $approvalId,
        );
    }

    private function send(string $email, string $name, string $subject, string $body): void
    {
        try {
            SendHtmlMailJob::dispatch(
                $email,
                $name,
                $subject,
                $body,
                [],
                'kijo@work.amiosh.com',
                'KIJO Workflow',
                ['headerLabel' => 'Quotation Approval', 'headerTitle' => $subject],
            )->afterCommit();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function details(object $approval): string
    {
        $margin = $approval->margin_percent === null ? 'Not available' : number_format((float) $approval->margin_percent, 2).'%';

        return '<ul>'
            .'<li>Reference: '.e((string) ($approval->quote_ref_no ?: '-')).'</li>'
            .'<li>Service: '.e(ucfirst((string) $approval->service)).'</li>'
            .'<li>Traffic light: '.e(strtoupper((string) $approval->zone)).'</li>'
            .'<li>Markup on estimated cost: '.e($margin).'</li>'
            .'</ul>';
    }

    private function route(object $approval, bool $approverScope = true): string
    {
        return '/crm/records?'.($approverScope ? 'approval_scope=mine&' : '').'service='.rawurlencode((string) $approval->service)
            .'&quoteId='.(int) $approval->quote_id.'&approvalId='.(int) $approval->id;
    }

    private function button(string $route, string $label): string
    {
        $base = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        return '<p><a href="'.e($base.$route).'">'.e($label).'</a></p>';
    }
}
