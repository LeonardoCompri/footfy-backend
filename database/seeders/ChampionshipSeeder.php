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
                'name'       => 'Brasileirão Série A 2026',
                'start_date' => '2026-04-01',
                'end_date'   => '2026-12-07',
                'organizer'  => 'CBF',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'       => 'Copa do Brasil 2026',
                'start_date' => '2026-02-01',
                'end_date'   => '2026-09-30',
                'organizer'  => 'CBF',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'       => 'Campeonato Paulista 2026',
                'start_date' => '2026-01-15',
                'end_date'   => '2026-04-20',
                'organizer'  => 'FPF',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
