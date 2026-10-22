<?php

namespace App\Http\Requests;

use App\Models\Player;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlayerStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', Rule::in(Player::positionValues())],
            'number' => ['nullable', 'integer'],
            'birth_date' => ['nullable', 'date'],
            // CPF de jogador removido (soft delete) não bloqueia: o PlayerService restaura esse registro.
            'document' => ['nullable', 'string', 'size:11', Rule::unique('players', 'document')->withoutTrashed()],
        ];
    }

    public function messages(): array
    {
        return [
            'position.in' => 'A posição informada é inválida.',
        ];
    }
}
