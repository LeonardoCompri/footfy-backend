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

    // Aqui role é a função na comissão técnica, não o papel do usuário (validation.attributes.role).
    public function attributes(): array
    {
        return [
            'role' => 'função',
        ];
    }

    /**
     * Lista oficial + o role já gravado no registro editado: um membro legado
     * (role fora da lista) pode ser editado em outros campos reenviando o mesmo
     * role, mas não pode trocar para outro valor fora da lista.
     */
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
