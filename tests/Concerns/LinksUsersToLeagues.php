<?php

namespace Tests\Concerns;

use App\Models\Player;
use App\Models\Team;
use App\Models\User;

trait LinksUsersToLeagues
{
    /**
     * Coloca o usuário nas ligas informadas do jeito que vale para o papel dele: master/admin
     * via user_leagues; coach via time vinculado (users.team_id); player via jogador vinculado
     * (players.user_id) de um time nessas ligas. Devolve o time criado para coach/player.
     */
    protected function linkUserToLeagues(User $user, array $leagueIds): ?Team
    {
        if (! $user->hasTeamScope()) {
            $user->leagues()->sync($leagueIds);

            return null;
        }

        $team = Team::factory()->create();
        $team->leagues()->sync($leagueIds);

        if ($user->hasRole('coach')) {
            $user->update(['team_id' => $team->id]);
        } else {
            Player::factory()->create(['team_id' => $team->id, 'user_id' => $user->id]);
        }

        return $team;
    }
}
