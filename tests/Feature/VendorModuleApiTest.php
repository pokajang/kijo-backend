<?php

namespace Tests\Feature;

use App\Http\Requests\Vendor\StoreVendorPaymentRequest;
use App\Http\Requests\VendorLoa\UpdateVendorLoaPaymentStatusRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class VendorModuleApiTest extends TestCase
{
    public function test_vendor_loa_paid_request_requires_yyyy_mm_dd_date(): void
    {
        $request = new UpdateVendorLoaPaymentStatusRequest();
        $rules = $request->rules();

        $fails = Validator::make([
            'id'               => 1,
            'vendor_id'        => 2,
            'project_id'       => 3,
            'transaction_date' => '15-01-2026',
        ], $rules)->fails();

        $this->assertTrue($fails);
    }

    public function test_store_vendor_payment_requires_core_fields(): void
    {
        $request = new StoreVendorPaymentRequest();
        $rules = $request->rules();

        $fails = Validator::make([
            'vendor_id' => 0,
            'amount'    => -1,
        ], $rules)->fails();

        $this->assertTrue($fails);
    }
}
