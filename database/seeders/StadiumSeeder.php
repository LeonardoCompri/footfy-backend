<?php

namespace Database\Seeders;

use App\Models\League;
use App\Models\Stadium;
use Database\Seeders\Support\FootballData;
use Illuminate\Database\Seeder;

class StadiumSeeder extends Seeder
{
    public function run(): void
    {
        $leagueIds = League::pluck('id', 'name');
        $leagueCatalog = FootballData::leagues();
        $stadiumCatalog = FootballData::stadiums();
        $now = now();

        foreach (FootballData::leagueTeams() as $leagueKey => $teamKeys) {
            $leagueId = $leagueIds[$leagueCatalog[$leagueKey]['name']];
            $rows = [];
            $seenNames = [];

            foreach ($teamKeys as $teamKey) {
                $stadium = $stadiumCatalog[$teamKey];

                if (isset($seenNames[$stadium['name']])) {
                    continue;
                }

                $seenNames[$stadium['name']] = true;

                $rows[] = [
                    'league_id' => $leagueId,
                    'name' => $stadium['name'],
                    'city' => $stadium['city'],
                    'capacity' => $stadium['capacity'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            Stadium::insert($rows);
        }
    }
}
