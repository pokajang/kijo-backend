<?php

namespace App\Http\Requests\ProposalTemplate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSpecialProposalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $proposalMode = $this->input('proposalMode', $this->input('proposal_mode', 'upload'));
        $serviceSummary = $this->input('serviceSummary', $this->input('service_summary'));
        $proposalContent = $this->input('proposalContent', $this->input('proposal_content'));
        $defaultLineItems = $this->input('defaultLineItems', $this->input('default_line_items', []));
        if (is_string($defaultLineItems)) {
            $decoded = json_decode($defaultLineItems, true);
            $defaultLineItems = is_array($decoded) ? $decoded : [];
        }

        $content = $this->input('content');
        if ($content === null) {
            $content = $proposalMode === 'write' ? $proposalContent : $serviceSummary;
        }
        if ($proposalMode === 'upload' && $serviceSummary === null) {
            $serviceSummary = $content;
        }
        if ($proposalMode === 'write' && $proposalContent === null) {
            $proposalContent = $content;
        }

        $this->merge([
            'serviceTitle' => $this->input('serviceTitle', $this->input('service_title')),
            'serviceCode'  => $this->input('serviceCode', $this->input('service_code')),
            'proposalMode' => in_array($proposalMode, ['upload', 'write'], true) ? $proposalMode : (string) $proposalMode,
            'serviceSummary' => $serviceSummary,
            'proposalContent' => $proposalContent,
            'content' => $content,
            'defaultLineItems' => $defaultLineItems,
        ]);
    }

    public function rules(): array
    {
        return [
            'serviceTitle'    => ['required', 'string', 'max:255'],
            'serviceCode'     => ['required', 'string', 'max:100'],
            'proposalMode'    => ['required', 'in:upload,write'],
            'content'         => ['nullable', 'string'],
            'serviceSummary'  => ['nullable', 'string'],
            'proposalContent' => ['nullable', 'string'],
            'remarks'         => ['nullable', 'string', 'max:1000'],
            'attachments'     => ['nullable', 'array'],
            'attachments.*'   => ['file', 'mimes:pdf', 'max:10240'],
            'defaultLineItems' => ['nullable', 'array'],
            'defaultLineItems.*.title' => ['nullable', 'string', 'max:255'],
            'defaultLineItems.*.item_name' => ['nullable', 'string', 'max:255'],
            'defaultLineItems.*.description' => ['nullable', 'string', 'max:1000'],
            'defaultLineItems.*.unit' => ['nullable', 'string', 'max:100'],
            'defaultLineItems.*.quantity' => ['nullable', 'numeric', 'min:0.01'],
            'defaultLineItems.*.unitPrice' => ['nullable', 'numeric', 'min:0'],
            'defaultLineItems.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mode = $this->input('proposalMode', 'upload');
            if ($mode === 'write' && trim(strip_tags((string) $this->input('proposalContent', ''))) === '') {
                $validator->errors()->add('proposalContent', 'Proposal content is required in write mode.');
            }

            if ($mode === 'upload') {
                if (! $this->hasFile('attachments')) {
                    $validator->errors()->add('attachments', 'At least one PDF attachment is required in upload mode.');
                }
            }

            foreach ((array) $this->input('defaultLineItems', []) as $index => $item) {
                $title = trim((string) ($item['item_name'] ?? $item['title'] ?? ''));
                if ($title === '') {
                    $validator->errors()->add("defaultLineItems.{$index}.title", 'Default line item title is required.');
                }
            }
        });
    }
}
