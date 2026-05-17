<?php

namespace App\Http\Requests\ProposalTemplate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpecialProposalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $removeAttachmentIds = $this->input('removeAttachmentIds', $this->input('remove_attachment_ids'));
        if (!is_array($removeAttachmentIds) && $this->has('removeAttachmentIds')) {
            $removeAttachmentIds = (array) $this->input('removeAttachmentIds');
        }

        $this->merge([
            'serviceTitle'        => $this->input('serviceTitle', $this->input('service_title')),
            'serviceCode'         => $this->input('serviceCode', $this->input('service_code')),
            'remarks'             => $this->input('remarks', $this->input('remark')),
            'removeAttachmentIds' => $removeAttachmentIds,
        ]);
    }

    public function rules(): array
    {
        return [
            'serviceTitle'          => ['required', 'string', 'max:255'],
            'serviceCode'           => ['required', 'string', 'max:100'],
            'content'               => ['required', 'string'],
            'remarks'               => ['nullable', 'string', 'max:1000'],
            'attachments'           => ['nullable', 'array'],
            'attachments.*'         => ['file', 'mimes:pdf,jpeg,png', 'max:10240'],
            'removeAttachmentIds'   => ['nullable', 'array'],
            'removeAttachmentIds.*' => ['integer'],
        ];
    }
}
