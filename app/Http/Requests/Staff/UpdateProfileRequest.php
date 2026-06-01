<?php

namespace App\Http\Requests\Staff;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fullName' => ['required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mobileNumber' => ['required', 'string', 'max:30'],
            'nameCode' => ['sometimes', 'nullable', 'string', 'max:50'],
            'crmPosition' => ['sometimes', 'nullable', 'string', 'max:150'],

            'birthDate' => ['required', 'date_format:Y-m-d'],
            'nric' => ['required', 'string', 'max:40'],
            'currentAddress' => ['required', 'string', 'max:1000'],

            'emergencyName1' => ['required', 'string', 'max:255'],
            'emergencyRelationship1' => ['required', 'string', 'max:255'],
            'emergencyPhone1' => ['required', 'string', 'max:30'],
            'emergencyAddress1' => ['required', 'string', 'max:1000'],

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

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Review the highlighted fields before saving.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
