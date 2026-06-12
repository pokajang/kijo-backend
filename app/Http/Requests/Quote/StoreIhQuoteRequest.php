<?php

namespace App\Http\Requests\Quote;

use Illuminate\Foundation\Http\FormRequest;

class StoreIhQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id'          => ['required', 'integer'],
            'client_name'        => ['required', 'string', 'max:255'],
            'client_ssm'         => ['nullable', 'string', 'max:255'],
            'client_address'     => ['required', 'string', 'max:255'],
            'client_city'        => ['nullable', 'string', 'max:255'],
            'client_state'       => ['nullable', 'string', 'max:255'],
            'client_zip'         => ['nullable', 'string', 'max:255'],
            'pic_name'           => ['required', 'string', 'max:2000'],
            'pic_email'          => ['required', 'string', 'max:2000'],
            'pic_phone'          => ['required', 'string', 'max:2000'],
            'pic_position'       => ['required', 'string', 'max:2000'],
            'service_id'         => ['required', 'integer'],
            'service_title'      => ['required', 'string', 'max:255'],
            'service_code'       => ['required', 'string', 'max:255'],
            'site_address'       => ['nullable', 'string', 'max:1000'],
            'travel_charge'      => ['nullable', 'numeric', 'min:0'],
            'sample_counts'      => ['nullable', 'numeric', 'min:0'],
            'sample_unit'        => ['nullable', 'string', 'max:100'],
            'num_work_units'     => ['nullable', 'numeric', 'min:0'],
            'unit_price'         => ['nullable', 'numeric', 'min:0'],
            'discount'           => ['nullable', 'numeric', 'min:0'],
            'sst_percent'        => ['nullable', 'numeric', 'min:0'],
            'sst_amount'         => ['nullable', 'numeric', 'min:0'],
            'sub_total'          => ['nullable', 'numeric', 'min:0'],
            'grand_total'        => ['nullable', 'numeric', 'min:0'],
            'hygiene_items'      => ['nullable', 'array'],
            'hygiene_items.*' => ['array'],
            'hygiene_items.*.item_description' => ['required_with:hygiene_items', 'string', 'max:255'],
            'hygiene_items.*.description' => ['nullable', 'string', 'max:1000'],
            'hygiene_items.*.quantity' => ['required_with:hygiene_items', 'numeric', 'min:0.01'],
            'hygiene_items.*.unit' => ['nullable', 'string', 'max:50'],
            'hygiene_items.*.unit_price' => ['required_with:hygiene_items', 'numeric', 'min:0.01'],
            'hygiene_items.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'inquiry_remarks'    => ['nullable', 'string', 'max:2000'],
            'attach_proposal'    => ['nullable', 'boolean'],
            'proposal_language'  => ['nullable', 'in:en,ms-MY'],
        ];
    }
}
