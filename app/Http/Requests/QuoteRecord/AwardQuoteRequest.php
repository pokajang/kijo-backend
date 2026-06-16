<?php

namespace App\Http\Requests\QuoteRecord;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AwardQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'award_date' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:2000'],
            'client_award_ref_no' => ['nullable', 'string', 'max:255'],
            'project_value_decision' => ['nullable', 'string', 'in:default,adjusted'],
            'current_project_value' => ['required_if:project_value_decision,adjusted', 'nullable', 'numeric', 'min:0'],
            'project_value_reason' => ['required_if:project_value_decision,adjusted', 'nullable', 'string', 'max:5000'],
            'project_collaborators' => ['nullable', 'array'],
            'project_collaborators.*.staff_id' => ['required_with:project_collaborators', 'integer', 'min:1'],
            'project_collaborators.*.project_role' => ['required_with:project_collaborators', 'string', 'in:Leader,Assistant,Collaborator'],
            'project_collaborators.*.role_description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $collaborators = $this->input('project_collaborators', []);
            if (! is_array($collaborators) || empty($collaborators)) {
                return;
            }

            $leaderCount = 0;
            foreach ($collaborators as $collaborator) {
                if (is_array($collaborator) && ($collaborator['project_role'] ?? null) === 'Leader') {
                    $leaderCount++;
                }
            }

            if ($leaderCount !== 1) {
                $validator->errors()->add('project_collaborators', 'Exactly one project Leader must be assigned.');
            }
        });
    }
}
