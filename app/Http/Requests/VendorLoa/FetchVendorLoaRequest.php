<?php

namespace App\Http\Requests\VendorLoa;

use Illuminate\Foundation\Http\FormRequest;

class FetchVendorLoaRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'year'     => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
