<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CoachStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'team_id'    => ['nullable', 'integer', 'exists:teams,id'],
            'name'       => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
        ];
    }
}
