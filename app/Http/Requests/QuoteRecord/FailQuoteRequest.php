<?php

namespace App\Http\Requests\QuoteRecord;

use Illuminate\Foundation\Http\FormRequest;

class FailQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'integer', 'min:1'],
            'remarks'  => ['nullable', 'string', 'max:1000'],
        ];
    }
}
