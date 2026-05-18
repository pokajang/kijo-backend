<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class AddExpenseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $routeProjectId = $this->route('id');

        if ($routeProjectId !== null) {
            $this->merge(['project_id' => $routeProjectId]);
        }
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'date'       => ['required', 'date_format:Y-m-d'],
            'amount'     => ['required', 'numeric', 'min:0'],
            'remarks'    => ['nullable', 'string', 'max:2000'],
            'file'       => ['nullable', 'file', 'mimes:pdf,jpeg,jpg,png', 'max:10240'],
        ];
    }
}
