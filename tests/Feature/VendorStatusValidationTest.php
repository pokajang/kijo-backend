<?php

namespace Tests\Feature;

use App\Http\Requests\Vendor\StoreVendorRequest;
use App\Http\Requests\Vendor\UpdateVendorRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class VendorStatusValidationTest extends TestCase
{
    public function test_store_vendor_status_rejects_deleted_value_not_in_db_enum(): void
    {
        $request = new StoreVendorRequest();
        $validator = Validator::make(
            $this->vendorPayload(['status' => 'Deleted']),
            $request->rules()
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    public function test_update_vendor_status_allows_active_and_inactive_only(): void
    {
        $request = new UpdateVendorRequest();

        $active = Validator::make($this->vendorPayload(['status' => 'Active']), $request->rules());
        $inactive = Validator::make($this->vendorPayload(['status' => 'Inactive']), $request->rules());
        $deleted = Validator::make($this->vendorPayload(['status' => 'Deleted']), $request->rules());

        $this->assertFalse($active->fails());
        $this->assertFalse($inactive->fails());
        $this->assertTrue($deleted->fails());
    }

    private function vendorPayload(array $overrides = []): array
    {
        return array_merge([
            'vendorName' => 'Vendor A',
            'mobileNumber' => '60123456789',
            'bankName' => 'Test Bank',
            'bankAccountNumber' => '123456789',
            'bankHolderName' => 'Vendor A Sdn Bhd',
        ], $overrides);
    }
}
