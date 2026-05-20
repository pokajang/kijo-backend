<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkflowRecipientsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'stages' => ['required', 'array'],
            'stages.*' => ['array'],
            'stages.*.*' => ['integer', 'min:1'],
        ];
    }
}
