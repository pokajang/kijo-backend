<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class CloseProjectRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'closeDate'  => ['required', 'date_format:Y-m-d'],
            'closeType'  => ['required', 'string', 'in:Completed,Terminated'],
            'reason'     => ['required', 'string', 'max:2000'],
            'claims'     => ['nullable', 'boolean'],
            'vendors'    => ['nullable', 'boolean'],
            'services'   => ['nullable', 'boolean'],
        ];
    }
}
