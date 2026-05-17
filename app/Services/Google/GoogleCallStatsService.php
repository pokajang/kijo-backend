<?php

namespace App\Services\Google;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoogleCallStatsService extends GoogleBaseService
{

    public function callStatistics(Request $request): JsonResponse
    {
        try {
            $rows = DB::select("
                SELECT
                    called_by_code AS name_code,
                    DATE_FORMAT(COALESCE(called_at, created_at), '%Y-%m') AS month_label,
                    COUNT(*) AS total_calls
                FROM google_call_records
                GROUP BY called_by_code, month_label
                ORDER BY month_label DESC, called_by_code
            ");

            $outcomes = DB::select("
                SELECT outcome, COUNT(*) AS total
                FROM google_call_records
                GROUP BY outcome
                ORDER BY total DESC
            ");

            return response()->json(['rows' => $rows, 'outcomes' => $outcomes]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['error' => 'Unable to load statistics right now.'], 500);
        }
    }
}
