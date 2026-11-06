<?php

namespace App\Http\Requests;

use App\Models\TechnicalCommitteeMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TechnicalCommitteeMemberUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'team_id' => ['sometimes', 'required', 'integer', 'exists:teams,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'string', Rule::in($this->allowedRoles())],
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

    private function allowedRoles(): array
    {
        $roles = TechnicalCommitteeMember::roleValues();
        $member = $this->route('id') ? TechnicalCommitteeMember::find($this->route('id')) : null;

        if ($member && $member->role !== null && ! in_array($member->role, $roles, true)) {
            $roles[] = $member->role;
        }

        return $roles;
    }
}
