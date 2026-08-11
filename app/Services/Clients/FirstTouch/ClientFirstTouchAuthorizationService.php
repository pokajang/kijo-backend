<?php

namespace App\Services\Clients\FirstTouch;

use Illuminate\Http\Request;

class ClientFirstTouchAuthorizationService
{
    public function __construct(private ClientFirstTouchRecipientService $recipients) {}

    public function permissions(
        Request $request,
        ?int $claimSubmitterStaffId = null,
        ?int $conflictId = null,
        ?string $conflictStatus = null,
        ?int $clarificationRecipientStaffId = null,
    ): array {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $hasOpenConflict = in_array($conflictStatus, ['open', 'clarification_requested'], true);
        $isPrivileged = $this->hasAnyRole($request, ['Manager', 'System Admin']);
        $isSystemAdmin = $this->hasAnyRole($request, ['System Admin']);

        return [
            'canSubmitEvidence' => $staffId > 0,
            'canEditEvidence' => $staffId > 0
                && $claimSubmitterStaffId !== null
                && ! $hasOpenConflict
                && ($staffId === $claimSubmitterStaffId || $isPrivileged),
            'canDisputeEvidence' => $staffId > 0 && $claimSubmitterStaffId !== null && ! $hasOpenConflict,
            'canReviewConflict' => $staffId > 0
                && $hasOpenConflict
                && $conflictId !== null
                && $this->recipients->canReview($request, $conflictId),
            'canRespondToClarification' => $staffId > 0
                && $conflictStatus === 'clarification_requested'
                && ($staffId === $clarificationRecipientStaffId || $isSystemAdmin),
        ];
    }

    public function canEditClaim(Request $request, int $submitterStaffId): bool
    {
        $staffId = (int) $request->session()->get('staff_id', 0);

        return $staffId > 0
            && ($staffId === $submitterStaffId || $this->hasAnyRole($request, ['Manager', 'System Admin']));
    }

    public function hasAnyRole(Request $request, array $roles): bool
    {
        $sessionRoles = $request->session()->get('roles', []);
        $decoded = is_string($sessionRoles) ? json_decode($sessionRoles, true) : null;
        $sessionRoles = is_array($decoded) ? $decoded : (is_array($sessionRoles) ? $sessionRoles : [$sessionRoles]);
        $normalized = array_map(static fn ($value): string => strtolower(trim((string) $value)), $sessionRoles);

        return collect($roles)->contains(fn (string $role): bool => in_array(strtolower($role), $normalized, true));
    }
}
