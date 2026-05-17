<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'vendorName'             => ['required', 'string', 'max:255'],
            'ssmNumber'              => ['nullable', 'string', 'max:100'],
            'sstNo'                  => ['nullable', 'string', 'max:100'],
            'address'                => ['nullable', 'string', 'max:1000'],
            'city'                   => ['nullable', 'string', 'max:255'],
            'state'                  => ['nullable', 'string', 'max:255'],
            'zip'                    => ['nullable', 'string', 'max:20'],
            'contactPersonName'      => ['nullable', 'string', 'max:255'],
            'mobileNumber'           => ['required', 'string', 'max:50'],
            'email'                  => ['nullable', 'email', 'max:255'],
            'companyWebsite'         => ['nullable', 'string', 'max:255'],
            'emergencyContactName'   => ['nullable', 'string', 'max:255'],
            'emergencyRelationship'  => ['nullable', 'string', 'max:255'],
            'emergencyMobileNumber'  => ['nullable', 'string', 'max:50'],
            'bankName'               => ['required', 'string', 'max:255'],
            'bankAccountNumber'      => ['required', 'string', 'max:100'],
            'bankHolderName'         => ['required', 'string', 'max:255'],
            'status'                 => ['sometimes', 'string', 'in:Active,Inactive'],
            'category'               => ['sometimes', 'array'],
            'category.*'             => ['nullable', 'string', 'max:255'],
            'trainingTopics'         => ['sometimes', 'array'],
            'trainingTopics.*'       => ['nullable', 'string', 'max:255'],
            'competency'             => ['sometimes', 'array'],
            'competency.*'           => ['nullable', 'string', 'max:255'],
            'supplierProducts'       => ['sometimes', 'array'],
            'supplierProducts.*'     => ['nullable', 'string', 'max:255'],
            'consultancy'            => ['sometimes', 'array'],
            'consultancy.*'          => ['nullable', 'string', 'max:255'],
            'servicesOffered'        => ['sometimes', 'array'],
            'servicesOffered.*'      => ['nullable', 'string', 'max:255'],
        ];
    }
}
