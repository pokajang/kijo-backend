<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('message')) {
            $this->merge(['message' => trim((string) $this->input('message'))]);
        }
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['confirm', 'reject'])],
            'message' => [
                Rule::requiredIf(fn (): bool => $this->input('decision') === 'reject'),
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }
}
