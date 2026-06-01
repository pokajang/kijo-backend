<?php

namespace App\Services\Stats;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WorkloadDashboardShareService
{
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private WorkloadDashboardStatsService $workloadStats,
    ) {}

    public function create(Request $request): JsonResponse
    {
        if (! Schema::hasTable('workload_dashboard_shares')) {
            return $this->json([
                'status' => 'error',
                'message' => 'Workload sharing is not available until the latest migration is run.',
            ], 503);
        }

        $payload = $this->workloadStats->workloadPayload($request);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            return $this->json([
                'status' => 'error',
                'message' => 'Unable to create workload share snapshot.',
            ], 500);
        }

        $completedWindow = is_array($payload['completedWindow'] ?? null) ? $payload['completedWindow'] : [];
        $token = $this->newToken();
        $expiresAt = now()->addDays(self::EXPIRY_DAYS);

        DB::table('workload_dashboard_shares')
            ->where('expires_at', '<=', now())
            ->delete();

        DB::table('workload_dashboard_shares')->insert([
            'token_hash' => $this->tokenHash($token),
            'created_by_staff_id' => (int) $request->session()->get('staff_id', 0) ?: null,
            'created_by_code' => (string) $request->session()->get('name_code', ''),
            'start_date' => $this->nullableDate($completedWindow['startDate'] ?? null),
            'end_date' => $this->nullableDate($completedWindow['endDate'] ?? null),
            'payload_json' => $payloadJson,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->json([
            'status' => 'success',
            'token' => $token,
            'path' => "/share/workload/{$token}",
            'expiresAt' => $expiresAt->toIso8601String(),
        ]);
    }

    public function show(string $token): JsonResponse
    {
        if (! Schema::hasTable('workload_dashboard_shares')) {
            return $this->json([
                'status' => 'error',
                'message' => 'Shared workload dashboard not found.',
            ], 404);
        }

        if (! preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token)) {
            return $this->json([
                'status' => 'error',
                'message' => 'Shared workload dashboard not found.',
            ], 404);
        }

        $row = DB::table('workload_dashboard_shares')
            ->where('token_hash', $this->tokenHash($token))
            ->first();

        if (! $row) {
            return $this->json([
                'status' => 'error',
                'message' => 'Shared workload dashboard not found.',
            ], 404);
        }

        if (Carbon::parse($row->expires_at)->lte(now())) {
            return $this->json([
                'status' => 'error',
                'message' => 'This shared workload dashboard has expired.',
            ], 410);
        }

        $payload = json_decode((string) $row->payload_json, true);
        if (! is_array($payload)) {
            return $this->json([
                'status' => 'error',
                'message' => 'Shared workload dashboard is unavailable.',
            ], 500);
        }

        $payload['share'] = [
            'expiresAt' => Carbon::parse($row->expires_at)->toIso8601String(),
            'createdAt' => Carbon::parse($row->created_at)->toIso8601String(),
        ];

        return $this->json($payload);
    }

    private function newToken(): string
    {
        do {
            $token = Str::random(48);
            $exists = DB::table('workload_dashboard_shares')
                ->where('token_hash', $this->tokenHash($token))
                ->exists();
        } while ($exists);

        return $token;
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function nullableDate(mixed $value): ?string
    {
        $text = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) ? $text : null;
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
