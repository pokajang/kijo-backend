<?php

namespace App\Services\Invoices;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceHrdClaimService extends InvoiceBaseService
{

    public function updateHrdClaimRef(Request $request, int $id = 0): JsonResponse
    {
        $bodyId = (int) $request->input('id', 0);
        if ($id > 0 && $bodyId > 0 && $id !== $bodyId) {
            return response()->json(['status' => 'error', 'message' => 'Invoice ID mismatch'], 409);
        }
        $id       = $id > 0 ? $id : $bodyId;
        $claimRef = trim((string) $request->input('hrd_claim_ref', ''));
        $claimRef = $claimRef !== '' ? $claimRef : null;

        if ($id <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or missing invoice ID'], 422);
        }

        try {
            $affected = DB::table('invoices')
                ->where('id', $id)
                ->whereRaw("LOWER(TRIM(service_type)) = 'training'")
                ->whereRaw("LOWER(COALESCE(payment_method, '')) LIKE '%hrd%'")
                ->limit(1)
                ->update(['hrd_claim_ref' => $claimRef, 'updated_at' => now()]);

            if ($affected === 0) {
                return response()->json(['status' => 'error', 'message' => 'Invoice not found or not eligible for HRD claim ref update.'], 422);
            }

            $this->auditLog->log($request, "Updated HRD claim ref for invoice ID {$id}");
            return response()->json(['status' => 'success', 'message' => 'HRD claim reference updated successfully.']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }
}
