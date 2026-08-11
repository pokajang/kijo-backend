<?php

namespace App\Services\Clients\FirstTouch;

use App\Jobs\SendHtmlMailJob;
use App\Services\AppNotificationService;
use Illuminate\Support\Facades\DB;

class ClientFirstTouchNotificationService
{
    public const ACTION_MODULE = 'client.first-touch';

    public const ACTIVITY_MODULE = 'client.first-touch.activity';

    public const CONFLICT_ENTITY = 'client_first_touch_conflict';

    public const CLARIFICATION_ENTITY = 'client_first_touch_clarification';

    private const REVIEW_TYPES = [
        'first_touch.conflict.opened',
        'first_touch.conflict.updated',
        'first_touch.clarification.responded',
    ];

    public function __construct(
        private AppNotificationService $notifications,
        private ClientFirstTouchRecipientService $recipients,
    ) {}

    public function conflictNeedsReview(int $conflictId, int $actorStaffId, string $eventKey, bool $opened): void
    {
        $context = $this->context($conflictId);
        if (! $context) {
            return;
        }

        $reviewers = $this->recipients->reviewersForConflict($conflictId);
        if ($reviewers === []) {
            report(new \RuntimeException("First-touch conflict #{$conflictId} has no eligible independent reviewer."));

            return;
        }

        $this->notifications->resolveOutstanding(
            self::ACTION_MODULE,
            self::CONFLICT_ENTITY,
            $conflictId,
            self::REVIEW_TYPES,
        );
        $type = $opened ? 'first_touch.conflict.opened' : 'first_touch.conflict.updated';
        $title = $opened ? 'First-touch conflict requires review' : 'First-touch conflict evidence updated';
        $route = $this->reviewRoute($context);
        $staffIds = collect($reviewers)->pluck('staff_id')->map(fn ($id): int => (int) $id)->all();

        $this->notifications->createForStaffOnce($staffIds, [
            'actor_staff_id' => $actorStaffId,
            'module_key' => self::ACTION_MODULE,
            'entity_type' => self::CONFLICT_ENTITY,
            'entity_id' => $conflictId,
            'type' => $type,
            'title' => $title,
            'message' => $context->company_name.' has competing first-touch evidence awaiting an independent decision.',
            'route' => $route,
            'severity' => 'warning',
            'metadata' => ['client_id' => (int) $context->client_id],
        ], "first_touch:conflict:{$conflictId}:{$eventKey}");

        $subject = ($opened ? '[Action required] First-touch conflict — ' : '[Updated] First-touch conflict — ')
            .$context->company_name;
        $this->sendToRecipients(
            $reviewers,
            $subject,
            '<p>Competing first-touch evidence is waiting for an independent review.</p>'
                .$this->details($context)
                .$this->button($route, 'Review first-touch conflict'),
        );
    }

    public function clarificationRequested(int $conflictId, int $clarificationId, int $actorStaffId): void
    {
        $context = $this->context($conflictId);
        $clarification = DB::table('client_first_touch_clarifications')->where('id', $clarificationId)->first();
        if (! $context || ! $clarification) {
            return;
        }

        $recipients = $this->recipients->recipientsForStaffIds([(int) $clarification->requested_from_staff_id]);
        $route = $this->clarificationRoute($context, $clarificationId);
        $this->notifications->createForStaffOnce([(int) $clarification->requested_from_staff_id], [
            'actor_staff_id' => $actorStaffId,
            'module_key' => self::ACTION_MODULE,
            'entity_type' => self::CLARIFICATION_ENTITY,
            'entity_id' => $clarificationId,
            'type' => 'first_touch.clarification.requested',
            'title' => 'First-touch clarification requested',
            'message' => 'Additional information is required for '.$context->company_name.'.',
            'route' => $route,
            'severity' => 'warning',
            'metadata' => ['client_id' => (int) $context->client_id, 'conflict_id' => $conflictId],
        ], "first_touch:clarification:{$clarificationId}:requested");

        $this->sendToRecipients(
            $recipients,
            '[Action required] Clarification requested — '.$context->company_name,
            '<p>An independent reviewer requested clarification about first-touch evidence you submitted.</p>'
                .'<p><strong>Request:</strong> '.e((string) $clarification->request_note).'</p>'
                .$this->details($context)
                .$this->button($route, 'Provide clarification'),
        );
    }

