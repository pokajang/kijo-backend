<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class AddProgressRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'date'       => ['required', 'date_format:Y-m-d'],
            'update'     => ['required', 'string', 'max:5000'],
        ];
    }
}
