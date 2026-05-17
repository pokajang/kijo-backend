<?php

namespace App\Http\Requests\Appraisal;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppraisalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'section'   => ['required', 'string', 'max:255'],
            'staffId'   => ['required', 'integer', 'min:1'],
            'eventDate' => ['required', 'date_format:Y-m-d'],
            'input'     => ['required', 'string', 'max:5000'],
        ];
    }
}
