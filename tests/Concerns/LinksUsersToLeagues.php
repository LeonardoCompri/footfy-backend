<?php

namespace Tests\Concerns;

use App\Models\Player;
use App\Models\Team;
use App\Models\User;

trait LinksUsersToLeagues
{
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
