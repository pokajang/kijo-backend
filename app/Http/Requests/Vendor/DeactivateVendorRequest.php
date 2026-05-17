<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class DeactivateVendorRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'vendor_id'     => ['sometimes', 'integer', 'min:1'],
            'delete_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

