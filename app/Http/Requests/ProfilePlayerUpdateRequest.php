<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /me/player: o jogador só edita nome e data de nascimento. Time, posição, número e
 * CPF não têm regra aqui, então ficam fora do validated() e são ignorados.
 */
class ProfilePlayerUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'birth_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
