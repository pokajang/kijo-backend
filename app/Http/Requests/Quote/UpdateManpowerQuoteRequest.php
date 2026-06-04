<?php

namespace App\Http\Requests\Quote;

use Illuminate\Foundation\Http\FormRequest;

class UpdateManpowerQuoteRequest extends FormRequest
{
    use ValidatesManpowerRateFloor;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id'       => ['required', 'integer'],
            'client_name'     => ['required', 'string', 'max:255'],
            'client_ssm'      => ['nullable', 'string', 'max:255'],
            'client_address'  => ['required', 'string', 'max:255'],
            'client_city'     => ['nullable', 'string', 'max:255'],
            'client_state'    => ['nullable', 'string', 'max:255'],
            'client_zip'      => ['nullable', 'string', 'max:255'],
            'pic_name'        => ['required', 'string', 'max:2000'],
            'pic_email'       => ['required', 'string', 'max:2000'],
            'pic_phone'       => ['required', 'string', 'max:2000'],
            'pic_position'    => ['required', 'string', 'max:2000'],
            'mp_id'           => ['required', 'integer'],
            'service_title'   => ['required', 'string', 'max:255'],
            'service_code'    => ['required', 'string', 'max:255'],
            'manpower_rate_type' => $this->manpowerRateTypeRules(),
            'billing_unit'    => ['nullable', 'in:month,hour'],
            'duration_hours'  => ['nullable', 'numeric', 'min:0'],
            'requires_management_approval' => ['nullable', 'boolean'],
            'nature_of_work'  => ['nullable', 'string'],
            'site_location'   => ['nullable', 'string'],
            'duration_months' => ['nullable', 'numeric', 'min:0'],
            'no_of_pax'       => ['nullable', 'integer', 'min:1'],
            'unit_cost'       => ['nullable', 'numeric', 'min:0'],
            'discount'        => ['nullable', 'numeric', 'min:0'],
            'price_exception_request_id' => ['nullable', 'integer', 'min:1'],
            'sst_percent'     => ['nullable', 'numeric', 'min:0'],
            'sst_amount'      => ['nullable', 'numeric', 'min:0'],
            'sub_total'       => ['nullable', 'numeric', 'min:0'],
            'grand_total'     => ['nullable', 'numeric', 'min:0'],
            'inquiry_remarks' => ['nullable', 'string', 'max:2000'],
            'attach_proposal' => ['nullable', 'boolean'],
            'proposal_language' => ['nullable', 'in:en,ms-MY'],
            'isRevision'      => ['nullable', 'boolean'],
        ];
    }
}
