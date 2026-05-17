<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LoginRateLimitService
{
    private array $rules = [
        ['scope' => 'ip',       'window_seconds' => 60,   'max_attempts' => 20,  'block_seconds' => 300],
        ['scope' => 'ip',       'window_seconds' => 3600, 'max_attempts' => 120, 'block_seconds' => 3600],
        ['scope' => 'email',    'window_seconds' => 900,  'max_attempts' => 10,  'block_seconds' => 1800],
        ['scope' => 'ip_email', 'window_seconds' => 900,  'max_attempts' => 7,   'block_seconds' => 1800],
    ];

    public function bucketKeys(string $ip, string $email): array
    {
        return [
            'ip'       => hash('sha256', $ip),
            'email'    => hash('sha256', $email),
            'ip_email' => hash('sha256', $ip . '|' . $email),
        ];
    }

    public function getBlockedUntil(array $bucketKeys, int $nowTs): ?int
    {
        $blockedUntilTs = null;

        foreach ($this->rules as $rule) {
            $bucketKey = $bucketKeys[$rule['scope']] ?? null;
            if (!$bucketKey) continue;

            $row = DB::selectOne("
                SELECT blocked_until FROM login_rate_limits
                WHERE scope = ? AND bucket_key = ? AND window_seconds = ?
                  AND blocked_until IS NOT NULL AND blocked_until > NOW()
                ORDER BY blocked_until DESC LIMIT 1
            ", [$rule['scope'], $bucketKey, $rule['window_seconds']]);

            if ($row) {
                $ts = strtotime($row->blocked_until);
                if ($ts && $ts > $nowTs && ($blockedUntilTs === null || $ts > $blockedUntilTs)) {
                    $blockedUntilTs = $ts;
                }
            }
        }

        return $blockedUntilTs;
    }

    public function recordFailure(array $bucketKeys, int $nowTs): ?int
    {
        $blockedUntilTs = null;

        foreach ($this->rules as $rule) {
            $bucketKey = $bucketKeys[$rule['scope']] ?? null;
            if (!$bucketKey) continue;

            $windowStart          = date('Y-m-d H:i:s', (int)(floor($nowTs / $rule['window_seconds']) * $rule['window_seconds']));
            $candidateBlockedUntil = date('Y-m-d H:i:s', $nowTs + $rule['block_seconds']);
            $initialBlockedUntil  = ($rule['max_attempts'] <= 1) ? $candidateBlockedUntil : null;

            DB::statement("
                INSERT INTO login_rate_limits
                    (scope, bucket_key, window_seconds, window_start, attempts, blocked_until)
                VALUES (?, ?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE
                    attempts = attempts + 1,
                    blocked_until = CASE
                        WHEN attempts + 1 >= ? THEN
                            CASE WHEN blocked_until IS NULL OR blocked_until < ? THEN ? ELSE blocked_until END
                        ELSE blocked_until
                    END
            ", [
                $rule['scope'], $bucketKey, $rule['window_seconds'], $windowStart, $initialBlockedUntil,
                $rule['max_attempts'], $candidateBlockedUntil, $candidateBlockedUntil,
            ]);

            $row = DB::selectOne("
                SELECT blocked_until FROM login_rate_limits
                WHERE scope = ? AND bucket_key = ? AND window_seconds = ? AND window_start = ?
                LIMIT 1
            ", [$rule['scope'], $bucketKey, $rule['window_seconds'], $windowStart]);

            if ($row?->blocked_until) {
                $ts = strtotime($row->blocked_until);
                if ($ts && $ts > $nowTs && ($blockedUntilTs === null || $ts > $blockedUntilTs)) {
                    $blockedUntilTs = $ts;
                }
            }
        }

        return $blockedUntilTs;
    }
}
