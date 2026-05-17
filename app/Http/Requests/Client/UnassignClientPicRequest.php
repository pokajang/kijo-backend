<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class UnassignClientPicRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        $this->merge([
            'company_id' => $this->route('companyId') ?? $this->route('company_id') ?? $payload['company_id'] ?? $payload['companyId'] ?? null,
            'pic_id' => $this->route('picId') ?? $this->route('pic_id') ?? $payload['pic_id'] ?? $payload['picId'] ?? null,
        ]);
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'min:1'],
            'pic_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
