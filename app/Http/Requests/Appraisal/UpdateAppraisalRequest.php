<?php

namespace App\Http\Requests\Appraisal;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppraisalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id'        => ['required', 'integer'],
            'eventDate' => ['required', 'date_format:Y-m-d'],
            'feedback'  => ['required', 'string', 'max:5000'],
        ];
    }
}