    public function clarificationResponded(int $conflictId, int $clarificationId, int $actorStaffId): void
    {
        $context = $this->context($conflictId);
        if (! $context) {
            return;
        }

        $this->notifications->resolveOutstanding(
            self::ACTION_MODULE,
            self::CLARIFICATION_ENTITY,
            $clarificationId,
        );
        $reviewers = $this->recipients->reviewersForConflict($conflictId);
        $this->notifications->resolveOutstanding(
            self::ACTION_MODULE,
            self::CONFLICT_ENTITY,
            $conflictId,
            self::REVIEW_TYPES,
        );
        $route = $this->reviewRoute($context);
        $staffIds = collect($reviewers)->pluck('staff_id')->map(fn ($id): int => (int) $id)->all();
        $this->notifications->createForStaffOnce($staffIds, [
            'actor_staff_id' => $actorStaffId,
            'module_key' => self::ACTION_MODULE,
            'entity_type' => self::CONFLICT_ENTITY,
            'entity_id' => $conflictId,
            'type' => 'first_touch.clarification.responded',
            'title' => 'First-touch clarification received',
            'message' => $context->company_name.' is ready for review again.',
            'route' => $route,
            'severity' => 'warning',
            'metadata' => ['client_id' => (int) $context->client_id, 'clarification_id' => $clarificationId],
        ], "first_touch:clarification:{$clarificationId}:responded");

        $this->sendToRecipients(
            $reviewers,
            '[Updated] First-touch clarification received — '.$context->company_name,
            '<p>The requested clarification has been provided and the conflict is ready for review.</p>'
                .$this->details($context)
                .$this->button($route, 'Continue conflict review'),
        );
    }

