<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ReactivateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $routeProjectId = $this->route('id');

        if ($routeProjectId !== null) {
            $this->merge(['project_id' => $routeProjectId]);
        }

        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
