<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlayerStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'integer'],
            'birth_date' => ['nullable', 'date'],
            'document' => ['nullable', 'string', 'size:11', Rule::unique('players', 'document')],
        ];
    }
}
