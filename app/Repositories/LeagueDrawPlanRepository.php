<?php

namespace App\Repositories;

use App\Models\League;
use App\Models\LeagueDrawPlan;
use Illuminate\Database\Eloquent\Collection;

class LeagueDrawPlanRepository
{
    public function forTurn(League $league, int $turn): Collection
    {
        return LeagueDrawPlan::query()
            ->where('league_id', $league->id)
            ->where('turn', $turn)
            ->get()
            ->keyBy('group_number');
    }

    public function save(League $league, int $groupNumber, int $turn, array $rounds): LeagueDrawPlan
    {
        return LeagueDrawPlan::query()->updateOrCreate(
            ['league_id' => $league->id, 'group_number' => $groupNumber, 'turn' => $turn],
            ['payload' => ['rounds' => $rounds]],
        );
    }

    public function deleteForLeague(League $league): int
    {
        return LeagueDrawPlan::query()->where('league_id', $league->id)->delete();
    }
}
