<?php

namespace App\Services\Invoices;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoicePaymentService
{
    public function __construct(private AuditLogService $auditLog)
    {
    }

    public function markPaid(Request $request, int $id = 0): JsonResponse
    {
        $bodyId = (int) $request->input('id', 0);
        if ($id > 0 && $bodyId > 0 && $id !== $bodyId) {
            return response()->json(['status' => 'error', 'message' => 'Invoice ID mismatch'], 409);
        }
        $id = $id > 0 ? $id : $bodyId;
        if ($id <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or missing invoice ID'], 422);
        }

        $validated = $request->validate([
            'paid_date' => ['required', 'date_format:Y-m-d'],
            'paid_amount' => ['required', 'numeric', 'gt:0'],
            'paid_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $affected = DB::table('invoices')->where('id', $id)->update([
                'status' => 'Paid',
                'paid_date' => $validated['paid_date'],
                'paid_amount' => $validated['paid_amount'],
                'paid_remarks' => $validated['paid_remarks'] ?? null,
                'updated_at' => now(),
            ]);

            if ($affected < 1) {
                return response()->json(['status' => 'error', 'message' => 'Invoice not found'], 404);
            }

            $this->auditLog->log($request, "Marked invoice ID {$id} as Paid");
            return response()->json(['status' => 'success', 'message' => 'Invoice marked as Paid']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function markUnpaid(Request $request, int $id = 0): JsonResponse
    {
        $bodyId = (int) $request->input('id', 0);
        if ($id > 0 && $bodyId > 0 && $id !== $bodyId) {
            return response()->json(['status' => 'error', 'message' => 'Invoice ID mismatch'], 409);
        }
        $id = $id > 0 ? $id : $bodyId;
        if ($id <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or missing invoice ID'], 422);
        }

        try {
            DB::table('invoices')->where('id', $id)->update([
                'status' => 'Pending',
                'paid_date' => null,
                'paid_amount' => null,
                'paid_remarks' => null,
                'updated_at' => now(),
            ]);

            $this->auditLog->log($request, "Marked invoice ID {$id} as Pending");
            return response()->json(['status' => 'success', 'message' => 'Invoice marked as Pending']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }
}
