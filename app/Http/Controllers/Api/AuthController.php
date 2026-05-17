<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemUser;
use App\Services\AuditLogService;
use App\Services\LoginRateLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS             = 5;
    private const BASE_LOCKOUT_MINUTES     = 15;
    private const MAX_LOCKOUT_MINUTES      = 1440; // 24h
    private const CHALLENGE_AFTER_LOCKOUTS = 2;
    private const DUMMY_HASH               = '$2y$10$9PW7i67cNA88aYvD.GSTo.ORqv8CbG7CinIY41gd9ek3iltllwu1O';

    public function __construct(
        private LoginRateLimitService $rateLimiter,
        private AuditLogService $auditLog,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $email    = strtolower(trim($request->input('email')));
        $password = $request->input('password');
        $ip       = $this->clientIp($request);
        $nowTs    = time();
        $today    = date('Y-m-d');

        $bucketKeys = $this->rateLimiter->bucketKeys($ip, $email);

        DB::beginTransaction();

        try {
            // 1) Global rate limit check
            $blockedUntilTs = $this->rateLimiter->getBlockedUntil($bucketKeys, $nowTs);
            if ($blockedUntilTs) {
                DB::rollBack();
                return $this->rateLimitedResponse($blockedUntilTs, $nowTs);
            }

            // 2) Fetch user with row lock
            $user = SystemUser::where('email', $email)
                ->where('is_active', 1)
                ->lockForUpdate()
                ->first();

            if (!$user) {
                $newBlock = $this->rateLimiter->recordFailure($bucketKeys, $nowTs);
                DB::commit();
                password_verify($password, self::DUMMY_HASH);
                usleep(random_int(150000, 300000));
                return $newBlock
                    ? $this->rateLimitedResponse($newBlock, $nowTs)
                    : response()->json(['status' => 'error', 'message' => 'Invalid email or password.']);
            }

            // 3) Permanent lock
            if ($user->total_lock) {
                $newBlock = $this->rateLimiter->recordFailure($bucketKeys, $nowTs);
                DB::commit();
                usleep(random_int(150000, 300000));
                return $newBlock
                    ? $this->rateLimitedResponse($newBlock, $nowTs)
                    : response()->json(['status' => 'error', 'message' => 'Your account is permanently locked. Please contact the administrator.']);
            }

            // 4) Temporary lock check
            if ($user->account_locked_until && $user->account_locked_until->timestamp > $nowTs) {
                $retryAfter = max(1, $user->account_locked_until->timestamp - $nowTs);
                $newBlock   = $this->rateLimiter->recordFailure($bucketKeys, $nowTs);
                DB::commit();
                usleep(random_int(150000, 300000));

                $payload = [
                    'status'              => 'error',
                    'message'             => 'Account is temporarily locked. Please try again later.',
                    'retry_after'         => $retryAfter,
                    'requires_challenge'  => $user->lockout_count >= self::CHALLENGE_AFTER_LOCKOUTS,
                ];
                if ($newBlock) {
                    $payload['retry_after'] = max($retryAfter, max(1, $newBlock - $nowTs));
                    return response()->json(['status' => 'error', 'message' => 'Too many login attempts. Please try again later.', 'retry_after' => $payload['retry_after']], 429);
                }
                return response()->json($payload);
            }

            // 5) Reset daily lockout_count on new day
            if ($user->last_lockout_date !== $today && $user->lockout_count !== 0) {
                $user->lockout_count    = 0;
                $user->last_lockout_date = $today;
                $user->save();
            }

            // 6) Verify password
            if (!password_verify($password, $user->password_hash)) {
                $failed     = $user->failed_attempts + 1;
                $lockCount  = $user->lockout_count;
                $lockUntil  = null;
                $retryAfter = 0;
                $requiresChallenge = false;

                if ($failed >= self::MAX_ATTEMPTS) {
                    $lockCount++;
                    $multiplier    = 2 ** max(0, $lockCount - 1);
                    $lockMinutes   = min(self::MAX_LOCKOUT_MINUTES, self::BASE_LOCKOUT_MINUTES * $multiplier);
                    $lockUntil     = now()->addMinutes($lockMinutes);
                    $retryAfter    = $lockMinutes * 60;
                    $failed        = 0;
                    $requiresChallenge = ($lockCount >= self::CHALLENGE_AFTER_LOCKOUTS);
                }

                $user->failed_attempts      = $failed;
                $user->last_failed_login    = now();
                $user->account_locked_until = $lockUntil;
                $user->lockout_count        = $lockCount;
                $user->last_lockout_date    = $today;
                $user->save();

                $newBlock = $this->rateLimiter->recordFailure($bucketKeys, $nowTs);
                DB::commit();
                usleep(random_int(150000, 300000));

                $payload = ['status' => 'error', 'message' => 'Invalid email or password.'];
                if ($lockUntil) {
                    $payload['retry_after']        = $retryAfter;
                    $payload['requires_challenge'] = $requiresChallenge;
                }
                if ($newBlock) {
                    return $this->rateLimitedResponse($newBlock, $nowTs);
                }
                return response()->json($payload);
            }

            // 7) Success — reset lockout fields
            $user->failed_attempts      = 0;
            $user->account_locked_until = null;
            $user->lockout_count        = 0;
            $user->last_lockout_date    = $today;
            $user->save();

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Login service is temporarily unavailable.'], 500);
        }

        // 8) Fetch staff profile (outside lock transaction)
        $staff = $user->staffProfile;

        // 9) Decode roles
        $roles = is_array($user->role) ? $user->role : [$user->role];

        // 10) Store session
        $request->session()->regenerate();
        $request->session()->put([
            'user_id'   => $user->id,
            'staff_id'  => $user->staff_id,
            'roles'     => $roles,
            'email'     => $user->email,
            'full_name' => $staff?->full_name,
            'name_code' => $staff?->name_code,
        ]);

        // 11) Link session row to user_id
        $this->linkCurrentSession($request, (int) $user->id);

        return response()->json([
            'status'  => 'success',
            'message' => 'Login successful.',
            'csrf_token' => $request->session()->token(),
            'user'    => [
                'user_id'   => $user->id,
                'staff_id'  => $user->staff_id,
                'email'     => $user->email,
                'roles'     => $roles,
                'full_name' => $staff?->full_name,
                'name_code' => $staff?->name_code,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('id', $request->session()->getId())->delete();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['status' => 'success', 'message' => 'Logged out.']);
    }

    public function session(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'csrf_token' => $request->session()->token(),
            'user'   => [
                'staff_id'  => $request->session()->get('staff_id'),
                'email'     => $request->session()->get('email'),
                'roles'     => $request->session()->get('roles', []),
                'full_name' => $request->session()->get('full_name'),
                'name_code' => $request->session()->get('name_code'),
            ],
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'currentPassword' => 'required|string',
            'newPassword'     => 'required|string|min:6',
            'confirmPassword' => 'required|string',
        ]);

        if ($request->input('newPassword') !== $request->input('confirmPassword')) {
            return response()->json(['status' => 'error', 'message' => 'Passwords do not match.'], 422);
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $user = DB::table('system_users')
            ->where('staff_id', $staffId)
            ->select('id', 'password_hash')
            ->first();

        if (!$user || !password_verify($request->input('currentPassword'), $user->password_hash)) {
            return response()->json(['status' => 'error', 'message' => 'Current password is incorrect.'], 422);
        }

        DB::table('system_users')
            ->where('id', $user->id)
            ->update([
                'password_hash' => password_hash($request->input('newPassword'), PASSWORD_BCRYPT),
                'updated_at'    => now(),
            ]);

        $this->invalidateOtherSessions($request, (int) $user->id);
        $request->session()->regenerateToken();

        $this->auditLog->log($request, 'Updated password');

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated successfully.',
            'csrf_token' => $request->session()->token(),
        ]);
    }

    private function clientIp(Request $request): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            $value = $_SERVER[$header] ?? null;
            if ($value) {
                foreach (explode(',', $value) as $part) {
                    $ip = trim($part);
                    if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
                }
            }
        }
        return $request->ip() ?? '0.0.0.0';
    }

    private function rateLimitedResponse(int $blockedUntilTs, int $nowTs): JsonResponse
    {
        return response()->json([
            'status'      => 'error',
            'message'     => 'Too many login attempts. Please try again later.',
            'retry_after' => max(1, $blockedUntilTs - $nowTs),
        ], 429);
    }

    private function linkCurrentSession(Request $request, int $userId): void
    {
        if (! Schema::hasTable('sessions')) {
            return;
        }

        DB::table('sessions')
            ->where('id', $request->session()->getId())
            ->update(['user_id' => $userId]);
    }

    private function invalidateOtherSessions(Request $request, int $userId): void
    {
        if (! Schema::hasTable('sessions')) {
            return;
        }

        DB::table('sessions')
            ->where('user_id', $userId)
            ->where('id', '<>', $request->session()->getId())
            ->delete();
    }
}
