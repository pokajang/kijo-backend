<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class DecideVendorPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'integer', 'min:1'],
            'remarks' => ['required', 'string', 'max:2000'],
        ];
    }
}
