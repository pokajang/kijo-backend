<?php

namespace App\Services\Feedback;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FeedbackWorkflowService
{
    public const STATUS_PENDING = 'Pending';

    public const STATUS_PENDING_PUSH = 'Fixed Pending Pushed';

    public const STATUS_IN_PROGRESS = 'In Progress';

    public const STATUS_FIXED = 'Fixed Completed';

    public const STATUS_RESOLVED = 'Resolved';

    private const FINAL_NON_SLA_TRACKS = [
        'Next Upgrade',
        'Roadmap / Backlog',
        'Not Actionable',
        'Rejected',
    ];

    public function __construct(private FeedbackNotificationService $notificationService) {}

    public function actor(Request $request): array
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $name = trim((string) (
            $request->session()->get('name_code')
            ?: $request->session()->get('full_name')
            ?: ($staffId > 0 ? "Staff #{$staffId}" : 'System')
        ));

        return [
            'staff_id' => $staffId > 0 ? $staffId : null,
            'name' => $name,
            'is_admin' => in_array('System Admin', (array) $request->session()->get('roles', []), true),
        ];
    }

    public function recordReceived(Request $request, int $feedbackId, CarbonImmutable $reportedAt): ?int
    {
        $actor = $this->actor($request);
        $feedback = $this->findFeedback($feedbackId);
        $eventId = $this->appendHistory([
            'feedback_id' => $feedbackId,
            'event_type' => 'report_received',
            'actor_staff_id' => $actor['staff_id'],
            'actor_name' => $actor['name'],
            'status_to' => self::STATUS_PENDING,
            'resolution_track_to' => (string) ($feedback->resolution_track ?? 'Needs Triage'),
            'created_at' => $reportedAt,
        ]);

        $this->notificationService->reportReceived($feedback, $actor, $eventId);

        return $eventId;
    }

    public function update(Request $request, int $feedbackId, array $validated): array
    {
        $actor = $this->actor($request);
        $result = DB::transaction(function () use ($actor, $feedbackId, $validated): array {
            $row = DB::table('system_feedbacks')->where('id', $feedbackId)->lockForUpdate()->first();
            if (! $row) {
                throw new NotFoundHttpException('Feedback not found.');
            }

            $isOwner = (int) ($row->reported_by ?? 0) > 0
                && (int) $row->reported_by === (int) ($actor['staff_id'] ?? 0);
            if (! $actor['is_admin'] && ! $isOwner) {
                abort(403, 'Only the reporter or System Admin can edit this feedback.');
            }

            if (! $actor['is_admin']) {
                if (! array_key_exists('feedback', $validated)) {
                    throw ValidationException::withMessages([
                        'feedback' => 'Feedback text is required.',
                    ]);
                }
                $validated = ['feedback' => $validated['feedback']];
            }

            if (($validated['status'] ?? null) === self::STATUS_RESOLVED) {
                throw ValidationException::withMessages([
                    'status' => 'Only the reporter can confirm that a feedback issue is resolved.',
                ]);
            }

            $effectiveTrack = (string) ($validated['resolution_track'] ?? $row->resolution_track ?? 'Needs Triage');
            $effectiveRemarks = array_key_exists('remarks', $validated)
                ? trim((string) ($validated['remarks'] ?? ''))
                : trim((string) ($row->remarks ?? ''));
            if (
                array_key_exists('resolution_track', $validated)
                && in_array($effectiveTrack, self::FINAL_NON_SLA_TRACKS, true)
                && $effectiveRemarks === ''
            ) {
                throw ValidationException::withMessages([
                    'remarks' => 'Remarks are required for final non-SLA resolution tracks.',
                ]);
            }

            $updates = [];
            $changes = [];
            foreach (['feedback', 'status', 'resolution_track', 'action_date', 'remarks'] as $field) {
                if (! array_key_exists($field, $validated)) {
                    continue;
                }
                $newValue = $validated[$field];
                if (in_array($field, ['feedback', 'remarks'], true) && $newValue !== null) {
                    $newValue = trim((string) $newValue);
                }
                $oldValue = $row->{$field} ?? null;
                if ($this->valuesEqual($oldValue, $newValue)) {
                    continue;
                }
                $updates[$field] = $newValue;
                $changes[$field] = ['from' => $oldValue, 'to' => $newValue];
            }

            if ($updates === []) {
                return ['feedback' => $this->findFeedback($feedbackId), 'event_id' => null, 'event_type' => null];
            }

            $oldStatus = (string) ($row->status ?? self::STATUS_PENDING);
            $newStatus = (string) ($updates['status'] ?? $oldStatus);
            $eventType = $actor['is_admin'] ? 'developer_updated' : 'report_edited';

            if ($newStatus === self::STATUS_FIXED && (
                $oldStatus !== self::STATUS_FIXED
                || array_key_exists('action_date', $updates)
            )) {
                $fixedAt = ! empty($updates['action_date'])
                    ? CarbonImmutable::parse($updates['action_date'])->startOfDay()
                    : ($row->fixed_at ?? now());
                $updates['fixed_at'] = $fixedAt;
                if ($this->hasFeedbackColumn('verification_requested_at')) {
                    $updates['verification_requested_at'] = now();
                }
                if ($this->hasFeedbackColumn('resolved_at')) {
                    $updates['resolved_at'] = null;
                }
                if ($this->hasFeedbackColumn('resolved_by')) {
                    $updates['resolved_by'] = null;
                }
                $eventType = 'fix_ready';
            } elseif ($oldStatus === self::STATUS_FIXED && $newStatus !== self::STATUS_FIXED) {
                $updates['fixed_at'] = null;
                if ($this->hasFeedbackColumn('verification_requested_at')) {
                    $updates['verification_requested_at'] = null;
                }
            }

            DB::table('system_feedbacks')->where('id', $feedbackId)->update($updates);
            $eventId = $this->appendHistory([
                'feedback_id' => $feedbackId,
                'event_type' => $eventType,
                'actor_staff_id' => $actor['staff_id'],
                'actor_name' => $actor['name'],
                'message' => array_key_exists('remarks', $updates) ? $updates['remarks'] : null,
                'status_from' => array_key_exists('status', $updates) ? $oldStatus : null,
                'status_to' => array_key_exists('status', $updates) ? $newStatus : null,
                'resolution_track_from' => array_key_exists('resolution_track', $updates)
                    ? ($row->resolution_track ?? 'Needs Triage')
                    : null,
                'resolution_track_to' => $updates['resolution_track'] ?? null,
                'changes_json' => $changes,
                'created_at' => now(),
            ]);

            return [
                'feedback' => $this->findFeedback($feedbackId),
                'event_id' => $eventId,
                'event_type' => $eventType,
            ];
        });

        if ($result['event_id']) {
            $feedback = $result['feedback'];
            if ($actor['is_admin']) {
                $isFix = $result['event_type'] === 'fix_ready';
                $this->notificationService->developerActivity(
                    $feedback,
                    $actor,
                    $result['event_id'],
                    $isFix ? 'feedback.fix.ready' : 'feedback.developer.updated',
                    $isFix ? 'Please verify the reported fix' : 'Feedback status updated',
                    $isFix
                        ? 'The developer marked this feedback as fixed. Please verify the result.'
                        : 'The developer updated your feedback report.',
                    $isFix ? 'success' : 'info',
                );
            } else {
                $this->notificationService->reporterActivity(
                    $feedback,
                    $actor,
                    $result['event_id'],
                    'feedback.report.updated',
                    'Feedback report updated',
                    'The reporter updated the original feedback.',
                );
            }
        }

        return $result;
    }

    public function comment(Request $request, int $feedbackId, string $message): array
    {
        $actor = $this->actor($request);
        $result = DB::transaction(function () use ($actor, $feedbackId, $message): array {
            $row = DB::table('system_feedbacks')->where('id', $feedbackId)->lockForUpdate()->first();
            if (! $row) {
                throw new NotFoundHttpException('Feedback not found.');
            }
            $this->ensureParticipant($row, $actor);

            $eventId = $this->appendHistory([
                'feedback_id' => $feedbackId,
                'event_type' => 'comment_added',
                'actor_staff_id' => $actor['staff_id'],
                'actor_name' => $actor['name'],
                'message' => $message,
                'created_at' => now(),
            ]);

            return ['feedback' => $this->findFeedback($feedbackId), 'event_id' => $eventId];
        });

        if ($actor['is_admin']) {
            $this->notificationService->developerActivity(
                $result['feedback'],
                $actor,
                $result['event_id'],
                'feedback.developer.comment',
                'Developer replied to feedback',
                $message,
            );
        } else {
            $this->notificationService->reporterActivity(
                $result['feedback'],
                $actor,
                $result['event_id'],
                'feedback.reporter.comment',
                'Reporter commented on feedback',
                $message,
            );
        }

        return $this->detailPayload($request, $feedbackId);
    }

    public function verify(
        Request $request,
        int $feedbackId,
        string $decision,
        ?string $message,
    ): array {
        $actor = $this->actor($request);
        $result = DB::transaction(function () use ($actor, $feedbackId, $decision, $message): array {
            $row = DB::table('system_feedbacks')->where('id', $feedbackId)->lockForUpdate()->first();
            if (! $row) {
                throw new NotFoundHttpException('Feedback not found.');
            }

            $isOwner = (int) ($row->reported_by ?? 0) > 0
                && (int) $row->reported_by === (int) ($actor['staff_id'] ?? 0);
            if (! $isOwner) {
                abort(403, 'Only the reporter can verify this feedback fix.');
            }
            if ((string) $row->status !== self::STATUS_FIXED) {
                throw new ConflictHttpException('This feedback is not awaiting reporter verification.');
            }

            $confirm = $decision === 'confirm';
            $updates = ['status' => $confirm ? self::STATUS_RESOLVED : self::STATUS_IN_PROGRESS];
            if ($confirm) {
                if ($this->hasFeedbackColumn('resolved_at')) {
                    $updates['resolved_at'] = now();
                }
                if ($this->hasFeedbackColumn('resolved_by')) {
                    $updates['resolved_by'] = $actor['staff_id'];
                }
            } else {
                $updates['fixed_at'] = null;
                if ($this->hasFeedbackColumn('verification_requested_at')) {
                    $updates['verification_requested_at'] = null;
                }
                if ($this->hasFeedbackColumn('resolved_at')) {
                    $updates['resolved_at'] = null;
                }
                if ($this->hasFeedbackColumn('resolved_by')) {
                    $updates['resolved_by'] = null;
                }
            }

            DB::table('system_feedbacks')->where('id', $feedbackId)->update($updates);
            $eventType = $confirm ? 'reporter_resolved' : 'fix_rejected';
            $eventId = $this->appendHistory([
                'feedback_id' => $feedbackId,
                'event_type' => $eventType,
                'actor_staff_id' => $actor['staff_id'],
                'actor_name' => $actor['name'],
                'message' => $message,
                'status_from' => self::STATUS_FIXED,
                'status_to' => $updates['status'],
                'changes_json' => [
                    'status' => ['from' => self::STATUS_FIXED, 'to' => $updates['status']],
                ],
                'created_at' => now(),
            ]);

            return [
                'feedback' => $this->findFeedback($feedbackId),
                'event_id' => $eventId,
                'event_type' => $eventType,
            ];
        });

        $confirmed = $result['event_type'] === 'reporter_resolved';
        $this->notificationService->reporterActivity(
            $result['feedback'],
            $actor,
            $result['event_id'],
            $confirmed ? 'feedback.reporter.resolved' : 'feedback.fix.rejected',
            $confirmed ? 'Reporter confirmed the fix' : 'Reporter rejected the fix',
            $confirmed ? 'The reporter confirmed that the issue is resolved.' : (string) $message,
            $confirmed ? 'success' : 'danger',
        );

        return $this->detailPayload($request, $feedbackId);
    }

    public function detailPayload(Request $request, int $feedbackId): array
    {
        $feedback = $this->findFeedback($feedbackId);
        $actor = $this->actor($request);
        $isOwner = (int) ($feedback->reported_by_id ?? 0) > 0
            && (int) $feedback->reported_by_id === (int) ($actor['staff_id'] ?? 0);

        return [
            'feedback' => $feedback,
            'history' => $this->history($feedbackId),
            'permissions' => [
                'can_comment' => $actor['is_admin'] || $isOwner,
                'can_update_fix' => $actor['is_admin'] && (string) $feedback->status !== self::STATUS_RESOLVED,
                'can_verify' => $isOwner && (string) $feedback->status === self::STATUS_FIXED,
                'can_edit' => $actor['is_admin'] || $isOwner,
            ],
        ];
    }

    public function decoratePaginator(LengthAwarePaginator $paginator): void
    {
        $items = collect($paginator->items());
        $ids = $items->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($ids === [] || ! Schema::hasTable('system_feedback_history')) {
            return;
        }

        $rows = DB::table('system_feedback_history')
            ->whereIn('feedback_id', $ids)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'feedback_id', 'event_type', 'actor_name', 'created_at']);
        $counts = $rows->countBy('feedback_id');
        $latest = $rows->groupBy('feedback_id')->map->first();

        foreach ($items as $item) {
            $last = $latest->get((int) $item->id);
            $item->history_count = (int) ($counts->get((int) $item->id) ?? 0);
            $item->last_activity_at = $last->created_at ?? null;
            $item->last_event_type = $last->event_type ?? null;
            $item->last_actor_name = $last->actor_name ?? null;
        }
    }

    public function findFeedback(int $feedbackId): object
    {
        $select = [
            'f.id',
            'f.feedback',
            DB::raw('DATE(f.date_reported) as date_reported'),
            'f.status',
            Schema::hasColumn('system_feedbacks', 'resolution_track')
                ? 'f.resolution_track'
                : DB::raw("'Needs Triage' as resolution_track"),
            DB::raw('DATE(f.action_date) as action_date'),
            Schema::hasColumn('system_feedbacks', 'fixed_at')
                ? 'f.fixed_at'
                : DB::raw('NULL as fixed_at'),
            'f.remarks',
            DB::raw('f.reported_by as reported_by_id'),
            DB::raw("COALESCE(s.name_code, '') as reported_by"),
        ];
        foreach (['verification_requested_at', 'resolved_at', 'resolved_by'] as $column) {
            $select[] = $this->hasFeedbackColumn($column)
                ? "f.{$column}"
                : DB::raw("NULL as {$column}");
        }

        $feedback = DB::table('system_feedbacks as f')
            ->leftJoin('staff_general as s', 'f.reported_by', '=', 's.staff_id')
            ->select($select)
            ->where('f.id', $feedbackId)
            ->first();
        if (! $feedback) {
            throw new NotFoundHttpException('Feedback not found.');
        }

        if (trim((string) $feedback->reported_by) === '') {
            $feedback->reported_by = 'Staff #'.(int) $feedback->reported_by_id;
        }
        if (Schema::hasTable('system_feedback_history')) {
            $activityQuery = DB::table('system_feedback_history')->where('feedback_id', $feedbackId);
            $lastActivity = (clone $activityQuery)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['event_type', 'actor_name', 'created_at']);
            $feedback->history_count = (clone $activityQuery)->count();
            $feedback->last_activity_at = $lastActivity->created_at ?? null;
            $feedback->last_event_type = $lastActivity->event_type ?? null;
            $feedback->last_actor_name = $lastActivity->actor_name ?? null;
        } else {
            $feedback->history_count = 0;
            $feedback->last_activity_at = null;
            $feedback->last_event_type = null;
            $feedback->last_actor_name = null;
        }

        return $feedback;
    }

    private function history(int $feedbackId): array
    {
        if (! Schema::hasTable('system_feedback_history')) {
            return [];
        }

        return DB::table('system_feedback_history')
            ->where('feedback_id', $feedbackId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (object $event): array {
                return [
                    'id' => (int) $event->id,
                    'event_type' => (string) $event->event_type,
                    'actor_staff_id' => $event->actor_staff_id !== null ? (int) $event->actor_staff_id : null,
                    'actor_name' => $event->actor_name,
                    'message' => $event->message,
                    'status_from' => $event->status_from,
                    'status_to' => $event->status_to,
                    'resolution_track_from' => $event->resolution_track_from,
                    'resolution_track_to' => $event->resolution_track_to,
                    'changes' => $event->changes_json ? json_decode($event->changes_json, true) : [],
                    'created_at' => $event->created_at,
                ];
            })
            ->all();
    }

    private function appendHistory(array $event): ?int
    {
        if (! Schema::hasTable('system_feedback_history')) {
            return null;
        }

        $event['changes_json'] = isset($event['changes_json'])
            ? json_encode($event['changes_json'])
            : null;

        return (int) DB::table('system_feedback_history')->insertGetId(array_merge([
            'actor_staff_id' => null,
            'actor_name' => null,
            'message' => null,
            'status_from' => null,
            'status_to' => null,
            'resolution_track_from' => null,
            'resolution_track_to' => null,
            'changes_json' => null,
            'created_at' => now(),
        ], $event));
    }

    private function ensureParticipant(object $row, array $actor): void
    {
        $isOwner = (int) ($row->reported_by ?? 0) > 0
            && (int) $row->reported_by === (int) ($actor['staff_id'] ?? 0);
        if (! $actor['is_admin'] && ! $isOwner) {
            abort(403, 'Only the reporter or System Admin can comment on this feedback.');
        }
    }

    private function valuesEqual(mixed $oldValue, mixed $newValue): bool
    {
        if ($oldValue === null || $newValue === null) {
            return $oldValue === $newValue || ($oldValue === null && $newValue === '');
        }

        return trim((string) $oldValue) === trim((string) $newValue);
    }

    private function hasFeedbackColumn(string $column): bool
    {
        return Schema::hasColumn('system_feedbacks', $column);
    }
}
