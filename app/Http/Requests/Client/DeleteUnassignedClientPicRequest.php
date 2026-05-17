<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class DeleteUnassignedClientPicRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        $this->merge([
            'pic_id' => $this->route('picId') ?? $this->route('pic_id') ?? $payload['pic_id'] ?? $payload['picId'] ?? null,
        ]);
    }

    public function rules(): array
    {
        return [
            'pic_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
