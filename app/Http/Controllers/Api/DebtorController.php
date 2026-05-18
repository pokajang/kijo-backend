<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Debtors\DebtorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DebtorController extends Controller
{
    public function __construct(private DebtorService $debtorService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->debtorService->index($request);
    }

    public function showManual(Request $request, int $id): JsonResponse
    {
        return $this->debtorService->showManual($request, $id);
    }

    public function storeManual(Request $request): JsonResponse
    {
        return $this->debtorService->storeManual($request);
    }

    public function updateManual(Request $request, int $id): JsonResponse
    {
        return $this->debtorService->updateManual($request, $id);
    }

    public function markManualPaid(Request $request, int $id): JsonResponse
    {
        return $this->debtorService->markManualPaid($request, $id);
    }

    public function markManualOpen(Request $request, int $id): JsonResponse
    {
        return $this->debtorService->markManualOpen($request, $id);
    }

    public function destroyManual(Request $request, int $id): JsonResponse
    {
        return $this->debtorService->destroyManual($request, $id);
    }

    public function manualAttachment(int $id)
    {
        return $this->debtorService->manualAttachment($id);
    }
}
