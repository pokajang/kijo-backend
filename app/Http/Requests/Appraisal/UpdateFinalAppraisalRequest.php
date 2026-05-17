<?php

namespace App\Http\Requests\Appraisal;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFinalAppraisalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'staffId' => ['required', 'integer', 'min:1', 'exists:staff_general,staff_id'],
            'appraisalDate' => ['required', 'date_format:Y-m-d'],
            'workQuality' => ['required', 'integer', 'between:1,5'],
            'teamwork' => ['required', 'integer', 'between:1,5'],
            'leadership' => ['required', 'integer', 'between:1,5'],
            'overallPerformance' => ['required', 'integer', 'between:1,5'],
            'supervisorComments' => ['required', 'string', 'max:5000'],
            'salaryIncrementRecommendation' => ['nullable', 'string', 'max:255'],
            'promotionRecommendation' => ['nullable', 'string', 'max:255'],
        ];
    }
}
