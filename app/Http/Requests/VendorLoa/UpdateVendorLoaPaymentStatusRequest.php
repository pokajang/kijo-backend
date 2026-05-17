<?php

namespace App\Http\Requests\VendorLoa;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorLoaPaymentStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id'               => ['required', 'integer', 'min:1'],
            'vendor_id'        => ['required', 'integer', 'min:1'],
            'project_id'       => ['required', 'integer', 'min:1'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}

