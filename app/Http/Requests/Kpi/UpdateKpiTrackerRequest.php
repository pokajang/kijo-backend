<?php

namespace App\Http\Requests\Kpi;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKpiTrackerRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'kpi_id'  => ['required', 'integer'],
            'month'   => ['required', 'string'],
            'target'  => ['required', 'numeric'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
