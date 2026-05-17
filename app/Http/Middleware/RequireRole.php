<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$requiredRoles): Response
    {
        $roles = $request->attributes->get('auth.roles');
        if (! is_array($roles)) {
            $user = app(RequireAuth::class)->resolveAuthenticatedUser($request);
            if ($user === null) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json(RequireAuth::unauthorizedPayload(), 403);
            }

            $roles = $user['roles'];
            $request->session()->put([
                'user_id' => $user['id'],
                'staff_id' => $user['staff_id'],
                'roles' => $roles,
                'email' => $user['email'],
            ]);
            $request->attributes->set('auth.user', $user);
            $request->attributes->set('auth.roles', $roles);
        }

        $normalizedSessionRoles = RequireAuth::roleKeys($roles);
        $normalizedRequiredRoles = array_map(
            static fn (string $role): string => strtolower(trim($role)),
            $requiredRoles,
        );

        if (empty(array_intersect($normalizedRequiredRoles, $normalizedSessionRoles))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: required role missing.',
            ], 403);
        }

        return $next($request);
    }
}
