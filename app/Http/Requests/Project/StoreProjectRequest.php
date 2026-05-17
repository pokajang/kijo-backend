<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'client_id'          => ['required', 'integer', 'min:1'],
            'project_name'       => ['required', 'string', 'max:255'],
            'project_type'       => ['required', 'string', 'max:100'],
            'po_loa_number'      => ['nullable', 'string', 'max:255'],
            'quote_value'        => ['nullable', 'numeric', 'min:0'],
            'award_date'         => ['nullable', 'date_format:Y-m-d'],
            'service_start_date' => ['nullable', 'date_format:Y-m-d'],
            'service_end_date'   => ['nullable', 'date_format:Y-m-d'],
            'description'        => ['nullable', 'string', 'max:5000'],
            'proposal_language'  => ['nullable', 'in:en,ms-MY'],
        ];
    }
}
