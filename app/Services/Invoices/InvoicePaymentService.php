<?php

namespace App\Services\Invoices;

use App\Services\Receivables\ReceivablePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoicePaymentService
{
    public function __construct(private ReceivablePaymentService $paymentService) {}

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

        return $this->paymentService->recordPayment($request, 'invoice', $id, true);
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

        $request->merge(['reason' => $request->input('reason', 'Reopened through legacy mark-unpaid action')]);

        return $this->paymentService->reverseAllPayments($request, 'invoice', $id);
    }
}
