<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

class LeaveActionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id'      => ['required', 'integer', 'min:1'],
            'action'  => ['required', 'string', 'in:recommend,approve,reject'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
