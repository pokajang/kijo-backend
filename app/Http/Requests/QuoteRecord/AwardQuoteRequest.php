<?php

namespace App\Http\Requests\QuoteRecord;

use Illuminate\Foundation\Http\FormRequest;

class AwardQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quote_id'            => ['required', 'integer', 'min:1'],
            'remarks'             => ['nullable', 'string', 'max:1000'],
            'award_date'          => ['nullable', 'date_format:Y-m-d'],
            'description'         => ['nullable', 'string', 'max:2000'],
            'client_award_ref_no' => ['nullable', 'string', 'max:255'],
        ];
    }
}
