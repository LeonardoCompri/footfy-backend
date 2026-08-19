<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\TechnicalCommitteeMember;
use Database\Seeders\Support\FootballData;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;

class TechnicalCommitteeMemberSeeder extends Seeder
{
    public function run(): void
    {
        $headCoaches = FootballData::headCoaches();
        $now = now();
        $rows = [];

        foreach (Team::all() as $team) {
            $teamKey = FootballData::teamKeyForName($team->name);
            $faker = FakerFactory::create(FootballData::localeForTeamName($team->name));

            $rows[] = [
                'team_id' => $team->id,
                'name' => $headCoaches[$teamKey],
                'role' => 'Técnico',
                'birth_date' => $faker->dateTimeBetween('-65 years', '-38 years')->format('Y-m-d'),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $rows[] = [
                'team_id' => $team->id,
                'name' => $faker->name(),
                'role' => 'Auxiliar Técnico',
                'birth_date' => $faker->dateTimeBetween('-55 years', '-28 years')->format('Y-m-d'),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $rows[] = [
                'team_id' => $team->id,
                'name' => $faker->name(),
                'role' => 'Preparador Físico',
                'birth_date' => $faker->dateTimeBetween('-50 years', '-25 years')->format('Y-m-d'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        TechnicalCommitteeMember::insert($rows);
    }
}
