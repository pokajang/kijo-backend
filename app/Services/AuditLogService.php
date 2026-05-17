<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogService
{
    public function log(Request $request, string $action): void
    {
        $staffId  = (int) $request->session()->get('staff_id', 0);
        $nameCode = (string) $request->session()->get('name_code', '');

        if ($staffId <= 0 || $nameCode === '') {
            return;
        }

        try {
            DB::table('user_activities')->insert([
                'staff_id'   => $staffId,
                'name_code'  => mb_substr($nameCode, 0, 20),
                'action'     => $action,
                'ip_address' => mb_substr($this->resolveClientIp($request), 0, 45),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function resolveClientIp(Request $request): string
    {
        foreach (['CF-Connecting-IP', 'X-Real-IP', 'X-Forwarded-For'] as $header) {
            $value = $request->header($header);
            if (!$value) {
                continue;
            }

            foreach (explode(',', $value) as $part) {
                $ip = trim($part);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $request->ip() ?? 'UNKNOWN';
    }
}
