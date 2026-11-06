<?php

namespace App\Http\Requests;

use App\Models\TechnicalCommitteeMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TechnicalCommitteeMemberStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', Rule::in(TechnicalCommitteeMember::roleValues())],
            'birth_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'A função informada é inválida.',
        ];
    }

    public function attributes(): array
    {
        return [
            'role' => 'função',
        ];
    }
}
