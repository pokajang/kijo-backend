<?php

namespace App\Http\Requests\ToolRequest;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAchievementRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'achievement' => ['required', 'string', 'max:2000'],
        ];
    }
}
