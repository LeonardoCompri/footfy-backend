<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CoachUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'team_id'    => ['nullable', 'integer', 'exists:teams,id'],
            'name'       => ['sometimes', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
        ];
    }
}
