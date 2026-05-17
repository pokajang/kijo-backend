<?php

namespace App\Services\Invoices;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    private function invoiceQueryService(): InvoiceQueryService
    {
        return app(InvoiceQueryService::class);
    }

    private function invoiceMutationService(): InvoiceMutationService
    {
        return app(InvoiceMutationService::class);
    }

    private function invoiceHrdClaimService(): InvoiceHrdClaimService
    {
        return app(InvoiceHrdClaimService::class);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->invoiceQueryService()->index($request);
    }

    public function latestByProject(Request $request): JsonResponse
    {
        return $this->invoiceQueryService()->latestByProject($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->invoiceMutationService()->store($request);
    }

    public function update(Request $request): JsonResponse
    {
        return $this->invoiceMutationService()->update($request);
    }

    public function destroy(Request $request): JsonResponse
    {
        return $this->invoiceMutationService()->destroy($request);
    }

    public function updateHrdClaimRef(Request $request, int $id = 0): JsonResponse
    {
        return $this->invoiceHrdClaimService()->updateHrdClaimRef($request, $id);
    }

}
