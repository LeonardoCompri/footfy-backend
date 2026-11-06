<?php

namespace App\Http\Requests\Concerns;

use App\Models\Player;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

trait ValidatesUserLinks
{
    protected function userLinkRules(?string $role, ?int $userId, bool $partial): array
    {
        $presence = $partial ? ['sometimes'] : [];
        $requiredFor = fn (string $linkRole) => Rule::requiredIf(
            fn () => $role === $linkRole && ! $this->user()->isMaster()
        );

        return [
            'team_id' => [
                ...$presence,
                Rule::prohibitedIf($role !== 'coach'),
                $requiredFor('coach'),
                'nullable', 'integer', 'exists:teams,id',
                Rule::unique('users', 'team_id')->ignore($userId),
            ],
            'player_id' => [
                ...$presence,
                Rule::prohibitedIf($role !== 'player'),
                $requiredFor('player'),
                'nullable', 'integer',
                Rule::exists('players', 'id')->withoutTrashed(),
            ],
        ];
    }

    protected function userLinkMessages(): array
    {
        return [
            'team_id.prohibited' => 'Só usuários com papel coach podem ser vinculados a um time.',
            'team_id.required' => 'Informe o time do coach.',
            'team_id.unique' => 'Este time já está vinculado a outro coach.',
            'player_id.prohibited' => 'Só usuários com papel player podem ser vinculados a um jogador.',
            'player_id.required' => 'Informe o jogador vinculado ao usuário.',
            'player_id.exists' => 'O jogador informado foi removido ou não existe.',
        ];
    }

    protected function validatePlayerIsFree(Validator $validator, ?int $userId): void
    {
        if ($validator->errors()->has('player_id') || ! $this->filled('player_id')) {
            return;
        }

        $linkedToAnother = Player::whereKey($this->input('player_id'))
            ->whereNotNull('user_id')
            ->when($userId, fn ($q) => $q->where('user_id', '!=', $userId))
            ->exists();

        if ($linkedToAnother) {
            $validator->errors()->add('player_id', 'Este jogador já está vinculado a outro usuário.');
        }
    }
}
