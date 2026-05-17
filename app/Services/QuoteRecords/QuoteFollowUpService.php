<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class QuoteFollowUpService
{
    public function __construct(private AuditLogService $auditLog) {}

    public function add(
        AddFollowUpRequest $request,
        string $quoteTable,
        string $quoteType,
        string $auditLabel,
        bool $requireAuthenticatedStaff = false,
        string $notFoundMessage = 'Quote not found',
        string $successMessage = 'Follow-up record added successfully'
    ): JsonResponse {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($requireAuthenticatedStaff && $staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        $quoteId = (int) $request->input('quote_id');
        $remarks = trim((string) $request->input('remarks', ''));
        $followUpDate = $request->input('follow_up_date');

        $exists = DB::table($quoteTable)->where('id', $quoteId)->exists();
        if (!$exists) {
            return response()->json(['status' => 'error', 'message' => $notFoundMessage], 404);
        }

        DB::table('quote_followups')->insert([
            'quote_id' => $quoteId,
            'quote_type' => $quoteType,
            'remarks' => $remarks,
            'follow_up_date' => $followUpDate,
            'created_by' => $staffId,
        ]);

        $this->auditLog->log($request, "Added follow-up for {$auditLabel} quote ID #{$quoteId}");

        return response()->json(['status' => 'success', 'message' => $successMessage]);
    }
}
