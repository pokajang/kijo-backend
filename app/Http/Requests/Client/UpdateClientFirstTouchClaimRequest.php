<?php

namespace App\Http\Requests\Client;

class UpdateClientFirstTouchClaimRequest extends StoreClientFirstTouchClaimRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['expected_version'] = ['required', 'integer', 'min:1'];
        $rules['edit_reason'] = ['required', 'string', 'max:2000'];
        $rules['keep_evidence_ids'] = ['sometimes', 'array', 'max:3'];
        $rules['keep_evidence_ids.*'] = ['integer', 'min:1'];
        $rules['evidence'] = ['sometimes', 'array', 'max:3'];
        $rules['evidence.*'] = ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'];

        return $rules;
    }
}
