<?php

namespace App\Http\Requests\SportEvent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSportEventRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'event_name'     => ['required', 'string', 'max:255'],
            'event_datetime' => ['required', 'string'],
            'attendee_ids'   => ['required', 'string'],
            'image'          => ['nullable', 'file', 'mimes:jpeg,png,webp,gif', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.mimes' => 'Only JPG, PNG, WEBP, and GIF images are allowed.',
            'image.max'   => 'Image must be smaller than 5 MB.',
        ];
    }
}
