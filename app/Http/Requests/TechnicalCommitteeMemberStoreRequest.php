<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TechnicalCommitteeMemberStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'team_id'    => ['nullable', 'integer', 'exists:teams,id'],
            'name'       => ['required', 'string', 'max:255'],
            'role'       => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
        ];
    }
}
