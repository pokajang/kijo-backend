<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientFirstTouchClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_group' => ['required', 'string', 'max:80'],
            'source_value' => ['required', 'string', 'max:120'],
            'channel' => ['required', 'string', 'max:120'],
            'method' => ['required', 'string', 'max:120'],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'occurred_time' => ['nullable', 'date_format:H:i'],
            'occurrence_precision' => ['required', Rule::in(['date', 'minute'])],
            'occurrence_timezone' => ['required', 'timezone:all', 'max:80'],
            'chronology_needs_review' => ['sometimes', 'boolean'],
            'client_contact' => ['nullable', 'string', 'max:255'],
            'contact_mode' => ['required', Rule::in(['named', 'shared', 'unknown'])],
            'amiosh_contact_staff_id' => ['nullable', 'integer', 'min:1'],
            'amiosh_contact_name' => ['nullable', 'string', 'max:255'],
            'amiosh_contact_code' => ['nullable', 'string', 'max:40'],
            'referrer_staff_id' => ['nullable', 'integer', 'min:1'],
            'referrer_name' => ['nullable', 'string', 'max:255'],
            'referrer_code' => ['nullable', 'string', 'max:40'],
            'employment_context' => ['nullable', 'string', 'max:40'],
            'employment_boundary' => ['nullable', Rule::in(['before_departure', 'after_departure'])],
            'employment_ended_on' => ['nullable', 'date_format:Y-m-d'],
            'employment_departure_type' => ['nullable', 'string', 'max:40'],
            'linked_inquiry_id' => ['nullable', 'integer', 'min:1'],
            'inquiry_ref' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'evidence' => ['required', 'array', 'min:1', 'max:3'],
            'evidence.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ];
    }
}
