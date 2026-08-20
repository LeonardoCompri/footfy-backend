<?php

namespace Database\Seeders;

use App\Models\Player;
use App\Models\Team;
use Database\Seeders\Support\FootballData;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;

class PlayerSeeder extends Seeder
{
    /**
     * Distribuição de posições do elenco (20 jogadores por time).
     */
    private const SQUAD_POSITIONS = [
        'GOL', 'GOL', 'GOL',
        'ZAG', 'ZAG', 'ZAG', 'ZAG',
        'LAT', 'LAT', 'LAT',
        'MEI', 'MEI', 'MEI', 'MEI', 'MEI', 'MEI',
        'ATA', 'ATA', 'ATA', 'ATA',
    ];

    /**
     * Números de camisa tradicionais por posição, sorteados sem repetição
     * dentro de cada time.
     */
    private const NUMBER_POOLS = [
        'GOL' => [1, 12, 22],
        'ZAG' => [2, 3, 4, 13, 15, 25],
        'LAT' => [6, 14, 16, 23],
        'MEI' => [5, 8, 10, 17, 18, 19, 20, 21],
        'ATA' => [7, 9, 11, 29, 30],
    ];

    public function run(): void
    {
        $now = now();

        foreach (Team::all() as $team) {
            $faker = FakerFactory::create(FootballData::localeForTeamName($team->name));
            $numberPools = collect(self::NUMBER_POOLS)
                ->map(fn (array $pool) => collect($pool)->shuffle()->values());
            $nextIndex = [];
            $rows = [];

            foreach (self::SQUAD_POSITIONS as $position) {
                $nextIndex[$position] = ($nextIndex[$position] ?? -1) + 1;

                $rows[] = [
                    'team_id' => $team->id,
                    'name' => $faker->name(),
                    'position' => $position,
                    'number' => $numberPools[$position][$nextIndex[$position]],
                    'birth_date' => $faker->dateTimeBetween('-36 years', '-18 years')->format('Y-m-d'),
                    'document' => fake()->unique()->numerify('###########'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            Player::insert($rows);
        }
    }
}
