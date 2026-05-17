<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeedbackRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'feedback'    => ['sometimes', 'string', 'max:5000', 'min:1'],
            'status'      => ['sometimes', 'string', 'in:Pending,In Progress,Resolved'],
            'action_date' => ['sometimes', 'nullable', 'date'],
            'remarks'     => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
