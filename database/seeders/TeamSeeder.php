<?php

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Team::insert([
            [
                'name' => 'Sport Club Corinthians Paulista',
                'nickname' => 'Corinthians',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'Real Beta F.C.',
                'nickname' => 'Beta',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'Villa Xavier F.C.',
                'nickname' => 'VX',
                'created_at' => now(),
                'updated_at' => now()
            ],
        ]);
    }
}
