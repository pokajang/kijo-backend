<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'fullName' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'mobileNumber' => ['sometimes', 'nullable', 'string', 'max:30'],
            'nameCode' => ['sometimes', 'nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'crmPosition' => ['sometimes', 'nullable', 'string', 'max:150'],

            'birthDate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'nric' => ['sometimes', 'nullable', 'string', 'max:40'],
            'currentAddress' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'emergencyName1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergencyRelationship1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergencyPhone1' => ['sometimes', 'nullable', 'string', 'max:30'],
            'emergencyAddress1' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'emergencyName2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergencyRelationship2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergencyPhone2' => ['sometimes', 'nullable', 'string', 'max:30'],
            'emergencyAddress2' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'chronicIllness' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'allergies' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'disabilities' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'currentMedication' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'otherConcerns' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $nameCode = $this->input('nameCode');

        if ($nameCode !== null && trim((string) $nameCode) !== '') {
            $this->merge(['nameCode' => strtoupper(trim((string) $nameCode))]);
        }
    }
}
