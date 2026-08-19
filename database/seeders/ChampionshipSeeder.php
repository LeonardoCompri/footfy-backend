<?php

namespace Database\Seeders;

use App\Models\Championship;
use Database\Seeders\Support\FootballData;
use Illuminate\Database\Seeder;

class ChampionshipSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $rows = collect(FootballData::championships())
            ->map(fn (string $name) => [
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        Championship::insert($rows);
    }
}
