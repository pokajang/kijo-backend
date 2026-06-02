<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\ApproveVendorPaymentRequest;
use App\Http\Requests\Vendor\CheckVendorPaymentRequest;
use App\Http\Requests\Vendor\DeactivateVendorRequest;
use App\Http\Requests\Vendor\DecideVendorPaymentRequest;
use App\Http\Requests\Vendor\DeleteVendorPaymentRequest;
use App\Http\Requests\Vendor\GetVendorPaymentsRequest;
use App\Http\Requests\Vendor\ListProjectVendorsRequest;
use App\Http\Requests\Vendor\ListVendorMainDetailsRequest;
use App\Http\Requests\Vendor\ListVendorPaymentsRequest;
use App\Http\Requests\Vendor\ListVendorsRequest;
use App\Http\Requests\Vendor\MarkVendorPaymentPaidRequest;
use App\Http\Requests\Vendor\PermanentDeleteVendorRequest;
use App\Http\Requests\Vendor\ReactivateVendorRequest;
use App\Http\Requests\Vendor\StoreVendorPaymentRequest;
use App\Http\Requests\Vendor\StoreVendorRequest;
use App\Http\Requests\Vendor\UpdateVendorRequest;
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

    public function paidPaymentsByVendor(ListVendorPaymentsRequest $request)
    {
        return $this->vendorService()->paidPaymentsByVendor($request);
    }

    public function paidPaymentsForVendor(ListVendorPaymentsRequest $request, int $vendorId)
    {
        return $this->vendorService()->paidPaymentsForVendor($request, $vendorId);
    }

    public function storePayment(StoreVendorPaymentRequest $request)
    {
        return $this->vendorService()->storePayment($request);
    }

    public function checkPayment(CheckVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorService()->checkPayment($request, $id);
    }

    public function approvePayment(ApproveVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorService()->approvePayment($request, $id);
    }

    public function rejectPayment(DecideVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorService()->rejectPayment($request, $id);
    }

    public function returnPayment(DecideVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorService()->returnPayment($request, $id);
    }

    public function markPaymentPaid(MarkVendorPaymentPaidRequest $request, ?int $id = null)
    {
        return $this->vendorService()->markPaymentPaid($request, $id);
    }

    public function deletePayment(DeleteVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorService()->deletePayment($request, $id);
    }
}
