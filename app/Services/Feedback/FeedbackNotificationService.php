<?php

namespace App\Services\Feedback;

use App\Jobs\SendHtmlMailJob;
use App\Services\AppNotificationService;
use App\Services\Mail\SystemEmailBodyBuilder;
use App\Services\Mail\SystemEmailUrlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class FeedbackNotificationService
{
    private const MODULE_KEY = 'support.feedback';

    private const ENTITY_TYPE = 'system_feedback';

    public function __construct(
        private AppNotificationService $notifications,
        private SystemEmailBodyBuilder $emailBody,
        private SystemEmailUrlBuilder $emailUrls,
    ) {}

    public function reportReceived(object $feedback, array $actor, ?int $eventId): void
    {
        $actorId = (int) ($actor['staff_id'] ?? 0);
        $adminIds = array_values(array_filter(
            $this->activeSystemAdminIds(),
            static fn (int $staffId): bool => $staffId !== $actorId,
        ));

        $this->deliver(
            $adminIds,
            $feedback,
            $actor,
            $eventId,
            'feedback.report.received',
            'New feedback received',
            'A new feedback report requires triage.',
            'warning',
        );

        if ($actorId > 0) {
            $this->sendEmails(
                [$actorId],
                $feedback,
                'Feedback report received',
                'Your feedback has been recorded and sent for triage.',
                'Pending',
            );
        }
    }

    public function reporterActivity(
        object $feedback,
        array $actor,
        ?int $eventId,
        string $type,
        string $title,
        string $message,
        string $severity = 'info',
    ): void {
        $actorId = (int) ($actor['staff_id'] ?? 0);
        $recipientIds = array_values(array_filter(
            $this->activeSystemAdminIds(),
            static fn (int $staffId): bool => $staffId !== $actorId,
        ));
        $this->deliver(
            $recipientIds,
            $feedback,
            $actor,
            $eventId,
            $type,
            $title,
            $message,
            $severity,
        );
    }

    public function developerActivity(
        object $feedback,
        array $actor,
        ?int $eventId,
        string $type,
        string $title,
        string $message,
        string $severity = 'info',
    ): void {
        $reporterId = (int) ($feedback->reported_by_id ?? $feedback->reported_by ?? 0);
        $actorId = (int) ($actor['staff_id'] ?? 0);
        $recipientIds = $reporterId > 0 && $reporterId !== $actorId ? [$reporterId] : [];
        $this->deliver(
            $recipientIds,
            $feedback,
            $actor,
            $eventId,
            $type,
            $title,
            $message,
            $severity,
        );
    }

    private function deliver(
        array $recipientIds,
        object $feedback,
        array $actor,
        ?int $eventId,
        string $type,
        string $title,
        string $message,
        string $severity,
    ): void {
        $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds))));
        if ($recipientIds === []) {
            return;
        }

        $feedbackId = (int) $feedback->id;
        $route = $this->route($feedbackId);
        foreach ($recipientIds as $recipientId) {
            $this->notifications->createForStaffOnce([$recipientId], [
                'actor_staff_id' => $actor['staff_id'] ?? null,
                'module_key' => self::MODULE_KEY,
                'entity_type' => self::ENTITY_TYPE,
                'entity_id' => $feedbackId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'route' => $route,
                'severity' => $severity,
                'metadata' => ['event_id' => $eventId],
            ], sprintf(
                'feedback:%d:event:%s:recipient:%d',
                $feedbackId,
                $eventId ?? $type,
                $recipientId,
            ));
        }

        $this->sendEmails(
            $recipientIds,
            $feedback,
            $title,
            $message,
            (string) ($feedback->status ?? 'Pending'),
        );
    }

    private function sendEmails(
        array $recipientIds,
        object $feedback,
        string $subject,
        string $message,
        string $status,
    ): void {
        foreach ($this->recipients($recipientIds) as $recipient) {
            if (! filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            try {
                $body = $this->emailBody->render([
                    'intro' => $message,
                    'status' => ['label' => $status, 'tone' => $this->statusTone($status)],
                    'detailsHeading' => 'Feedback Details',
                    'details' => [
                        'Ticket' => '#'.(int) $feedback->id,
                        'Feedback' => (string) ($feedback->feedback ?? '-'),
                    ],
                    'actionUrl' => $this->emailUrls->frontendUrl($this->route((int) $feedback->id)),
                    'actionLabel' => 'Open feedback',
                    'signOff' => false,
                ]);
                SendHtmlMailJob::dispatch(
                    $recipient['email'],
                    $recipient['name'],
                    $subject.' — Feedback #'.(int) $feedback->id,
                    $body,
                    [],
                    null,
                    null,
                    $this->emailBody->presentation('System Feedback', $subject, 'Workflow update', $message),
                )->afterCommit();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function activeSystemAdminIds(): array
    {
        if (! Schema::hasTable('system_users')) {
            return [];
        }

        $query = DB::table('system_users')->whereNotNull('staff_id');
        if (Schema::hasColumn('system_users', 'is_active')) {
            $query->where('is_active', 1);
        }

        return $query
            ->get(['staff_id', 'role'])
            ->filter(function (object $row): bool {
                $decoded = is_string($row->role ?? null) ? json_decode($row->role, true) : null;
                $roles = is_array($decoded) ? $decoded : (array) ($row->role ?? []);

                return collect($roles)->contains(
                    static fn ($role): bool => strcasecmp(trim((string) $role), 'System Admin') === 0,
                );
            })
            ->pluck('staff_id')
            ->map(static fn ($staffId): int => (int) $staffId)
            ->unique()
            ->values()
            ->all();
    }

    private function recipients(array $staffIds): array
    {
        $recipients = [];
        foreach (array_values(array_unique(array_filter(array_map('intval', $staffIds)))) as $staffId) {
            $account = Schema::hasTable('system_users')
                ? DB::table('system_users')->where('staff_id', $staffId)->first()
                : null;
            if ($account && Schema::hasColumn('system_users', 'is_active') && ! (bool) $account->is_active) {
                continue;
            }

            $staff = Schema::hasTable('staff_general')
                ? DB::table('staff_general')->where('staff_id', $staffId)->first()
                : null;
            if ($staff && Schema::hasColumn('staff_general', 'deleted_at') && $staff->deleted_at !== null) {
                continue;
            }
            if (
                $staff
                && Schema::hasColumn('staff_general', 'status')
                && strcasecmp((string) $staff->status, 'Active') !== 0
            ) {
                continue;
            }

            $email = trim((string) ($account->email ?? $staff->email ?? ''));
            $name = trim((string) ($staff->full_name ?? $staff->name_code ?? '')) ?: 'KIJO User';
            $recipients[] = ['staff_id' => $staffId, 'email' => $email, 'name' => $name];
        }

        return $recipients;
    }

    private function route(int $feedbackId): string
    {
        return "/support/feedback/{$feedbackId}";
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'Fixed Completed', 'Resolved' => 'success',
            'Pending', 'Fixed Pending Pushed' => 'warning',
            default => 'info',
        };
    }
}
