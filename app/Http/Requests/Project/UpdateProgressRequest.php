<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProgressRequest extends FormRequest
{
    public function authorize(): bool { return true; }

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
