<?php

namespace App\Services\Clients\FirstTouch;

use App\Support\AppFilePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientFirstTouchMutationService
{
    private const OPEN_CONFLICT_STATUSES = ['open', 'clarification_requested'];

    public function __construct(
        private ClientFirstTouchQueryService $query,
        private ClientFirstTouchNotificationService $notifications,
        private ClientFirstTouchRecipientService $recipients,
        private ClientFirstTouchAuthorizationService $authorization,
    ) {}

    public function storeClaim(Request $request, int $clientId): array
    {
        $this->ensureClient($clientId);
        $this->validateLinkedInquiry($request, $clientId);
        $actor = $this->actor($request);
        $storedPaths = [];
        $notification = null;

        try {
            DB::transaction(function () use ($request, $clientId, $actor, &$storedPaths, &$notification): void {
                DB::table('client_company')
                    ->where('company_id', $clientId)
                    ->lockForUpdate()
                    ->first();
                $current = DB::table('client_first_touch_claims')
                    ->where('client_id', $clientId)
                    ->where('is_current', true)
                    ->lockForUpdate()
                    ->first();

                if ($current) {
                    $this->validateCompetingChronology($request, $current);
                }

                $now = now();
                $claimId = DB::table('client_first_touch_claims')->insertGetId([
                    ...$this->claimValues($request),
                    'client_id' => $clientId,
                    'status' => $current ? 'competing' : 'current',
                    'is_current' => ! $current,
                    'version' => 1,
                    'submitted_by_staff_id' => $actor['staff_id'],
                    'submitted_by_name' => $actor['name'],
                    'submitted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->storeEvidence($request->file('evidence', []), 'claim', $claimId, $clientId, $actor, $request, $storedPaths);

                if ($current) {
                    DB::table('client_first_touch_claims')->where('id', $current->id)->update([
                        'status' => 'contested',
                        'updated_at' => $now,
                    ]);
                    $conflict = $this->openConflict($clientId, (int) $current->id, $now);
                    $notification = [
                        'conflict_id' => $conflict['id'],
                        'event_key' => 'claim:'.$claimId,
                        'opened' => $conflict['created'],
                    ];
                }
            }, 3);
        } catch (\Throwable $error) {
            $this->deletePaths($storedPaths);
            throw $error;
        }

        if ($notification) {
            $this->notify(fn () => $this->notifications->conflictNeedsReview(
                $notification['conflict_id'],
                $actor['staff_id'],
                $notification['event_key'],
                $notification['opened'],
            ));
        }

        return $this->query->show($clientId, $request);
    }

    public function updateClaim(Request $request, int $clientId, int $claimId): array
    {
        $this->ensureClient($clientId);
        $this->validateLinkedInquiry($request, $clientId, $claimId);
        $actor = $this->actor($request);
        $storedPaths = [];
        $removedPaths = [];

        try {
            DB::transaction(function () use ($request, $clientId, $claimId, $actor, &$storedPaths, &$removedPaths): void {
                $claim = DB::table('client_first_touch_claims')
                    ->where('id', $claimId)
                    ->where('client_id', $clientId)
                    ->lockForUpdate()
                    ->first();

                if (! $claim || ! $claim->is_current) {
                    throw ValidationException::withMessages(['claim' => 'Only the current first-touch claim can be edited.']);
                }
                if (! $this->authorization->canEditClaim($request, (int) $claim->submitted_by_staff_id)) {
                    abort(403, 'Only the original submitter, a manager, or a system administrator can edit this first-touch claim.');
                }
                if ((int) $claim->version !== (int) $request->input('expected_version')) {
                    abort(409, 'This first-touch claim changed after you opened it. Reload the client and try again.');
                }
                if ($this->hasOpenConflict($clientId)) {
                    abort(409, 'Evidence cannot be edited while a first-touch conflict is under review.');
                }

                $existingEvidence = DB::table('client_first_touch_evidence')
                    ->where('owner_type', 'claim')
                    ->where('owner_id', $claimId)
                    ->lockForUpdate()
                    ->get();
                $keepIds = collect($request->input('keep_evidence_ids', []))
                    ->map(fn ($id): int => (int) $id)->unique()->values();
                $validExistingIds = $existingEvidence->pluck('id')->map(fn ($id): int => (int) $id);
                if ($keepIds->diff($validExistingIds)->isNotEmpty()) {
                    throw ValidationException::withMessages(['keep_evidence_ids' => 'One or more retained evidence images do not belong to this claim.']);
                }
                $newFiles = $request->file('evidence', []);
                if ($keepIds->count() + count($newFiles) < 1 || $keepIds->count() + count($newFiles) > 3) {
                    throw ValidationException::withMessages(['evidence' => 'Keep or upload between 1 and 3 evidence images.']);
                }

                DB::table('client_first_touch_revisions')->insert([
                    'claim_id' => $claimId,
                    'reason' => (string) $request->input('edit_reason'),
                    'previous_snapshot' => json_encode($this->snapshot($claim, $existingEvidence), JSON_THROW_ON_ERROR),
                    'revised_by_staff_id' => $actor['staff_id'],
                    'revised_by_name' => $actor['name'],
                    'revised_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $toRemove = $existingEvidence->reject(fn (object $row): bool => $keepIds->contains((int) $row->id));
                if ($toRemove->isNotEmpty()) {
                    DB::table('client_first_touch_evidence')->whereIn('id', $toRemove->pluck('id'))->delete();
                    $removedPaths = $toRemove->pluck('path')->filter()->values()->all();
                }

                DB::table('client_first_touch_claims')->where('id', $claimId)->update([
                    ...$this->claimValues($request),
                    'version' => (int) $claim->version + 1,
                    'updated_by_staff_id' => $actor['staff_id'],
                    'updated_by_name' => $actor['name'],
                    'updated_at' => now(),
                ]);
                $this->storeEvidence($newFiles, 'claim', $claimId, $clientId, $actor, $request, $storedPaths);
            }, 3);
        } catch (\Throwable $error) {
            $this->deletePaths($storedPaths);
            throw $error;
        }

        $this->deletePaths($removedPaths);

        return $this->query->show($clientId, $request);
    }

    public function storeDispute(Request $request, int $clientId): array
    {
        $this->ensureClient($clientId);
        $actor = $this->actor($request);
        $storedPaths = [];
        $notification = null;

        try {
            DB::transaction(function () use ($request, $clientId, $actor, &$storedPaths, &$notification): void {
                $claim = DB::table('client_first_touch_claims')
                    ->where('id', (int) $request->input('claim_id'))
                    ->where('client_id', $clientId)
                    ->where('is_current', true)
                    ->lockForUpdate()
                    ->first();
                if (! $claim) {
                    throw ValidationException::withMessages(['claim_id' => 'The current first-touch claim is no longer available.']);
                }
                if ($this->hasOpenConflict($clientId)) {
                    abort(409, 'This client already has an open first-touch conflict.');
                }

                $now = now();
                $disputeId = DB::table('client_first_touch_disputes')->insertGetId([
                    'client_id' => $clientId,
                    'claim_id' => (int) $claim->id,
                    'reason' => (string) $request->input('reason'),
                    'explanation' => (string) $request->input('explanation'),
                    'status' => 'open',
                    'submitted_by_staff_id' => $actor['staff_id'],
                    'submitted_by_name' => $actor['name'],
                    'submitted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->storeEvidence($request->file('evidence', []), 'dispute', $disputeId, $clientId, $actor, $request, $storedPaths);
                DB::table('client_first_touch_claims')->where('id', $claim->id)->update([
                    'status' => 'contested',
                    'updated_at' => $now,
                ]);
                $conflict = $this->openConflict($clientId, (int) $claim->id, $now);
                $notification = [
                    'conflict_id' => $conflict['id'],
                    'event_key' => 'dispute:'.$disputeId,
                    'opened' => $conflict['created'],
                ];
            }, 3);
        } catch (\Throwable $error) {
            $this->deletePaths($storedPaths);
            throw $error;
        }

        if ($notification) {
            $this->notify(fn () => $this->notifications->conflictNeedsReview(
                $notification['conflict_id'],
                $actor['staff_id'],
                $notification['event_key'],
                $notification['opened'],
            ));
        }

        return $this->query->show($clientId, $request);
    }

    public function resolveConflict(Request $request, int $conflictId): array
    {
        $actor = $this->actor($request);
        $result = DB::transaction(function () use ($request, $conflictId, $actor): array {
            $conflict = DB::table('client_first_touch_conflicts')
                ->where('id', $conflictId)
                ->lockForUpdate()
                ->first();
            if (! $conflict) {
                abort(404, 'First-touch conflict not found.');
            }
            if (! in_array($conflict->status, self::OPEN_CONFLICT_STATUSES, true)) {
                abort(409, 'This first-touch conflict has already been resolved.');
            }
            if (! $this->recipients->canReview($request, $conflictId)) {
                abort(403, 'This conflict is assigned to another independent reviewer.');
            }

            $decision = (string) $request->input('decision');
            $clientId = (int) $conflict->client_id;
            $now = now();
            if ($decision === 'clarification_requested') {
                if (DB::table('client_first_touch_clarifications')->where('conflict_id', $conflictId)->where('status', 'pending')->exists()) {
                    abort(409, 'This conflict already has a pending clarification request.');
                }
                $recipientStaffId = (int) $request->input('clarification_recipient_staff_id', 0);
                $recipientName = $this->recipients->participantName($conflictId, $recipientStaffId);
                if ($recipientStaffId <= 0 || ! $recipientName) {
                    throw ValidationException::withMessages([
                        'clarification_recipient_staff_id' => 'Select an active evidence submitter from this conflict.',
                    ]);
                }
                $clarificationId = DB::table('client_first_touch_clarifications')->insertGetId([
                    'conflict_id' => $conflictId,
                    'client_id' => $clientId,
                    'requested_from_staff_id' => $recipientStaffId,
                    'requested_from_name' => $recipientName,
                    'requested_by_staff_id' => $actor['staff_id'],
                    'requested_by_name' => $actor['name'],
                    'request_note' => (string) $request->input('note'),
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('client_first_touch_conflicts')->where('id', $conflictId)->update([
                    'status' => 'clarification_requested',
                    'resolution' => $decision,
                    'comment' => (string) $request->input('note'),
                    'clarification_recipient' => $recipientName,
                    'clarification_recipient_staff_id' => $recipientStaffId,
                    'reviewed_by_staff_id' => $actor['staff_id'],
                    'reviewed_by_name' => $actor['name'],
                    'reviewed_at' => $now,
                    'updated_at' => $now,
                ]);

                return [
                    'client_id' => $clientId,
                    'decision' => $decision,
                    'clarification_id' => $clarificationId,
                ];
            }

            $currentClaimId = $conflict->current_claim_id ? (int) $conflict->current_claim_id : null;
            $activeClaims = DB::table('client_first_touch_claims')
                ->where('client_id', $clientId)
                ->whereIn('status', ['current', 'contested', 'competing'])
                ->lockForUpdate()
                ->get();
            $reviewerParticipatedInConflict = $activeClaims->contains(
                fn (object $claim): bool => (int) $claim->submitted_by_staff_id === $actor['staff_id'],
            ) || DB::table('client_first_touch_disputes')
                ->where('client_id', $clientId)
                ->where('status', 'open')
                ->where('submitted_by_staff_id', $actor['staff_id'])
                ->exists();
            if ($reviewerParticipatedInConflict) {
                abort(403, 'An independent manager or system administrator who did not submit the disputed evidence must review this conflict.');
            }
            $selectedClaimId = (int) $request->input('selected_claim_id', 0);

            if ($decision === 'accept_competing' && ! $activeClaims->contains(fn (object $claim): bool => (int) $claim->id === $selectedClaimId && $claim->status === 'competing')) {
                throw ValidationException::withMessages(['selected_claim_id' => 'Select a competing claim from this conflict.']);
            }

            foreach ($activeClaims as $claim) {
                $claimId = (int) $claim->id;
                $status = match ($decision) {
                    'uphold_current' => $claimId === $currentClaimId ? 'current' : 'rejected',
                    'accept_competing' => $claimId === $selectedClaimId ? 'current' : 'superseded',
                    'reject_both' => 'rejected',
                };
                DB::table('client_first_touch_claims')->where('id', $claimId)->update([
                    'status' => $status,
                    'is_current' => $status === 'current',
                    'updated_at' => $now,
                ]);
            }

            DB::table('client_first_touch_disputes')
                ->where('client_id', $clientId)
                ->where('status', 'open')
                ->update([
                    'status' => $decision === 'uphold_current' ? 'dismissed' : 'resolved',
                    'resolution' => $decision,
                    'resolved_at' => $now,
                    'updated_at' => $now,
                ]);
            DB::table('client_first_touch_conflicts')->where('id', $conflictId)->update([
                'status' => 'resolved',
                'resolution' => $decision,
                'comment' => (string) $request->input('note'),
                'clarification_recipient' => null,
                'clarification_recipient_staff_id' => null,
                'resolved_by_staff_id' => $actor['staff_id'],
                'resolved_by_name' => $actor['name'],
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('client_first_touch_clarifications')
                ->where('conflict_id', $conflictId)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled', 'updated_at' => $now]);

            return ['client_id' => $clientId, 'decision' => $decision, 'clarification_id' => null];
        }, 3);

        if ($result['decision'] === 'clarification_requested') {
            $this->notify(fn () => $this->notifications->clarificationRequested(
                $conflictId,
                (int) $result['clarification_id'],
                $actor['staff_id'],
            ));
        } else {
            $this->notify(fn () => $this->notifications->conflictResolved($conflictId, $actor['staff_id'], $result['decision']));
        }

        return $this->query->show((int) $result['client_id'], $request);
    }

    public function respondToClarification(Request $request, int $conflictId, int $clarificationId): array
    {
        $actor = $this->actor($request);
        $storedPaths = [];

        try {
            $clientId = DB::transaction(function () use ($request, $conflictId, $clarificationId, $actor, &$storedPaths): int {
                $clarification = DB::table('client_first_touch_clarifications')
                    ->where('id', $clarificationId)
                    ->where('conflict_id', $conflictId)
                    ->lockForUpdate()
                    ->first();
                if (! $clarification) {
                    abort(404, 'First-touch clarification request not found.');
                }
                if ($clarification->status !== 'pending') {
                    abort(409, 'This clarification request has already been completed.');
                }
                if (
                    (int) $clarification->requested_from_staff_id !== $actor['staff_id']
                    && ! $this->hasRole($request, 'System Admin')
                ) {
                    abort(403, 'This clarification request is assigned to another staff member.');
                }

                $conflict = DB::table('client_first_touch_conflicts')
                    ->where('id', $conflictId)
                    ->lockForUpdate()
                    ->first();
                if (! $conflict || $conflict->status !== 'clarification_requested') {
                    abort(409, 'This conflict is no longer waiting for clarification.');
                }

                $now = now();
                DB::table('client_first_touch_clarifications')->where('id', $clarificationId)->update([
                    'status' => 'responded',
                    'response' => (string) $request->input('response'),
                    'responded_by_staff_id' => $actor['staff_id'],
                    'responded_by_name' => $actor['name'],
                    'responded_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->storeEvidence(
                    $request->file('evidence', []),
                    'clarification',
                    $clarificationId,
                    (int) $conflict->client_id,
                    $actor,
                    $request,
                    $storedPaths,
                );
                DB::table('client_first_touch_conflicts')->where('id', $conflictId)->update([
                    'status' => 'open',
                    'resolution' => null,
                    'clarification_recipient' => null,
                    'clarification_recipient_staff_id' => null,
                    'updated_at' => $now,
                ]);

                return (int) $conflict->client_id;
            }, 3);
        } catch (\Throwable $error) {
            $this->deletePaths($storedPaths);
            throw $error;
        }

        $this->notify(fn () => $this->notifications->clarificationResponded(
            $conflictId,
            $clarificationId,
            $actor['staff_id'],
        ));

        return $this->query->show($clientId, $request);
    }

    private function claimValues(Request $request): array
    {
        return [
            'source_group' => $request->input('source_group'),
            'source_value' => $request->input('source_value'),
            'channel' => $request->input('channel'),
            'method' => $request->input('method'),
            'occurred_on' => $request->input('occurred_on'),
            'occurred_time' => $request->input('occurred_time') ?: null,
            'occurrence_precision' => $request->input('occurrence_precision'),
            'occurrence_timezone' => $request->input('occurrence_timezone'),
            'chronology_needs_review' => $request->boolean('chronology_needs_review'),
            'client_contact' => $request->input('client_contact') ?: null,
            'contact_mode' => $request->input('contact_mode'),
            'amiosh_contact_staff_id' => $request->input('amiosh_contact_staff_id') ?: null,
            'amiosh_contact_name' => $request->input('amiosh_contact_name') ?: null,
            'amiosh_contact_code' => $request->input('amiosh_contact_code') ?: null,
            'referrer_staff_id' => $request->input('referrer_staff_id') ?: null,
            'referrer_name' => $request->input('referrer_name') ?: null,
            'referrer_code' => $request->input('referrer_code') ?: null,
            'employment_context' => $request->input('employment_context') ?: null,
            'employment_boundary' => $request->input('employment_boundary') ?: null,
            'employment_ended_on' => $request->input('employment_ended_on') ?: null,
            'employment_departure_type' => $request->input('employment_departure_type') ?: null,
            'linked_inquiry_id' => $request->input('linked_inquiry_id') ?: null,
            'inquiry_ref' => $request->input('inquiry_ref') ?: null,
            'notes' => $request->input('notes') ?: null,
        ];
    }

    private function storeEvidence(array $files, string $ownerType, int $ownerId, int $clientId, array $actor, Request $request, array &$storedPaths): void
    {
        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
            $filename = bin2hex(random_bytes(16)).'.'.$extension;
            $path = AppFilePaths::storeFileAs("client-first-touch/{$clientId}/".now()->format('Y/m'), $file, $filename);
            $storedPaths[] = $path;
            DB::table('client_first_touch_evidence')->insert([
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'platform' => $request->input('channel') ?: ($ownerType === 'dispute' ? 'Dispute attachment' : 'Evidence image'),
                'author' => $request->input('client_contact') ?: $actor['name'],
                'evidence_date' => $request->input('occurred_on') ?: now()->toDateString(),
                'uploaded_by_staff_id' => $actor['staff_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function validateCompetingChronology(Request $request, object $current): void
    {
        $newDate = (string) $request->input('occurred_on');
        $currentDate = substr((string) $current->occurred_on, 0, 10);
        if ($newDate > $currentDate) {
            throw ValidationException::withMessages(['occurred_on' => 'Competing evidence must document an encounter on or before the current first-touch date.']);
        }
        $newTime = (string) $request->input('occurred_time', '');
        $currentTime = $current->occurred_time ? substr((string) $current->occurred_time, 0, 5) : '';
        if ($newDate === $currentDate && $newTime !== '' && $currentTime !== '' && $newTime >= $currentTime) {
            throw ValidationException::withMessages(['occurred_time' => 'Competing evidence on the same date must show an earlier time.']);
        }
    }

    private function openConflict(int $clientId, int $currentClaimId, mixed $now): array
    {
        $existing = DB::table('client_first_touch_conflicts')
            ->where('client_id', $clientId)
            ->whereIn('status', self::OPEN_CONFLICT_STATUSES)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return ['id' => (int) $existing->id, 'created' => false];
        }
        $id = DB::table('client_first_touch_conflicts')->insertGetId([
            'client_id' => $clientId,
            'current_claim_id' => $currentClaimId,
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => (int) $id, 'created' => true];
    }

    private function hasOpenConflict(int $clientId): bool
    {
        return DB::table('client_first_touch_conflicts')
            ->where('client_id', $clientId)
            ->whereIn('status', self::OPEN_CONFLICT_STATUSES)
            ->exists();
    }

    private function actor(Request $request): array
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            abort(403, 'A staff identity is required for first-touch evidence changes.');
        }

        return [
            'staff_id' => $staffId,
            'name' => (string) ($request->session()->get('full_name') ?: $request->session()->get('name_code') ?: "Staff #{$staffId}"),
        ];
    }

    private function hasRole(Request $request, string $role): bool
    {
        $roles = $request->session()->get('roles', []);
        $decoded = is_string($roles) ? json_decode($roles, true) : null;
        $roles = is_array($decoded) ? $decoded : (is_array($roles) ? $roles : [$roles]);

        return in_array(
            strtolower($role),
            array_map(static fn ($value): string => strtolower(trim((string) $value)), $roles),
            true,
        );
    }

    private function notify(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $error) {
            report($error);
        }
    }

    private function ensureClient(int $clientId): void
    {
        if ($clientId <= 0 || ! DB::table('client_company')->where('company_id', $clientId)->whereNull('deleted_at')->exists()) {
            abort(404, 'Client company not found.');
        }
    }

    private function validateLinkedInquiry(Request $request, int $clientId, ?int $claimId = null): void
    {
        $inquiryId = (int) $request->input('linked_inquiry_id', 0);
        if ($inquiryId <= 0) {
            return;
        }

        if ($claimId && DB::table('client_first_touch_claims')
            ->where('id', $claimId)
            ->where('client_id', $clientId)
            ->where('linked_inquiry_id', $inquiryId)
            ->exists()) {
            return;
        }

        $belongsToClient = DB::table('sales_inquiries')
            ->where('id', $inquiryId)
            ->where('client_id', $clientId)
            ->whereNull('deleted_at')
            ->exists();
        if (! $belongsToClient) {
            throw ValidationException::withMessages([
                'linked_inquiry_id' => 'Select an active inquiry linked to this client.',
            ]);
        }
    }

    private function snapshot(object $claim, $evidence): array
    {
        return [
            'id' => (int) $claim->id,
            'version' => (int) $claim->version,
            'sourceGroup' => (string) $claim->source_group,
            'sourceValue' => (string) $claim->source_value,
            'channel' => (string) $claim->channel,
            'method' => (string) $claim->method,
            'occurredAt' => substr((string) $claim->occurred_on, 0, 10),
            'occurredTime' => $claim->occurred_time ? substr((string) $claim->occurred_time, 0, 5) : '',
            'clientContact' => (string) ($claim->client_contact ?? ''),
            'amioshContact' => (string) ($claim->amiosh_contact_name ?? ''),
            'linkedInquiryId' => $claim->linked_inquiry_id ? (int) $claim->linked_inquiry_id : null,
            'inquiryRef' => (string) ($claim->inquiry_ref ?? ''),
            'notes' => (string) ($claim->notes ?? ''),
            'proofCount' => $evidence->count(),
            'proofs' => $evidence->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'originalName' => (string) $row->original_name,
                'mimeType' => (string) $row->mime_type,
                'fileSize' => (int) $row->size,
            ])->all(),
        ];
    }

    private function deletePaths(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            AppFilePaths::deleteStoredPath($path);
        }
    }
}
