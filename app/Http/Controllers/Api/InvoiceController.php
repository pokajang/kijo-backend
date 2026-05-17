<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Invoices\InvoicePaymentService;
use App\Services\Invoices\InvoicePdfService;
use App\Services\Invoices\InvoiceQuoteLookupService;
use App\Services\Invoices\InvoiceService;
use App\Services\Invoices\Jd14Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    private function pdfService(): InvoicePdfService
    {
        return app(InvoicePdfService::class);
    }

    private function jd14Service(): Jd14Service
    {
        return app(Jd14Service::class);
    }

    private function paymentService(): InvoicePaymentService
    {
        return app(InvoicePaymentService::class);
    }

    private function quoteLookupService(): InvoiceQuoteLookupService
    {
        return app(InvoiceQuoteLookupService::class);
    }

    private function invoiceService(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    // Invoices

    public function index(Request $request): JsonResponse
    {
        return $this->invoiceService()->index($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->invoiceService()->store($request);
    }

    public function update(Request $request): JsonResponse
    {
        return $this->invoiceService()->update($request);
    }

    public function destroy(Request $request): JsonResponse
    {
        return $this->invoiceService()->destroy($request);
    }

    public function markPaid(Request $request, int $id = 0): JsonResponse
    {
        return $this->paymentService()->markPaid($request, $id);
    }

    public function markUnpaid(Request $request, int $id = 0): JsonResponse
    {
        return $this->paymentService()->markUnpaid($request, $id);
    }

    public function latestByProject(Request $request): JsonResponse
    {
        return $this->invoiceService()->latestByProject($request);
    }

    public function updateHrdClaimRef(Request $request, int $id = 0): JsonResponse
    {
        return $this->invoiceService()->updateHrdClaimRef($request, $id);
    }

    public function invoicePdf(Request $request, int $id = 0)
    {
        return $this->pdfService()->invoicePdf($request, $id);
    }

    public function receiptPdf(Request $request, int $id = 0)
    {
        return $this->pdfService()->receiptPdf($request, $id);
    }

    // Quote lookups for invoice form population

    public function quoteTraining(Request $request, int $id): JsonResponse
    {
        return $this->quoteLookupService()->quoteTraining($request, $id);
    }

    public function quoteEquipment(Request $request, int $id): JsonResponse
    {
        return $this->quoteLookupService()->quoteEquipment($request, $id);
    }

    public function quoteManpower(Request $request, int $id): JsonResponse
    {
        return $this->quoteLookupService()->quoteManpower($request, $id);
    }

    public function quoteIh(Request $request, int $id): JsonResponse
    {
        return $this->quoteLookupService()->quoteIh($request, $id);
    }

    public function quoteSpecial(Request $request, int $id): JsonResponse
    {
        return $this->quoteLookupService()->quoteSpecial($request, $id);
    }
    // JD14 forms

    public function listJd14(Request $request): JsonResponse
    {
        return $this->jd14Service()->listJd14($request);
    }

    public function storeJd14(Request $request): JsonResponse
    {
        return $this->jd14Service()->storeJd14($request);
    }

    public function updateJd14(Request $request, int $id): JsonResponse
    {
        return $this->jd14Service()->updateJd14($request, $id);
    }

    public function destroyJd14(Request $request, int $id = 0): JsonResponse
    {
        return $this->jd14Service()->destroyJd14($request, $id);
    }

    public function jd14ByProject(Request $request): JsonResponse
    {
        return $this->jd14Service()->jd14ByProject($request);
    }

    public function jd14Pdf(Request $request)
    {
        return $this->jd14Service()->jd14Pdf($request);
    }
}
