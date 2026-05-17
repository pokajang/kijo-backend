<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

class AssignEntitlementRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'integer', 'min:1'],
            'year'     => ['required', 'integer', 'min:2020'],
            'type'     => ['required', 'string', 'max:100'],
            'days'     => ['required', 'numeric', 'min:0'],
        ];
    }
}
