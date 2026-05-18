<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProgressRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $routeProjectId = $this->route('id');
        $routeProgressId = $this->route('progressId');
        $merge = [];

        if ($routeProjectId !== null) {
            $merge['project_id'] = $routeProjectId;
        }

        if ($routeProgressId !== null) {
            $merge['progress_id'] = $routeProgressId;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'progress_id' => ['required', 'integer', 'min:1'],
            'project_id'  => ['required', 'integer', 'min:1'],
            'date'        => ['required', 'date_format:Y-m-d'],
            'update'      => ['required', 'string', 'max:5000'],
        ];
    }
}
