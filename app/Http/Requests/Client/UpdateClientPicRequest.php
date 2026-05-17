<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientPicRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        $this->merge([
            'pic_id' => $this->route('picId') ?? $this->route('pic_id') ?? $payload['pic_id'] ?? $payload['picId'] ?? null,
            'full_name' => $payload['full_name'] ?? $payload['fullName'] ?? null,
            'mobile_number' => $payload['mobile_number'] ?? $payload['mobileNumber'] ?? null,
            'company_id' => $payload['company_id'] ?? $payload['companyId'] ?? null,
        ]);
    }

    public function rules(): array
    {
        return [
            'pic_id' => ['required', 'integer', 'min:1'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'mobile_number' => ['nullable', 'string', 'max:50'],
            'position' => ['nullable', 'string', 'max:100'],
            'company_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
