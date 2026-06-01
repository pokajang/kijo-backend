<?php

namespace App\Http\Middleware;

use App\Services\RememberMeService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RequireAuth
{
    private const UNAUTHORIZED_MESSAGE = 'Unauthorized. Please log in to continue.';

    public function __construct(private RememberMeService $rememberMe) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->resolveAuthenticatedUser($request);

        if ($user === null) {
            $response = $this->unauthorizedResponse();
            $this->rememberMe->clear($response, $request);

            return $response;
        }

        $this->syncSessionAndRequest($request, $user);

        if (! $request->isMethodSafe() && ! $this->hasValidCsrfToken($request)) {
            return response()->json([
                'status' => 'error',
                'message' => 'CSRF token mismatch.',
            ], 419);
        }

        $response = $next($request);
        $this->rememberMe->rotate($response, $request);

        return $response;
    }

    public function resolveAuthenticatedUser(Request $request): ?array
    {
        $userId = (int) $request->session()->get('user_id', 0);
        $staffId = (int) $request->session()->get('staff_id', 0);

        if (($userId <= 0 || $staffId <= 0) && Schema::hasTable('system_users')) {
            $remembered = $this->rememberMe->resolveUser($request);
            if ($remembered !== null) {
                $userId = $remembered['id'];
            }
        }

        if ($userId <= 0 || ! Schema::hasTable('system_users')) {
            return null;
        }

        $query = DB::table('system_users')
            ->select($this->systemUserColumns())
            ->where('system_users.id', $userId);

        if (Schema::hasTable('staff_general')) {
            $query->leftJoin('staff_general', 'staff_general.staff_id', '=', 'system_users.staff_id');
        }

        $user = $query->first();

        if (
            ! $user ||
            ($staffId > 0 && (int) ($user->staff_id ?? 0) !== $staffId) ||
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
            'full_name' => (string) ($user->full_name ?? ''),
            'name_code' => (string) ($user->name_code ?? ''),
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
            'full_name' => $user['full_name'],
            'name_code' => $user['name_code'],
        ]);

        $request->attributes->set('auth.user', $user);
        $request->attributes->set('auth.roles', $user['roles']);
    }

    private function systemUserColumns(): array
    {
        $columns = ['system_users.id', 'system_users.staff_id', 'system_users.email', 'system_users.role', 'system_users.is_active'];

        foreach (['total_lock', 'account_locked_until'] as $column) {
            if (Schema::hasColumn('system_users', $column)) {
                $columns[] = 'system_users.'.$column;
            }
        }

        if (Schema::hasTable('staff_general')) {
            $columns[] = 'staff_general.full_name';
            $columns[] = 'staff_general.name_code';
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
