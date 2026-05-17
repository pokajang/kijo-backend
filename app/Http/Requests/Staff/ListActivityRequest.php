<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class ListActivityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'searchTerm' => ['nullable', 'string', 'max:120'],
            'userFilter' => ['nullable', 'string', 'max:50'],
            'periodFilter' => ['nullable', 'in:1w,1m,1y,custom,by_month,all'],
            'customStartDate' => ['nullable', 'date_format:Y-m-d'],
            'customEndDate' => ['nullable', 'date_format:Y-m-d'],
            'monthFilter' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'sortColumn' => ['nullable', 'in:created_at,name_code,action,date,user_code,details'],
            'sortDirection' => ['nullable', 'in:asc,desc,ASC,DESC'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }
}
