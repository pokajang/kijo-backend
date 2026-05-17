<?php

namespace App\Http\Requests\ProposalTemplate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIhProposalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'serviceTitle' => $this->input('serviceTitle', $this->input('service_title')),
            'serviceCode'  => $this->input('serviceCode', $this->input('service_code')),
            'workScope'    => $this->input('workScope', $this->input('work_scope')),
            'otherFields'  => $this->input('otherFields', $this->input('other_fields')),
            'remarks'      => $this->input('remarks', $this->input('remark')),
        ]);
    }

    public function rules(): array
    {
        return [
            'serviceTitle'  => ['required', 'string', 'max:255'],
            'serviceCode'   => ['required', 'string', 'max:100'],
            'introduction'  => ['required', 'string'],
            'objectives'    => ['nullable', 'string'],
            'workScope'     => ['nullable', 'string'],
            'schedule'      => ['nullable', 'string'],
            'reference'     => ['nullable', 'string'],
            'otherFields'   => ['nullable', 'string'],
            'remarks'       => ['nullable', 'string', 'max:1000'],
        ];
    }
}
