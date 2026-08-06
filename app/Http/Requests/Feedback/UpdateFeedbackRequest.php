<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['feedback', 'remarks'] as $field) {
            if ($this->has($field) && $this->input($field) !== null) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'feedback' => ['sometimes', 'string', 'max:5000', 'min:1'],
            'status' => ['sometimes', 'string', 'in:Pending,Fixed Pending Pushed,In Progress,Fixed Completed'],
            'resolution_track' => ['sometimes', 'string', 'in:Needs Triage,30-Day Fix,Next Upgrade,Roadmap / Backlog,Not Actionable,Rejected'],
            'action_date' => ['sometimes', 'nullable', 'date'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
