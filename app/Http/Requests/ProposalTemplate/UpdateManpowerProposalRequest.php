<?php

namespace App\Http\Requests\ProposalTemplate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateManpowerProposalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'serviceTitle'                 => $this->input('serviceTitle', $this->input('service_title')),
            'serviceCode'                  => $this->input('serviceCode', $this->input('service_code')),
            'serviceDeliverables'          => $this->input('serviceDeliverables', $this->input('service_deliverables')),
            'suppliedManpowerDeliverables' => $this->input(
                'suppliedManpowerDeliverables',
                $this->input('supplied_manpower_deliverables')
            ),
            'customSection'                => $this->input('customSection', $this->input('custom_section')),
            'remarks'                      => $this->input('remarks', $this->input('remark')),
        ]);
    }

    public function rules(): array
    {
        return [
            'serviceTitle'                  => ['required', 'string', 'max:255'],
            'serviceCode'                   => ['required', 'string', 'max:100'],
            'introduction'                  => ['required', 'string'],
            'serviceDeliverables'           => ['required', 'string'],
            'suppliedManpowerDeliverables'  => ['nullable', 'string'],
            'customSection'                 => ['nullable', 'string'],
            'remarks'                       => ['nullable', 'string', 'max:1000'],
        ];
    }
}
