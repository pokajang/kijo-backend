<?php

namespace App\Http\Requests\Signature;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'signature' => ['required', 'file', 'mimes:jpeg,png', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'signature.mimes' => 'Only JPEG/PNG allowed.',
            'signature.max' => 'Signature image must be smaller than 2 MB.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Review the highlighted fields before saving.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
