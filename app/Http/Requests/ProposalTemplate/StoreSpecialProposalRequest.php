<?php

namespace App\Http\Requests\ProposalTemplate;

use Illuminate\Foundation\Http\FormRequest;

class StoreSpecialProposalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'serviceTitle' => $this->input('serviceTitle', $this->input('service_title')),
            'serviceCode'  => $this->input('serviceCode', $this->input('service_code')),
        ]);
    }

    public function rules(): array
    {
        return [
            'serviceTitle'    => ['required', 'string', 'max:255'],
            'serviceCode'     => ['required', 'string', 'max:100'],
            'content'         => ['required', 'string'],
            'remarks'         => ['nullable', 'string', 'max:1000'],
            'attachments'     => ['nullable', 'array'],
            'attachments.*'   => ['file', 'mimes:pdf,jpeg,png', 'max:10240'],
        ];
    }
}
