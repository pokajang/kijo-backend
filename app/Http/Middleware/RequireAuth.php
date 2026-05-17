<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RequireAuth
{
    private const UNAUTHORIZED_MESSAGE = 'Unauthorized. Please log in to continue.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->resolveAuthenticatedUser($request);

        if ($user === null) {
            return $this->unauthorizedResponse();
        }

        $this->syncSessionAndRequest($request, $user);

        if (! $request->isMethodSafe() && ! $this->hasValidCsrfToken($request)) {
            return response()->json([
                'status' => 'error',
                'message' => 'CSRF token mismatch.',
            ], 419);
        }

        return $next($request);
    }

    public function resolveAuthenticatedUser(Request $request): ?array
    {
        $userId = (int) $request->session()->get('user_id', 0);
        $staffId = (int) $request->session()->get('staff_id', 0);

        if ($userId <= 0 || $staffId <= 0 || ! Schema::hasTable('system_users')) {
            return null;
        }

        $user = DB::table('system_users')
            ->select($this->systemUserColumns())
            ->where('id', $userId)
            ->first();

        if (
            ! $user ||
            (int) ($user->staff_id ?? 0) !== $staffId ||
            ! (bool) ($user->is_active ?? false) ||
            $this->isLocked($user)
        ) {
            $this->invalidateSession($request);
            return null;
        }

        return [
            'id' => (int) $user->id,
            'staff_id' => (int) $user->staff_id,
            'email' => (string) ($user->email ?? ''),
            'roles' => self::decodeRoles($user->role ?? null),
        ];
    }

    public static function decodeRoles(mixed $raw): array
    {
        if (is_array($raw)) {
            return self::normalizeRoles($raw);
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return self::normalizeRoles($decoded);
        }

        return self::normalizeRoles([$raw]);
    }

    public static function roleKeys(array $roles): array
    {
        return array_values(array_unique(array_map(
            static fn (mixed $role): string => strtolower(trim((string) $role)),
            self::normalizeRoles($roles),
        )));
    }

    public static function unauthorizedPayload(): array
    {
        return [
            'status' => 'error',
            'message' => self::UNAUTHORIZED_MESSAGE,
        ];
    }

    private static function normalizeRoles(array $roles): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $role): string => trim((string) $role),
            $roles,
        ), static fn (string $role): bool => $role !== ''));

        return array_values(array_unique($normalized));
    }

    private function syncSessionAndRequest(Request $request, array $user): void
    {
        $request->session()->put([
            'user_id' => $user['id'],
            'staff_id' => $user['staff_id'],
            'roles' => $user['roles'],
            'email' => $user['email'],
        ]);

        $request->attributes->set('auth.user', $user);
        $request->attributes->set('auth.roles', $user['roles']);
    }

    private function systemUserColumns(): array
    {
        $columns = ['id', 'staff_id', 'email', 'role', 'is_active'];

        foreach (['total_lock', 'account_locked_until'] as $column) {
            if (Schema::hasColumn('system_users', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function isLocked(object $user): bool
    {
        if (property_exists($user, 'total_lock') && (bool) ($user->total_lock ?? false)) {
            return true;
        }

        if (! property_exists($user, 'account_locked_until') || empty($user->account_locked_until)) {
            return false;
        }

        return Carbon::parse($user->account_locked_until)->isFuture();
    }

    private function hasValidCsrfToken(Request $request): bool
    {
        $sessionToken = (string) $request->session()->token();
        if ($sessionToken === '') {
            return false;
        }

        $requestToken = (string) (
            $request->header('X-CSRF-TOKEN')
            ?: $request->header('X-XSRF-TOKEN')
            ?: $request->input('_token', '')
        );

        return $requestToken !== '' && hash_equals($sessionToken, $requestToken);
    }

    private function invalidateSession(Request $request): void
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function unauthorizedResponse(): Response
    {
        return response()->json(self::unauthorizedPayload(), 403);
    }
}
