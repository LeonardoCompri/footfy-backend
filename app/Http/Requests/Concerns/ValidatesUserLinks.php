<?php

namespace App\Http\Requests\Concerns;

use App\Models\Player;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Vínculos 1:1 do usuário: team_id (users.team_id) só para coach e player_id (players.user_id)
 * só para player. O acesso ao time/jogador (ligas de quem edita) é conferido no UserService.
 */
trait ValidatesUserLinks
{
    /**
     * $role é o papel que o usuário terá depois da requisição; $userId é o usuário editado
     * (null no cadastro). $partial (update) só valida os campos enviados. Para quem não é
     * master o vínculo do papel é obrigatório: sem ele o coach/player ficaria fora das ligas
     * de quem o cadastrou. Master pode deixar sem vínculo.
     */
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

    // O jogador não pode estar vinculado a outro usuário (unique em players.user_id).
    protected function validatePlayerIsFree(Validator $validator, ?int $userId): void
    {
        // after() roda mesmo com regra falhando: com player_id[]=1, whereKey(array) mudaria a consulta.
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
