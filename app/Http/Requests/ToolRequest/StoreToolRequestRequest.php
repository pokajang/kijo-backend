<?php

namespace App\Http\Requests\ToolRequest;

use Illuminate\Foundation\Http\FormRequest;

class StoreToolRequestRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'equipmentDetail' => ['required', 'string', 'max:500'],
            'useStartDate'    => ['required', 'date_format:Y-m-d'],
            'useEndDate'      => ['required', 'date_format:Y-m-d', 'after_or_equal:useStartDate'],
            'purpose'         => ['required', 'string', 'max:2000'],
            'remarks'         => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'useEndDate.after_or_equal' => 'End date must be on or after start date.',
        ];
    }
}
