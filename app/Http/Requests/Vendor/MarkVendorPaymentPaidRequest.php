<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class MarkVendorPaymentPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'integer', 'min:1'],
            'paid_date' => ['required', 'date_format:Y-m-d'],
            'paid_amount' => ['nullable', 'numeric', 'gt:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
