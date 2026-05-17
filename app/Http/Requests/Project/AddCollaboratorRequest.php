<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class AddCollaboratorRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'project_id'       => ['required', 'integer', 'min:1'],
            'staff_id'         => ['required', 'integer', 'min:1'],
            'project_role'     => ['nullable', 'string', 'max:100'],
            'role_description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
