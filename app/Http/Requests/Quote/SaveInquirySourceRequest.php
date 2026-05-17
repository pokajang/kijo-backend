<?php

namespace App\Http\Requests\Quote;

use Illuminate\Foundation\Http\FormRequest;

class SaveInquirySourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quote_id'     => ['required', 'integer'],
            'quote_ref_no' => ['nullable', 'string', 'max:100'],
            'client_id'    => ['required', 'integer'],
            'service_type' => [
                'required',
                'string',
                'in:equipment,manpower,ih,special,training,Equipment Supply,Manpower Supply,Industrial Hygiene,Special Service,Training',
            ],
            'source'       => ['required', 'string', 'max:255'],
            'remarks'      => ['nullable', 'string', 'max:1000'],
        ];
    }
}
