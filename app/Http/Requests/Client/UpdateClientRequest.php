<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        $picList = $payload['pic_list'] ?? $payload['picList'] ?? null;
        $newPicList = $payload['new_pic_list'] ?? $payload['newPicList'] ?? [];
        $branchList = $payload['branch_list'] ?? $payload['branchList'] ?? null;

        $this->merge([
            'company_id' => $this->route('companyId') ?? $this->route('company_id') ?? $payload['company_id'] ?? $payload['companyId'] ?? null,
            'company_name' => $payload['company_name'] ?? $payload['companyName'] ?? null,
            'ssm_number' => $payload['ssm_number'] ?? $payload['ssmNumber'] ?? null,
            'tax_id_no_tin' => $payload['tax_id_no_tin'] ?? $payload['taxIdNoTin'] ?? null,
            'client_status' => $payload['client_status'] ?? $payload['clientStatus'] ?? 'New',
            'country' => $payload['country'] ?? null,
            'intl_country' => $payload['intl_country'] ?? $payload['intlCountry'] ?? null,
            'pic_list' => is_array($picList)
                ? array_map(static fn ($pic) => [
                    'pic_id' => $pic['pic_id'] ?? $pic['picId'] ?? null,
                    'full_name' => $pic['full_name'] ?? $pic['fullName'] ?? null,
                    'email' => $pic['email'] ?? null,
                    'mobile_number' => $pic['mobile_number'] ?? $pic['mobileNumber'] ?? null,
                    'position' => $pic['position'] ?? null,
                ], $picList)
                : null,
            'new_pic_list' => is_array($newPicList)
                ? array_map(static fn ($pic) => [
                    'full_name' => $pic['full_name'] ?? $pic['fullName'] ?? null,
                    'email' => $pic['email'] ?? null,
                    'mobile_number' => $pic['mobile_number'] ?? $pic['mobileNumber'] ?? null,
                    'position' => $pic['position'] ?? null,
                ], $newPicList)
                : [],
            'branch_list' => is_array($branchList)
                ? array_map(static fn ($branch) => [
                    'branch_id' => $branch['branch_id'] ?? $branch['branchId'] ?? null,
                    'branch_name' => $branch['branch_name'] ?? $branch['branchName'] ?? null,
                    'address' => $branch['address'] ?? null,
                    'city' => $branch['city'] ?? null,
                    'state' => $branch['state'] ?? null,
                    'zip' => $branch['zip'] ?? null,
                    'country' => $branch['country'] ?? null,
                    'intl_country' => $branch['intl_country'] ?? $branch['intlCountry'] ?? null,
                ], $branchList)
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'min:1'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'ssm_number' => ['nullable', 'string', 'max:100'],
            'tax_id_no_tin' => ['nullable', 'string', 'max:30'],
            'client_status' => ['nullable', 'in:Old,New'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'intl_country' => ['nullable', 'string', 'max:100'],

            'pic_list' => ['nullable', 'array'],
            'pic_list.*.pic_id' => ['nullable', 'integer', 'min:1'],
            'pic_list.*.full_name' => ['nullable', 'string', 'max:255'],
            'pic_list.*.email' => ['nullable', 'string', 'max:255'],
            'pic_list.*.mobile_number' => ['nullable', 'string', 'max:50'],
            'pic_list.*.position' => ['nullable', 'string', 'max:100'],

            'new_pic_list' => ['nullable', 'array'],
            'new_pic_list.*.full_name' => ['nullable', 'string', 'max:255'],
            'new_pic_list.*.email' => ['nullable', 'string', 'max:255'],
            'new_pic_list.*.mobile_number' => ['nullable', 'string', 'max:50'],
            'new_pic_list.*.position' => ['nullable', 'string', 'max:100'],

            'branch_list' => ['nullable', 'array'],
            'branch_list.*.branch_id' => ['nullable', 'integer', 'min:1'],
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
