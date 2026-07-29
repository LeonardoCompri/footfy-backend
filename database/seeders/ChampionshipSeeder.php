<?php

namespace Database\Seeders;

use App\Models\Championship;
use Illuminate\Database\Seeder;

class ChampionshipSeeder extends Seeder
{
    public function run(): void
    {
        Championship::insert([
            [
                'name' => 'Campeonato Brasileiro',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Copa do Brasil',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Campeonato Paulista',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
