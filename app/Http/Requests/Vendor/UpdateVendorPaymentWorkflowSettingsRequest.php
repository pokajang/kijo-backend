<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateVendorPaymentWorkflowSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'review_enabled' => ['required', 'boolean'],
            'review_levels' => ['required', 'integer', 'min:0', 'max:5'],
            'approval_enabled' => ['required', 'boolean'],
            'approval_levels' => ['required', 'integer', 'min:0', 'max:5'],
            'stages' => ['nullable', 'array'],
            'stages.*.stage_type' => ['required', 'string', 'in:review,approval,finance'],
            'stages.*.level_no' => ['required', 'integer', 'min:1', 'max:5'],
            'stages.*.recipient_staff_ids' => ['nullable', 'array'],
            'stages.*.recipient_staff_ids.*' => ['integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $reviewEnabled = $this->boolean('review_enabled');
            $approvalEnabled = $this->boolean('approval_enabled');
            $reviewLevels = (int) $this->input('review_levels', 0);
            $approvalLevels = (int) $this->input('approval_levels', 0);

            if ($reviewEnabled && $reviewLevels < 1) {
                $validator->errors()->add('review_levels', 'Review levels must be at least 1 when review is enabled.');
            }

            if ($approvalEnabled && $approvalLevels < 1) {
                $validator->errors()->add('approval_levels', 'Approval levels must be at least 1 when approval is enabled.');
            }

            foreach ((array) $this->input('stages', []) as $index => $stage) {
                if (($stage['stage_type'] ?? null) === 'finance' && (int) ($stage['level_no'] ?? 0) !== 1) {
                    $validator->errors()->add("stages.{$index}.level_no", 'Finance must use level 1.');
                }
            }
        });
    }
}
