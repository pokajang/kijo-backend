<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientFirstTouchDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'claim_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:80'],
            'explanation' => ['required', 'string', 'max:5000'],
            'evidence' => ['sometimes', 'array', 'max:3'],
            'evidence.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ];
    }
}
