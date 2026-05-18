<?php

namespace App\Services\QuoteRecords;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QuoteRecordDeletePermission
{
    public static function denial(Request $request, object $quote): ?JsonResponse
    {
        if (self::isPrivileged($request)) {
            return null;
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        $nameCode = strtolower(trim((string) $request->session()->get('name_code', '')));
        $creatorId = (int) ($quote->created_by_id ?? 0);
        $creatorCode = strtolower(trim((string) ($quote->created_by_code ?? '')));

        $sameStaff = $staffId > 0 && $creatorId > 0 && $staffId === $creatorId;
        $sameCode = $nameCode !== '' && $creatorCode !== '' && $nameCode === $creatorCode;

        if ($sameStaff || $sameCode) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Delete disabled: this is not your record.',
        ], 403);
    }

    private static function isPrivileged(Request $request): bool
    {
        $roles = $request->session()->get('roles', []);
        if (! is_array($roles)) {
            $roles = $roles ? [$roles] : [];
        }

        $normalized = array_map(
            static fn (mixed $role): string => strtolower(trim((string) $role)),
            $roles,
        );

        return in_array('manager', $normalized, true)
            || in_array('system admin', $normalized, true);
    }
}
