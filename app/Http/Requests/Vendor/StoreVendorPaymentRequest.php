<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorPaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'vendor_id'        => ['required', 'integer', 'min:1'],
            'project_id'       => ['nullable', 'integer', 'min:1'],
            'payment_context'  => ['required', 'string', 'max:100'],
            'payment_type'     => ['required', 'string', 'max:100'],
            'amount'           => ['required', 'numeric', 'min:0'],
            'method'           => ['required', 'string', 'max:100'],
            'remarks'          => ['nullable', 'string', 'max:2000'],
            'reference'        => ['nullable', 'string', 'max:255'],
            'receipt'          => ['nullable', 'file', 'mimes:pdf,jpeg,jpg,png', 'max:5120'],
        ];
    }
}

