<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\SystemUser;
use App\Services\AuditLogService;
use App\Services\LoginRateLimitService;
use App\Services\RememberMeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const BASE_LOCKOUT_MINUTES = 15;

    private const MAX_LOCKOUT_MINUTES = 1440; // 24h

    private const CHALLENGE_AFTER_LOCKOUTS = 2;

    private const DUMMY_HASH = '$2y$10$9PW7i67cNA88aYvD.GSTo.ORqv8CbG7CinIY41gd9ek3iltllwu1O';

    public function __construct(
        private LoginRateLimitService $rateLimiter,
        private AuditLogService $auditLog,
        private RememberMeService $rememberMe,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'remember' => 'sometimes|boolean',
        ]);

        $email = strtolower(trim($request->input('email')));
        $password = $request->input('password');
        $ip = $this->clientIp($request);
        $nowTs = time();
        $today = date('Y-m-d');

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

            if (! $user) {
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
                $newBlock = $this->rateLimiter->recordFailure($bucketKeys, $nowTs);
                DB::commit();
                usleep(random_int(150000, 300000));

                $payload = [
                    'status' => 'error',
                    'message' => 'Account is temporarily locked. Please try again later.',
                    'retry_after' => $retryAfter,
                    'requires_challenge' => $user->lockout_count >= self::CHALLENGE_AFTER_LOCKOUTS,
                ];
                if ($newBlock) {
                    $payload['retry_after'] = max($retryAfter, max(1, $newBlock - $nowTs));

                    return response()->json(['status' => 'error', 'message' => 'Too many login attempts. Please try again later.', 'retry_after' => $payload['retry_after']], 429);
                }

                return response()->json($payload);
            }

            // 5) Reset daily lockout_count on new day
            if ($user->last_lockout_date !== $today && $user->lockout_count !== 0) {
                $user->lockout_count = 0;
                $user->last_lockout_date = $today;
                $user->save();
            }

            // 6) Verify password
            if (! password_verify($password, $user->password_hash)) {
                $failed = $user->failed_attempts + 1;
                $lockCount = $user->lockout_count;
                $lockUntil = null;
                $retryAfter = 0;
                $requiresChallenge = false;

                if ($failed >= self::MAX_ATTEMPTS) {
                    $lockCount++;
                    $multiplier = 2 ** max(0, $lockCount - 1);
                    $lockMinutes = min(self::MAX_LOCKOUT_MINUTES, self::BASE_LOCKOUT_MINUTES * $multiplier);
                    $lockUntil = now()->addMinutes($lockMinutes);
                    $retryAfter = $lockMinutes * 60;
                    $failed = 0;
                    $requiresChallenge = ($lockCount >= self::CHALLENGE_AFTER_LOCKOUTS);
                }

                $user->failed_attempts = $failed;
                $user->last_failed_login = now();
                $user->account_locked_until = $lockUntil;
                $user->lockout_count = $lockCount;
                $user->last_lockout_date = $today;
                $user->save();

                $newBlock = $this->rateLimiter->recordFailure($bucketKeys, $nowTs);
                DB::commit();
                usleep(random_int(150000, 300000));

                $payload = ['status' => 'error', 'message' => 'Invalid email or password.'];
                if ($lockUntil) {
                    $payload['retry_after'] = $retryAfter;
                    $payload['requires_challenge'] = $requiresChallenge;
                }
                if ($newBlock) {
                    return $this->rateLimitedResponse($newBlock, $nowTs);
                }

                return response()->json($payload);
            }

            // 7) Success — reset lockout fields
            $user->failed_attempts = 0;
            $user->account_locked_until = null;
            $user->lockout_count = 0;
            $user->last_lockout_date = $today;
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
            'user_id' => $user->id,
            'staff_id' => $user->staff_id,
            'roles' => $roles,
            'email' => $user->email,
            'full_name' => $staff?->full_name,
            'name_code' => $staff?->name_code,
        ]);

        // 11) Link session row to user_id
        $this->linkCurrentSession($request, (int) $user->id);

        $response = response()->json([
            'status' => 'success',
            'message' => 'Login successful.',
            'csrf_token' => $request->session()->token(),
            'user' => [
                'user_id' => $user->id,
                'staff_id' => $user->staff_id,
                'email' => $user->email,
                'roles' => $roles,
                'full_name' => $staff?->full_name,
                'name_code' => $staff?->name_code,
            ],
        ]);

        if ($request->boolean('remember')) {
            $this->rememberMe->issue($response, (int) $user->id);
        }

        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $response = response()->json(['status' => 'success', 'message' => 'Logged out.']);
        $this->rememberMe->clear($response, $request);

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('id', $request->session()->getId())->delete();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $response;
    }

    public function session(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'csrf_token' => $request->session()->token(),
            'user' => [
                'staff_id' => $request->session()->get('staff_id'),
                'email' => $request->session()->get('email'),
                'roles' => $request->session()->get('roles', []),
                'full_name' => $request->session()->get('full_name'),
                'name_code' => $request->session()->get('name_code'),
            ],
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:12', 'max:128'],
            'confirmPassword' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Review the highlighted fields before saving.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->input('newPassword') !== $request->input('confirmPassword')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Passwords do not match.',
                'errors' => ['confirmPassword' => ['Passwords do not match.']],
            ], 422);
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $user = DB::table('system_users')
            ->where('staff_id', $staffId)
            ->select('id', 'password_hash')
            ->first();

        if (! $user || ! password_verify($request->input('currentPassword'), $user->password_hash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Current password is incorrect.',
                'errors' => ['currentPassword' => ['Current password is incorrect.']],
            ], 422);
        }

        DB::table('system_users')
            ->where('id', $user->id)
            ->update([
                'password_hash' => password_hash($request->input('newPassword'), PASSWORD_BCRYPT),
                'updated_at' => now(),
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

    public function requestPasswordReset(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim((string) $request->input('email')));
        $user = SystemUser::where('email', $email)
            ->where('is_active', 1)
            ->first();

        if ($user) {
            $plainToken = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                [
                    'token' => hash('sha256', $plainToken),
                    'created_at' => now(),
                ],
            );

            $resetUrl = $this->passwordResetUrl($request, $plainToken, $email);
            $recipientName = '';
            if (Schema::hasTable('staff_general')) {
                $staff = $user->staffProfile;
                $recipientName = trim((string) ($staff?->full_name ?? $staff?->name_code ?? ''));
            }

            Mail::to($email, $recipientName !== '' ? $recipientName : null)
                ->send(new PasswordResetMail($resetUrl, $recipientName !== '' ? $recipientName : 'there'));
        }

        return response()->json([
            'status' => 'success',
            'message' => 'If an active account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:12', 'max:128'],
            'confirmPassword' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Review the highlighted fields before saving.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->input('newPassword') !== $request->input('confirmPassword')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Passwords do not match.',
                'errors' => ['confirmPassword' => ['Passwords do not match.']],
            ], 422);
        }

        $email = strtolower(trim((string) $request->input('email')));
        $tokenHash = hash('sha256', (string) $request->input('token'));

        $reset = DB::table('password_reset_tokens')->where('email', $email)->first();
        if (
            ! $reset ||
            ! hash_equals((string) $reset->token, $tokenHash) ||
            Carbon::parse($reset->created_at)->lt(now()->subMinutes(60))
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'This password reset link is invalid or has expired.',
            ], 422);
        }

        $user = DB::table('system_users')
            ->where('email', $email)
            ->where('is_active', 1)
            ->select('id')
            ->first();

        if (! $user) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return response()->json([
                'status' => 'error',
                'message' => 'This password reset link is invalid or has expired.',
            ], 422);
        }

        $updates = [
            'password_hash' => password_hash($request->input('newPassword'), PASSWORD_BCRYPT),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('system_users', 'failed_attempts')) {
            $updates['failed_attempts'] = 0;
        }
        if (Schema::hasColumn('system_users', 'account_locked_until')) {
            $updates['account_locked_until'] = null;
        }

        DB::table('system_users')->where('id', $user->id)->update($updates);

        DB::table('password_reset_tokens')->where('email', $email)->delete();
        $this->invalidateAllSessions((int) $user->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset successfully. You can now sign in with your new password.',
        ]);
    }

    private function clientIp(Request $request): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            $value = $_SERVER[$header] ?? null;
            if ($value) {
                foreach (explode(',', $value) as $part) {
                    $ip = trim($part);
                    if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        return $request->ip() ?? '0.0.0.0';
    }

    private function rateLimitedResponse(int $blockedUntilTs, int $nowTs): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Too many login attempts. Please try again later.',
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
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')
                ->where('user_id', $userId)
                ->where('id', '<>', $request->session()->getId())
                ->delete();
        }

        if (Schema::hasTable('auth_remember_tokens')) {
            DB::table('auth_remember_tokens')->where('user_id', $userId)->delete();
        }
    }

    private function invalidateAllSessions(int $userId): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')
                ->where('user_id', $userId)
                ->delete();
        }

        if (Schema::hasTable('auth_remember_tokens')) {
            DB::table('auth_remember_tokens')->where('user_id', $userId)->delete();
        }
    }

    private function passwordResetUrl(Request $request, string $token, string $email): string
    {
        $baseUrl = $this->passwordResetBaseUrl($request);

        return $baseUrl.'/reset-password/'.$token.'?email='.rawurlencode($email);
    }

    private function passwordResetBaseUrl(Request $request): string
    {
        $configuredUrl = trim((string) config('app.frontend_url', ''));
        if ($configuredUrl !== '') {
            return rtrim($configuredUrl, '/');
        }

        if (app()->environment(['local', 'testing'])) {
            $requestOrigin = $this->originFromUrl((string) $request->headers->get('Origin', ''));
            if ($requestOrigin !== null) {
                return $requestOrigin;
            }

            $refererOrigin = $this->originFromUrl((string) $request->headers->get('Referer', ''));
            if ($refererOrigin !== null) {
                return $refererOrigin;
            }
        }

        return $this->originFromUrl((string) config('app.url', 'http://localhost')) ?? 'http://localhost';
    }

    private function originFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}
