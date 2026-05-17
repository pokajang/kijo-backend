<?php

namespace App\Services\Catalog;

use App\Http\Requests\Catalog\MarkSupplierPoPaidRequest;
use App\Http\Requests\Catalog\StoreCatalogItemRequest;
use App\Http\Requests\Catalog\StoreSupplierPoRequest;
use App\Http\Requests\Catalog\UpdateCatalogItemRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CatalogService
{
    private function catalogItemService(): CatalogItemService
    {
        return app(CatalogItemService::class);
    }

    private function supplierPurchaseOrderService(): SupplierPurchaseOrderService
    {
        return app(SupplierPurchaseOrderService::class);
    }

    private function supplierPurchaseOrderPdfService(): SupplierPurchaseOrderPdfService
    {
        return app(SupplierPurchaseOrderPdfService::class);
    }

    public function index(Request $request)
    {
        return $this->catalogItemService()->index($request);
    }

    public function show(int $id)
    {
        return $this->catalogItemService()->show($id);
    }

    public function store(StoreCatalogItemRequest $request)
    {
        return $this->catalogItemService()->store($request);
    }

    public function update(UpdateCatalogItemRequest $request, ?int $id = null)
    {
        return $this->catalogItemService()->update($request, $id);
    }

    public function destroy(Request $request, ?int $id = null)
    {
        return $this->catalogItemService()->destroy($request, $id);
    }

    public function listPurchaseOrders(Request $request)
    {
        return $this->supplierPurchaseOrderService()->listPurchaseOrders($request);
    }

    public function storePurchaseOrder(StoreSupplierPoRequest $request)
    {
        return $this->supplierPurchaseOrderService()->storePurchaseOrder($request);
    }

    public function markPurchaseOrderPaid(MarkSupplierPoPaidRequest $request)
    {
        return $this->supplierPurchaseOrderService()->markPurchaseOrderPaid($request);
    }

    public function destroyPurchaseOrder(Request $request, ?int $poId = null)
    {
        return $this->supplierPurchaseOrderService()->destroyPurchaseOrder($request, $poId);
    }

    public function purchaseOrderPdf(Request $request, ?int $poId = null)
    {
        return $this->supplierPurchaseOrderPdfService()->purchaseOrderPdf($request, $poId);
    }

}
