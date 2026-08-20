<?php

namespace Database\Seeders;

use App\Models\Championship;
use App\Models\League;
use Database\Seeders\Support\FootballData;
use Illuminate\Database\Seeder;

class LeagueSeeder extends Seeder
{
    public function run(): void
    {
        $championshipIds = Championship::pluck('id', 'name');
        $championshipNames = FootballData::championships();
        $now = now();

        $rows = collect(FootballData::leagues())
            ->map(fn (array $league) => [
                'name' => $league['name'],
                'start_date' => $league['start_date'],
                'end_date' => $league['end_date'],
                'organizer' => $league['organizer'],
                'season' => $league['season'],
                'championship_id' => $championshipIds[$championshipNames[$league['championship']]],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        League::insert($rows);
    }
}
