<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class AssignVendorRequest extends FormRequest
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
            'project_id'          => ['required', 'integer', 'min:1'],
            'vendor_id'           => ['required', 'integer', 'min:1'],
            'award_value'         => ['required', 'numeric', 'min:0.01'],
            'award_date'          => ['required', 'date_format:Y-m-d'],
            'position'            => ['nullable', 'string', 'max:1000'],
            'remarks'             => ['nullable', 'string', 'max:5000'],
            'services_description'=> ['nullable', 'string', 'max:5000'],
            'venue_details'       => ['nullable', 'string', 'max:5000'],
            'fee_breakdown'       => ['nullable', 'string', 'max:5000'],
            'payment_terms'       => ['nullable', 'string', 'max:5000'],
        ];
    }
}
