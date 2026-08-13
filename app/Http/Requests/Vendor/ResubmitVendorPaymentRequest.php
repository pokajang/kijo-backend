<?php

namespace App\Http\Requests\Vendor;

class ResubmitVendorPaymentRequest extends UpdateVendorPaymentRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);
    }
}
