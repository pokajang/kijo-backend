<?php

namespace App\Services\Assistant\UserTrace;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AssistantUserIdentityResolver
{
    public function resolve(Request $request): AssistantUserTraceIdentity
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $userId = (int) $request->session()->get('user_id', 0);
        $roles = $request->session()->get('roles', []);
        if (! is_array($roles)) {
            $roles = [$roles];
        }
        $sessionNameCode = trim((string) $request->session()->get('name_code', '')) ?: null;
        $sessionEmail = trim((string) $request->session()->get('email', '')) ?: null;

        $profile = null;
        if ($staffId > 0 && Schema::hasTable('staff_general')) {
            $profile = DB::table('staff_general')->where('staff_id', $staffId)->first();
        }
        $userEmail = null;
        if ($userId > 0 && Schema::hasTable('system_users')) {
            $userEmail = trim((string) DB::table('system_users')->where('id', $userId)->value('email')) ?: null;
        }

        $profileNameCode = $profile ? trim((string) ($profile->name_code ?? '')) : '';
        $warnings = [];
        if ($sessionNameCode && $profileNameCode !== '' && strcasecmp($sessionNameCode, $profileNameCode) !== 0) {
            $warnings[] = 'session_name_code_mismatch';
        }

        $position = $profile
            ? (trim((string) ($profile->crm_position ?? '')) ?: trim((string) ($profile->position ?? '')) ?: null)
            : null;

        return new AssistantUserTraceIdentity(
            $staffId,
            $userId,
            array_values(array_filter(array_map(static fn ($role): string => trim((string) $role), $roles))),
            $profileNameCode !== '' ? $profileNameCode : $sessionNameCode,
            ($profile ? (trim((string) ($profile->email ?? '')) ?: null) : null) ?: $sessionEmail ?: $userEmail,
            $profile ? (trim((string) ($profile->full_name ?? '')) ?: null) : null,
            $profile ? (trim((string) ($profile->department ?? '')) ?: null) : null,
            $position,
            $profile !== null,
            $warnings,
        );
    }
}
