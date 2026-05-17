<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'fullName' => ['required', 'string', 'max:255'],
            'nameCode' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'email' => ['required', 'email', 'max:255'],
            'mobileNumber' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:100'],
            'staffType' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:150'],
            'startDate' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', 'max:50'],
            'grantAccess' => ['nullable', 'boolean'],
            'systemRoles' => ['nullable', 'array', 'max:10'],
            'systemRoles.*' => ['string', 'max:80'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $systemRoles = $this->input('systemRoles', []);
        if (!is_array($systemRoles)) {
            $systemRoles = [];
        }

        $this->merge([
            'nameCode' => $this->filled('nameCode') ? strtoupper(trim((string) $this->input('nameCode'))) : null,
            'grantAccess' => filter_var($this->input('grantAccess', false), FILTER_VALIDATE_BOOL),
            'systemRoles' => array_values(array_filter(array_map(
                fn ($role) => trim((string) $role),
                $systemRoles
            ), fn ($role) => $role !== '')),
        ]);
    }
}
