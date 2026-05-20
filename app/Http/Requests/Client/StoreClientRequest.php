<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        $picList = $payload['pic_list'] ?? $payload['picList'] ?? [];
        if (!is_array($picList)) {
            $picList = [];
        }

        $branchList = $payload['branch_list'] ?? $payload['branchList'] ?? [];
        if (!is_array($branchList)) {
            $branchList = [];
        }

        $hasPaymentTerms = array_key_exists('payment_terms_days', $payload) || array_key_exists('paymentTermsDays', $payload);
        $useDefaultPaymentTerms = $payload['use_default_payment_terms'] ?? $payload['useDefaultPaymentTerms'] ?? ! $hasPaymentTerms;

        $this->merge([
            'company_name' => $payload['company_name'] ?? $payload['companyName'] ?? null,
            'ssm_number' => $payload['ssm_number'] ?? $payload['ssmNumber'] ?? null,
            'tax_id_no_tin' => $payload['tax_id_no_tin'] ?? $payload['taxIdNoTin'] ?? null,
            'client_status' => $payload['client_status'] ?? $payload['clientStatus'] ?? 'New',
            'use_default_payment_terms' => $useDefaultPaymentTerms,
            'payment_terms_days' => filter_var($useDefaultPaymentTerms, FILTER_VALIDATE_BOOLEAN) ? null : ($payload['payment_terms_days'] ?? $payload['paymentTermsDays'] ?? 30),
            'country' => $payload['country'] ?? null,
            'intl_country' => $payload['intl_country'] ?? $payload['intlCountry'] ?? null,
            'pic_list' => array_map(static fn ($pic) => [
                'full_name' => $pic['full_name'] ?? $pic['fullName'] ?? null,
                'email' => $pic['email'] ?? null,
                'mobile_number' => $pic['mobile_number'] ?? $pic['mobileNumber'] ?? null,
                'position' => $pic['position'] ?? null,
            ], $picList),
            'branch_list' => array_map(static fn ($branch) => [
                'branch_name' => $branch['branch_name'] ?? $branch['branchName'] ?? null,
                'address' => $branch['address'] ?? null,
                'city' => $branch['city'] ?? null,
                'state' => $branch['state'] ?? null,
                'zip' => $branch['zip'] ?? null,
                'country' => $branch['country'] ?? null,
                'intl_country' => $branch['intl_country'] ?? $branch['intlCountry'] ?? null,
            ], $branchList),
        ]);
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'ssm_number' => ['nullable', 'string', 'max:100'],
            'tax_id_no_tin' => ['nullable', 'string', 'max:30'],
            'client_status' => ['nullable', 'in:Old,New'],
            'use_default_payment_terms' => ['nullable', 'boolean'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'intl_country' => ['nullable', 'string', 'max:100'],

            'pic_list' => ['required', 'array', 'min:1'],
            'pic_list.*.full_name' => ['required', 'string', 'max:255'],
            'pic_list.*.email' => ['required', 'string', 'max:255'],
            'pic_list.*.mobile_number' => ['nullable', 'string', 'max:50'],
            'pic_list.*.position' => ['nullable', 'string', 'max:100'],

            'branch_list' => ['nullable', 'array'],
            'branch_list.*.branch_name' => ['nullable', 'string', 'max:255'],
            'branch_list.*.address' => ['nullable', 'string', 'max:1000'],
            'branch_list.*.city' => ['nullable', 'string', 'max:100'],
            'branch_list.*.state' => ['nullable', 'string', 'max:255'],
            'branch_list.*.zip' => ['nullable', 'string', 'max:20'],
            'branch_list.*.country' => ['nullable', 'string', 'max:100'],
            'branch_list.*.intl_country' => ['nullable', 'string', 'max:100'],
        ];
    }
}
