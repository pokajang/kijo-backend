<?php

namespace App\Http\Requests\QuoteRecord;

use Illuminate\Foundation\Http\FormRequest;

class SpecialLineItemsByServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
