<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class MarkSupplierPoPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'po_id'         => ['required', 'integer', 'min:1'],
            'payment_date'  => ['required', 'date'],
            'remarks'       => ['nullable', 'string', 'max:5000'],
        ];
    }
}
