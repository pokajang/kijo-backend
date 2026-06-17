<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Salary\UpdateSalaryProfileRequest;
use App\Services\Salary\OtherClaimService;
use App\Services\Salary\PaymentQueueService;
use App\Services\Salary\SalaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryController extends Controller
{
    public function __construct(
        private SalaryService $salaryService,
        private OtherClaimService $otherClaimService,
        private PaymentQueueService $paymentQueueService,
    ) {}

    public function profile(Request $request): JsonResponse
    {
        return $this->salaryService->profile($request);
    }

    public function updateProfile(UpdateSalaryProfileRequest $request): JsonResponse
    {
        return $this->salaryService->updateProfile($request);
    }

    public function records(Request $request): JsonResponse
    {
        return $this->salaryService->records($request);
    }

    public function financialRecords(Request $request): JsonResponse
    {
        return $this->salaryService->financialRecords($request);
    }

    public function financialRecordAction(Request $request, int $id): JsonResponse
    {
        return $this->salaryService->financialRecordAction($request, $id);
    }

    public function record(Request $request, int $id): JsonResponse
    {
        return $this->salaryService->record($request, $id);
    }

    public function claimsPdf(Request $request, int $id)
    {
        return $this->salaryService->claimsPdf($request, $id);
    }

    public function financialClaimsPdf(Request $request, int $id)
    {
        return $this->salaryService->financialClaimsPdf($request, $id);
    }

    public function payslipPdf(Request $request, int $id)
    {
        return $this->salaryService->payslipPdf($request, $id);
    }

    public function financialPayslipPdf(Request $request, int $id)
    {
        return $this->salaryService->financialPayslipPdf($request, $id);
    }

    public function destroyRecord(Request $request, int $id): JsonResponse
    {
        return $this->salaryService->destroyRecord($request, $id);
    }

    public function draftApplication(Request $request): JsonResponse
    {
        return $this->salaryService->draftApplication($request);
    }

    public function storeDraftApplication(Request $request): JsonResponse
    {
        return $this->salaryService->storeDraftApplication($request);
    }

    public function destroyDraftApplication(Request $request): JsonResponse
    {
        return $this->salaryService->destroyDraftApplication($request);
    }

    public function storeApplication(Request $request): JsonResponse
    {
        return $this->salaryService->storeApplication($request);
    }

    public function attachment(Request $request, int $id)
    {
        return $this->salaryService->attachment($request, $id);
    }

    public function paymentQueue(Request $request): JsonResponse
    {
        return $this->paymentQueueService->queue($request);
    }

    public function paymentQueueDetail(Request $request, int $staffId, string $period): JsonResponse
    {
        return $this->paymentQueueService->detail($request, $staffId, $period);
    }

    public function markPaymentQueuePaid(Request $request): JsonResponse
    {
        return $this->paymentQueueService->markPaid($request);
    }

    public function otherClaimRecords(Request $request): JsonResponse
    {
        return $this->otherClaimService->records($request);
    }

    public function otherClaimFinancialRecords(Request $request): JsonResponse
    {
        return $this->otherClaimService->financialRecords($request);
    }

    public function otherClaimFinancialRecordAction(Request $request, int $id): JsonResponse
    {
        return $this->otherClaimService->financialRecordAction($request, $id);
    }

    public function otherClaimRecord(Request $request, int $id): JsonResponse
    {
        return $this->otherClaimService->record($request, $id);
    }

    public function otherClaimClaimsPdf(Request $request, int $id)
    {
        return $this->otherClaimService->claimsPdf($request, $id);
    }

    public function otherClaimFinancialClaimsPdf(Request $request, int $id)
    {
        return $this->otherClaimService->financialClaimsPdf($request, $id);
    }

    public function destroyOtherClaimRecord(Request $request, int $id): JsonResponse
    {
        return $this->otherClaimService->destroyRecord($request, $id);
    }

    public function otherClaimDraftApplication(Request $request): JsonResponse
    {
        return $this->otherClaimService->draftApplication($request);
    }

    public function storeOtherClaimDraftApplication(Request $request): JsonResponse
    {
        return $this->otherClaimService->storeDraftApplication($request);
    }

    public function destroyOtherClaimDraftApplication(Request $request): JsonResponse
    {
        return $this->otherClaimService->destroyDraftApplication($request);
    }

    public function storeOtherClaimApplication(Request $request): JsonResponse
    {
        return $this->otherClaimService->storeApplication($request);
    }

    public function otherClaimAttachment(Request $request, int $id)
    {
        return $this->otherClaimService->attachment($request, $id);
    }
}
