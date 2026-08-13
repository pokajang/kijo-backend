<?php

namespace App\Http\Requests\Vendor;

class UpdateVendorPaymentRequest extends StoreVendorPaymentRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpeg,jpg,png', 'max:5120'],
            'idempotency_key' => ['sometimes', 'string', 'max:120'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
