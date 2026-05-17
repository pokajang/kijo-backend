<?php

namespace App\Http\Requests\DeliveryOrder;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeliveryOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'details'                          => ['required', 'array'],
            'details.client_name'              => ['required', 'string', 'max:255'],
            'details.client_address'           => ['required', 'string', 'max:1000'],
            'details.client_contact_name'      => ['required', 'string', 'max:2000'],
            'details.client_contact_position'  => ['required', 'string', 'max:2000'],
            'details.client_contact_email'     => ['required', 'string', 'max:2000'],
            'details.client_contact_phone'     => ['required', 'string', 'max:2000'],
            'details.company_contact_name'     => ['required', 'string', 'max:255'],
            'details.company_contact_email'    => ['nullable', 'email', 'max:255'],
            'details.company_contact_phone'    => ['nullable', 'string', 'max:50'],
            'details.project_name'             => ['required', 'string', 'max:255'],
            'details.project_code'             => ['required', 'string', 'max:100'],
            'details.project_award_date'       => ['required', 'date'],
            'details.project_type'             => ['nullable', 'string', 'max:100'],
            'details.project_description'      => ['nullable', 'string', 'max:2000'],
            'details.project_service_period'   => ['nullable', 'string', 'max:255'],
            'breakdown'                        => ['sometimes', 'array', 'min:1'],
            'breakdown.*.item_name'            => ['required_with:breakdown', 'string', 'max:255'],
            'breakdown.*.description'          => ['required_with:breakdown', 'string', 'max:1000'],
            'breakdown.*.quantity'             => ['required_with:breakdown', 'numeric', 'min:0'],
            'breakdown.*.unit'                 => ['nullable', 'string', 'max:50'],
        ];
    }
}
