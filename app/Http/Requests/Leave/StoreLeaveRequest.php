<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'type'          => ['required', 'string', 'max:100'],
            'start_date'    => ['required', 'date_format:Y-m-d'],
            'start_time'    => ['required', 'string'],
            'end_date'      => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'end_time'      => ['required', 'string'],
            'duration_days' => ['required', 'numeric', 'min:0.5'],
            'status'        => ['required', 'string', 'in:Pending,Draft'],
            'reason'        => ['nullable', 'string', 'max:2000'],
        ];
    }
}
