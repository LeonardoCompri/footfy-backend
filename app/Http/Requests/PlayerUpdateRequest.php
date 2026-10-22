<?php

namespace App\Http\Requests;

use App\Models\Player;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlayerUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'team_id' => ['sometimes', 'required', 'integer', 'exists:teams,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'position' => ['nullable', 'string', Rule::in(Player::positionValues())],
            'number' => ['nullable', 'integer'],
            'birth_date' => ['nullable', 'date'],
            'document' => [
                'nullable',
                'string',
                'size:11',
                // Contra todos, inclusive removidos: o unique do banco em document é global.
                Rule::unique('players', 'document')->ignore($this->route('id')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'position.in' => 'A posição informada é inválida.',
            'document.unique' => 'Este CPF já pertence a outro jogador (ativo ou removido).',
        ];
    }
}
