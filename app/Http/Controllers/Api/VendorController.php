<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
use App\Services\Vendors\VendorService;

class VendorController extends Controller
{
    private function vendorService(): VendorService
    {
        return app(VendorService::class);
    }


        public function index(ListVendorsRequest $request)
    {
        return $this->vendorService()->index($request);
    }


        public function mainDetails(ListVendorMainDetailsRequest $request)
    {
        return $this->vendorService()->mainDetails($request);
    }


        public function store(StoreVendorRequest $request)
    {
        return $this->vendorService()->store($request);
    }


        public function update(UpdateVendorRequest $request, ?int $id = null)
    {
        return $this->vendorService()->update($request, $id);
    }


        public function deactivate(DeactivateVendorRequest $request, ?int $id = null)
    {
        return $this->vendorService()->deactivate($request, $id);
    }


        public function reactivate(ReactivateVendorRequest $request, ?int $id = null)
    {
        return $this->vendorService()->reactivate($request, $id);
    }


        public function destroy(PermanentDeleteVendorRequest $request, ?int $id = null)
    {
        return $this->vendorService()->destroy($request, $id);
    }


        public function projectVendors(ListProjectVendorsRequest $request)
    {
        return $this->vendorService()->projectVendors($request);
    }


        public function vendorPayments(GetVendorPaymentsRequest $request)
    {
        return $this->vendorService()->vendorPayments($request);
    }


        public function listPayments(ListVendorPaymentsRequest $request)
    {
        return $this->vendorService()->listPayments($request);
    }


        public function storePayment(StoreVendorPaymentRequest $request)
    {
        return $this->vendorService()->storePayment($request);
    }


        public function approvePayment(ApproveVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorService()->approvePayment($request, $id);
    }


        public function deletePayment(DeleteVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorService()->deletePayment($request, $id);
    }

}
