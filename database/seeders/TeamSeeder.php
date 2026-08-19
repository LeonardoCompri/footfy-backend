<?php

namespace Database\Seeders;

use App\Models\League;
use App\Models\Team;
use Database\Seeders\Support\FootballData;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    /**
     * Cria os times reais/realistas e vincula cada um às ligas em que
     * participa (pivot league_team), permitindo que um mesmo time apareça
     * em mais de uma liga — como acontece de fato (ex: Corinthians disputa
     * Brasileirão, Copa do Brasil e Paulista).
     */
    public function run(): void
    {
        $now = now();

        $rows = collect(FootballData::teams())
            ->map(fn (array $team) => [
                'name' => $team['name'],
                'nickname' => $team['nickname'],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        Team::insert($rows);

        $this->attachTeamsToLeagues();
    }

    private function attachTeamsToLeagues(): void
    {
        $teamIds = Team::pluck('id', 'name');
        $leagueIds = League::pluck('id', 'name');
        $teamCatalog = FootballData::teams();
        $leagueCatalog = FootballData::leagues();

        foreach (FootballData::leagueTeams() as $leagueKey => $teamKeys) {
            $leagueId = $leagueIds[$leagueCatalog[$leagueKey]['name']];

            $ids = collect($teamKeys)
                ->map(fn (string $teamKey) => $teamIds[$teamCatalog[$teamKey]['name']])
                ->all();

            League::find($leagueId)->teams()->attach($ids);
        }
    }
}
