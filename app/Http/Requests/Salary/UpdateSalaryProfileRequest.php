<?php

namespace App\Http\Requests\Salary;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalaryProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'effective_month' => ['required', 'date_format:Y-m'],
            'vehicle' => ['nullable', 'string', 'max:120'],
            'default_mileage_rate' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'yearly_medical_claim' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'recurring_allowances' => ['nullable', 'array'],
            'recurring_allowances.*.id' => ['nullable'],
            'recurring_allowances.*.description' => ['required', 'string', 'max:255'],
            'recurring_allowances.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'recurring_allowances.*.start_month' => ['nullable', 'date_format:Y-m'],
        ];
    }
}
