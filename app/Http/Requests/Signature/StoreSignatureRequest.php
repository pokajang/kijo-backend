<?php

namespace App\Http\Requests\Signature;

use Illuminate\Foundation\Http\FormRequest;

class StoreSignatureRequest extends FormRequest
{
    public function authorize(): bool { return true; }

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
            'signature.max'   => 'Signature image must be smaller than 2 MB.',
        ];
    }
}
