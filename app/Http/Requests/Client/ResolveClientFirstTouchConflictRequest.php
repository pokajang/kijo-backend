<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveClientFirstTouchConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['uphold_current', 'accept_competing', 'clarification_requested', 'reject_both'])],
            'note' => ['required', 'string', 'max:2000'],
            'selected_claim_id' => ['nullable', 'integer', 'min:1', 'required_if:decision,accept_competing'],
            'clarification_recipient_staff_id' => ['nullable', 'integer', 'min:1', 'required_if:decision,clarification_requested'],
        ];
    }
}
