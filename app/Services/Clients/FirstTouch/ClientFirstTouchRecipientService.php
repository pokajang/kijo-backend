<?php

namespace App\Services\Clients\FirstTouch;

use App\Services\AppNotificationService;
use App\Services\Workflows\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientFirstTouchRecipientService
{
    public const TEMPLATE_KEY = 'client-first-touch-conflict';

    public const STEP_KEY = 'review';

    private const REVIEW_ROLES = ['Manager', 'System Admin'];

    public function reviewersForConflict(int $conflictId): array
    {
        $participants = $this->participantStaffIds($conflictId);
        $configured = app(WorkflowService::class)->effectiveStepRecipients(
            self::TEMPLATE_KEY,
            self::STEP_KEY,
            1,
            self::REVIEW_ROLES,
        );
        $roleEligibleIds = app(AppNotificationService::class)->staffIdsForRoles(self::REVIEW_ROLES);
        $configured = collect($configured)
            ->filter(fn (array $recipient): bool => in_array((int) ($recipient['staff_id'] ?? 0), $roleEligibleIds, true))
            ->values()
            ->all();
        $eligible = $this->withoutParticipants($configured, $participants);

        if ($eligible !== []) {
            return $eligible;
        }

        return $this->withoutParticipants($this->recipientsForStaffIds($roleEligibleIds), $participants);
    }

    public function canReview(Request $request, int $conflictId): bool
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return false;
        }

        return collect($this->reviewersForConflict($conflictId))
            ->contains(fn (array $recipient): bool => (int) $recipient['staff_id'] === $staffId);
    }

    public function participantsForConflict(int $conflictId): array
    {
        return $this->recipientsForStaffIds($this->participantStaffIds($conflictId));
    }

    public function participantName(int $conflictId, int $staffId): ?string
    {
        $participant = collect($this->participantsForConflict($conflictId))
            ->first(fn (array $recipient): bool => (int) $recipient['staff_id'] === $staffId);

        return $participant ? (string) $participant['full_name'] : null;
    }

    public function recipientsForStaffIds(array $staffIds): array
    {
        $staffIds = array_values(array_unique(array_filter(array_map('intval', $staffIds))));
        if ($staffIds === [] || ! Schema::hasTable('staff_general')) {
            return [];
        }

        $query = DB::table('staff_general as sg')
            ->whereIn('sg.staff_id', $staffIds)
            ->whereNull('sg.deleted_at')
            ->whereRaw("LOWER(COALESCE(sg.status, '')) = 'active'");

        if (Schema::hasTable('system_users')) {
            $query->leftJoin('system_users as su', function ($join): void {
                $join->on('su.staff_id', '=', 'sg.staff_id');
                if (Schema::hasColumn('system_users', 'is_active')) {
                    $join->where('su.is_active', 1);
                }
            });
        }

        $emailExpression = Schema::hasTable('system_users')
            ? (Schema::hasColumn('staff_general', 'email')
                ? 'COALESCE(NULLIF(su.email, ""), sg.email) as email'
                : 'su.email as email')
            : (Schema::hasColumn('staff_general', 'email') ? 'sg.email as email' : 'NULL as email');

        return $query
            ->get([
                'sg.staff_id',
                'sg.full_name',
                'sg.name_code',
                DB::raw($emailExpression),
            ])
            ->map(fn (object $row): array => [
                'staff_id' => (int) $row->staff_id,
                'full_name' => (string) ($row->full_name ?: $row->name_code ?: 'Staff #'.$row->staff_id),
                'name_code' => (string) ($row->name_code ?? ''),
                'email' => strtolower(trim((string) ($row->email ?? ''))),
            ])
            ->unique('staff_id')
            ->values()
            ->all();
    }

    private function participantStaffIds(int $conflictId): array
    {
        $conflict = DB::table('client_first_touch_conflicts')->where('id', $conflictId)->first();
        if (! $conflict) {
            return [];
        }

        $claimants = DB::table('client_first_touch_claims')
            ->where('client_id', $conflict->client_id)
            ->where(function ($query) use ($conflict): void {
                $query->where('id', $conflict->current_claim_id)
                    ->orWhere('submitted_at', '>=', $conflict->created_at);
            })
            ->pluck('submitted_by_staff_id');
        $disputers = DB::table('client_first_touch_disputes')
            ->where('client_id', $conflict->client_id)
            ->where('submitted_at', '>=', $conflict->created_at)
            ->pluck('submitted_by_staff_id');

        return $claimants
            ->merge($disputers)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function withoutParticipants(array $recipients, array $participantIds): array
    {
        return collect($recipients)
            ->filter(fn (array $recipient): bool => ! in_array((int) ($recipient['staff_id'] ?? 0), $participantIds, true))
            ->unique(fn (array $recipient): int => (int) ($recipient['staff_id'] ?? 0))
            ->values()
            ->all();
    }
}
