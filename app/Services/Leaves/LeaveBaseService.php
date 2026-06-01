<?php

namespace App\Services\Leaves;

use App\Http\Requests\Leave\AssignEntitlementRequest;
use App\Http\Requests\Leave\LeaveActionRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Requests\Leave\UpdateEntitlementRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class LeaveBaseService
{
    public function __construct(protected AuditLogService $auditLog) {}

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function isPrivileged(Request $request): bool
    {
        $roles = (array) $request->session()->get('roles', []);
        return collect($roles)->contains(fn($r) =>
            str_contains(strtolower((string) $r), 'hr') ||
            str_contains(strtolower((string) $r), 'manager') ||
            str_contains(strtolower((string) $r), 'admin') ||
            str_contains(strtolower((string) $r), 'super')
        );
    }

    protected function hasAnyRole(Request $request, array $allowedRoles): bool
    {
        $roles = $request->session()->get('roles', []);
        if (!is_array($roles)) {
            $roles = [$roles];
        }
        $normalizedRoles = array_map(
            static fn ($role): string => strtolower(trim((string) $role)),
            $roles,
        );
        if (in_array('system admin', $normalizedRoles, true)) {
            return true;
        }

        $normalizedAllowed = array_map(
            static fn (string $role): string => strtolower(trim($role)),
            $allowedRoles,
        );

        return collect($normalizedRoles)
            ->intersect($normalizedAllowed)
            ->isNotEmpty();
    }

    protected function canManageEntitlements(Request $request): bool
    {
        return $this->hasAnyRole($request, ['HR', 'System Admin']);
    }

    protected function unauthorizedResponse(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Unauthorized access.'], 403);
    }

    protected function isValidEmail(?string $email): bool
    {
        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    // -------------------------------------------------------------------------
    // createLeave - POST
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // getAllLeavesData - GET (privileged)
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // getPersonalLeavesRecord - GET
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // leaveAction - POST (privileged)
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // cancelLeave - POST
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // getAllEntitlements - GET (any authenticated user)
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // getMyEntitlements - GET
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // assignLeavesEntitlement - POST
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // updateEntitlement - POST/PUT
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // deleteEntitlement - POST/DELETE
    // -------------------------------------------------------------------------
}
