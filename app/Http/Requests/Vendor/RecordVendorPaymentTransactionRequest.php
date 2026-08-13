<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class RecordVendorPaymentTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', 'string', 'max:100'],
            'reference_number' => ['required', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'proof' => ['nullable', 'file', 'mimes:pdf,jpeg,jpg,png', 'max:5120'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
