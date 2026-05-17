<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
use App\Services\Catalog\CatalogService;

class CatalogController extends Controller
{
    private function catalogService(): CatalogService
    {
        return app(CatalogService::class);
    }


        public function index(Request $request)
    {
        return $this->catalogService()->index($request);
    }


        public function show(int $id)
    {
        return $this->catalogService()->show($id);
    }


        public function store(StoreCatalogItemRequest $request)
    {
        return $this->catalogService()->store($request);
    }


        public function update(UpdateCatalogItemRequest $request, ?int $id = null)
    {
        return $this->catalogService()->update($request, $id);
    }


        public function destroy(Request $request, ?int $id = null)
    {
        return $this->catalogService()->destroy($request, $id);
    }


        public function listPurchaseOrders(Request $request)
    {
        return $this->catalogService()->listPurchaseOrders($request);
    }


        public function storePurchaseOrder(StoreSupplierPoRequest $request)
    {
        return $this->catalogService()->storePurchaseOrder($request);
    }


        public function markPurchaseOrderPaid(MarkSupplierPoPaidRequest $request)
    {
        return $this->catalogService()->markPurchaseOrderPaid($request);
    }


        public function destroyPurchaseOrder(Request $request, ?int $poId = null)
    {
        return $this->catalogService()->destroyPurchaseOrder($request, $poId);
    }


        public function purchaseOrderPdf(Request $request, ?int $poId = null)
    {
        return $this->catalogService()->purchaseOrderPdf($request, $poId);
    }

}
