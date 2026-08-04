<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Receivables\ReceivablePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceivablePaymentController extends Controller
{
    public function __construct(private ReceivablePaymentService $paymentService) {}

    public function index(Request $request, string $source, int $id): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->paymentService->history($source, $id, $validated['as_of_date'] ?? null);
    }

    public function store(Request $request, string $source, int $id): JsonResponse
    {
        return $this->paymentService->recordPayment($request, $source, $id);
    }

    public function reverse(Request $request, int $paymentId): JsonResponse
    {
        return $this->paymentService->reversePayment($request, $paymentId);
    }
}
