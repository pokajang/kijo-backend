<?php

namespace App\Services\Vendors;

use App\Http\Requests\Vendor\ApproveVendorPaymentRequest;
use App\Http\Requests\Vendor\DeactivateVendorRequest;
use App\Http\Requests\Vendor\DeleteVendorPaymentRequest;
use App\Http\Requests\Vendor\GetVendorPaymentsRequest;
use App\Http\Requests\Vendor\ListProjectVendorsRequest;
use App\Http\Requests\Vendor\ListVendorMainDetailsRequest;
use App\Http\Requests\Vendor\ListVendorsRequest;
use App\Http\Requests\Vendor\ListVendorPaymentsRequest;
use App\Http\Requests\Vendor\PermanentDeleteVendorRequest;
use App\Http\Requests\Vendor\ReactivateVendorRequest;
use App\Http\Requests\Vendor\StoreVendorPaymentRequest;
use App\Http\Requests\Vendor\StoreVendorRequest;
use App\Http\Requests\Vendor\UpdateVendorRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VendorService
{
    private function vendorCrudService(): VendorCrudService
    {
        return app(VendorCrudService::class);
    }

    private function vendorProjectAssignmentService(): VendorProjectAssignmentService
    {
        return app(VendorProjectAssignmentService::class);
    }

    private function vendorPaymentService(): VendorPaymentService
    {
        return app(VendorPaymentService::class);
    }

    public function index(ListVendorsRequest $request)
    {
        return $this->vendorCrudService()->index($request);
    }

    public function mainDetails(ListVendorMainDetailsRequest $request)
    {
        return $this->vendorCrudService()->mainDetails($request);
    }

    public function store(StoreVendorRequest $request)
    {
        return $this->vendorCrudService()->store($request);
    }

    public function update(UpdateVendorRequest $request, ?int $id = null)
    {
        return $this->vendorCrudService()->update($request, $id);
    }

    public function deactivate(DeactivateVendorRequest $request, ?int $id = null)
    {
        return $this->vendorCrudService()->deactivate($request, $id);
    }

    public function reactivate(ReactivateVendorRequest $request, ?int $id = null)
    {
        return $this->vendorCrudService()->reactivate($request, $id);
    }

    public function destroy(PermanentDeleteVendorRequest $request, ?int $id = null)
    {
        return $this->vendorCrudService()->destroy($request, $id);
    }

    public function projectVendors(ListProjectVendorsRequest $request)
    {
        return $this->vendorProjectAssignmentService()->projectVendors($request);
    }

    public function vendorPayments(GetVendorPaymentsRequest $request)
    {
        return $this->vendorPaymentService()->vendorPayments($request);
    }

    public function listPayments(ListVendorPaymentsRequest $request)
    {
        return $this->vendorPaymentService()->listPayments($request);
    }

    public function storePayment(StoreVendorPaymentRequest $request)
    {
        return $this->vendorPaymentService()->storePayment($request);
    }

    public function approvePayment(ApproveVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorPaymentService()->approvePayment($request, $id);
    }

    public function deletePayment(DeleteVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorPaymentService()->deletePayment($request, $id);
    }

}
