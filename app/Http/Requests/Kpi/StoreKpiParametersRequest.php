<?php

namespace App\Http\Requests\Kpi;

use Illuminate\Foundation\Http\FormRequest;

class StoreKpiParametersRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            '*.parameter_name' => ['required', 'string', 'max:255'],
            '*.description'    => ['required', 'string'],
            '*.annual_target'  => ['required', 'integer', 'min:0'],
            '*.unit'           => ['required', 'string', 'max:100'],
            '*.weightage'      => ['required', 'numeric', 'min:0', 'max:100'],
            '*.year'           => ['required', 'integer', 'min:2020'],
        ];
    }
}