    public function conflictResolved(int $conflictId, int $actorStaffId, string $decision): void
    {
        $context = $this->context($conflictId);
        if (! $context) {
            return;
        }

        $this->notifications->resolveOutstanding(self::ACTION_MODULE, self::CONFLICT_ENTITY, $conflictId);
        $clarificationIds = DB::table('client_first_touch_clarifications')
            ->where('conflict_id', $conflictId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        foreach ($clarificationIds as $clarificationId) {
            $this->notifications->resolveOutstanding(
                self::ACTION_MODULE,
                self::CLARIFICATION_ENTITY,
                $clarificationId,
            );
        }

        $participants = $this->recipients->participantsForConflict($conflictId);
        $staffIds = collect($participants)->pluck('staff_id')->map(fn ($id): int => (int) $id)->all();
        $route = '/client/first-touch/'.(int) $context->client_id.'?tab=claims';
        $decisionLabel = str_replace('_', ' ', $decision);
        $this->notifications->createForStaffOnce($staffIds, [
            'actor_staff_id' => $actorStaffId,
            'module_key' => self::ACTIVITY_MODULE,
            'entity_type' => self::CONFLICT_ENTITY,
            'entity_id' => $conflictId,
            'type' => 'first_touch.conflict.resolved',
            'title' => 'First-touch conflict resolved',
            'message' => $context->company_name.' was resolved: '.$decisionLabel.'.',
            'route' => $route,
            'severity' => 'success',
            'metadata' => ['client_id' => (int) $context->client_id, 'decision' => $decision],
        ], "first_touch:conflict:{$conflictId}:resolved");

        $this->sendToRecipients(
            $participants,
            '[Resolved] First-touch conflict — '.$context->company_name,
            '<p>The first-touch conflict has been resolved.</p>'
                .'<p><strong>Decision:</strong> '.e(ucfirst($decisionLabel)).'</p>'
                .$this->details($context)
                .$this->button($route, 'View claims and history'),
        );
    }

    private function context(int $conflictId): ?object
    {
        $context = DB::table('client_first_touch_conflicts as conflict')
            ->join('client_company as client', 'client.company_id', '=', 'conflict.client_id')
            ->where('conflict.id', $conflictId)
            ->select([
                'conflict.id',
                'conflict.client_id',
                'conflict.status',
                'conflict.current_claim_id',
                'conflict.created_at',
                'client.company_name',
            ])
            ->first();
        if (! $context) {
            return null;
        }

        $claims = DB::table('client_first_touch_claims')
            ->where('client_id', $context->client_id)
            ->where(function ($query) use ($context): void {
                $query->where('id', $context->current_claim_id)
                    ->orWhere('submitted_at', '>=', $context->created_at);
            })
            ->orderBy('occurred_on')
            ->orderBy('occurred_time')
            ->get();
        $claimIds = $claims->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $disputeIds = DB::table('client_first_touch_disputes')
            ->where('client_id', $context->client_id)
            ->where('submitted_at', '>=', $context->created_at)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $clarificationIds = DB::table('client_first_touch_clarifications')
            ->where('conflict_id', $context->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $context->claims = $claims->all();
        $context->claim_count = $claims->count();
        $context->evidence_count = ($claimIds === [] && $disputeIds === [] && $clarificationIds === [])
            ? 0
            : DB::table('client_first_touch_evidence')
                ->where(function ($query) use ($claimIds, $disputeIds, $clarificationIds): void {
                    if ($claimIds !== []) {
                        $query->orWhere(fn ($owner) => $owner->where('owner_type', 'claim')->whereIn('owner_id', $claimIds));
                    }
                    if ($disputeIds !== []) {
                        $query->orWhere(fn ($owner) => $owner->where('owner_type', 'dispute')->whereIn('owner_id', $disputeIds));
                    }
                    if ($clarificationIds !== []) {
                        $query->orWhere(fn ($owner) => $owner->where('owner_type', 'clarification')->whereIn('owner_id', $clarificationIds));
                    }
                })
                ->count();

        return $context;
    }

    private function reviewRoute(object $context): string
    {
        return '/client/first-touch/'.(int) $context->client_id
            .'?tab=claims&reviewConflict='.(int) $context->id;
    }

    private function clarificationRoute(object $context, int $clarificationId): string
    {
        return '/client/first-touch/'.(int) $context->client_id
            .'?tab=claims&clarification='.$clarificationId;
    }

    private function details(object $context): string
    {
        $claims = collect($context->claims ?? [])->map(function (object $claim): string {
            $occurred = substr((string) $claim->occurred_on, 0, 10)
                .($claim->occurred_time ? ' '.substr((string) $claim->occurred_time, 0, 5) : '');
            $handledBy = $claim->amiosh_contact_name ?: $claim->referrer_name ?: '-';

            return '<li>'.e($occurred).' · '.e((string) $claim->source_value)
                .' · submitted by '.e((string) $claim->submitted_by_name)
                .' · handled/referred through '.e((string) $handledBy).'</li>';
        })->implode('');

        return '<ul>'
            .'<li>Client: '.e((string) $context->company_name).'</li>'
            .'<li>Claims recorded: '.(int) $context->claim_count.'</li>'
            .'<li>Evidence images: '.(int) $context->evidence_count.'</li>'
            .'</ul>'
            .($claims !== '' ? '<p><strong>Claims in this review</strong></p><ul>'.$claims.'</ul>' : '');
    }

    private function button(string $route, string $label): string
    {
        $base = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        return '<p><a href="'.e($base.$route).'">'.e($label).'</a></p>';
    }

    private function sendToRecipients(array $recipients, string $subject, string $body): void
    {
        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                report(new \RuntimeException(
                    'First-touch workflow recipient has no valid email: staff #'.(int) ($recipient['staff_id'] ?? 0),
                ));

                continue;
            }

            try {
                SendHtmlMailJob::dispatch(
                    $email,
                    (string) ($recipient['full_name'] ?? 'Recipient'),
                    $subject,
                    $body,
                    [],
                    'kijo@work.amiosh.com',
                    'KIJO Workflow',
                    ['headerLabel' => 'First Touch', 'headerTitle' => $subject],
                )->afterCommit();
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }
}
