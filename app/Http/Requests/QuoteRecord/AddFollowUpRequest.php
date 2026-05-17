<?php

namespace App\Http\Requests\QuoteRecord;

use Illuminate\Foundation\Http\FormRequest;

class AddFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quote_id'       => ['required', 'integer', 'min:1'],
            'remarks'        => ['required', 'string', 'max:2000'],
            'follow_up_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
