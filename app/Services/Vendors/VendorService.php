<?php

namespace App\Services\Vendors;

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
use App\Http\Requests\Vendor\UpdateVendorPaymentWorkflowSettingsRequest;
use App\Http\Requests\Vendor\UpdateVendorRequest;
use Illuminate\Http\Request;

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

    private function vendorPaymentWorkflowService(): VendorPaymentWorkflowService
    {
        return app(VendorPaymentWorkflowService::class);
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

    public function paidPaymentsByVendor(ListVendorPaymentsRequest $request)
    {
        return $this->vendorPaymentService()->paidPaymentsByVendor($request);
    }

    public function paidPaymentsForVendor(ListVendorPaymentsRequest $request, int $vendorId)
    {
        return $this->vendorPaymentService()->paidPaymentsForVendor($request, $vendorId);
    }

    public function paymentWorkflowSettings(Request $request)
    {
        return $this->vendorPaymentWorkflowService()->index($request);
    }

    public function updatePaymentWorkflowSettings(UpdateVendorPaymentWorkflowSettingsRequest $request)
    {
        return $this->vendorPaymentWorkflowService()->update($request);
    }

    public function storePayment(StoreVendorPaymentRequest $request)
    {
        return $this->vendorPaymentService()->storePayment($request);
    }

    public function checkPayment(CheckVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorPaymentService()->checkPayment($request, $id);
    }

    public function approvePayment(ApproveVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorPaymentService()->approvePayment($request, $id);
    }

    public function rejectPayment(DecideVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorPaymentService()->rejectPayment($request, $id);
    }

    public function returnPayment(DecideVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorPaymentService()->returnPayment($request, $id);
    }

    public function markPaymentPaid(MarkVendorPaymentPaidRequest $request, ?int $id = null)
    {
        return $this->vendorPaymentService()->markPaymentPaid($request, $id);
    }

    public function deletePayment(DeleteVendorPaymentRequest $request, ?int $id = null)
    {
        return $this->vendorPaymentService()->deletePayment($request, $id);
    }
}
