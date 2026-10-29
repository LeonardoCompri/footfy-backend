<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /me: só nome, e-mail e senha da própria conta. Papel, CPF, time, jogador e ligas
 * não têm regra aqui, então ficam fora do validated() e são ignorados.
 */
class ProfileUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'password' => ['sometimes', 'string', 'min:6', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Este e-mail já está em uso por outro usuário.',
            'password.confirmed' => 'A confirmação da senha não confere.',
        ];
    }
}
