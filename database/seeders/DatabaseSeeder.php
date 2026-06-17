<?php

namespace Database\Seeders;

use App\Models\Championship;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            ChampionshipSeeder::class,
            TeamSeeder::class,
            PlayerSeeder::class,
        ]);

        // Associar o usuário admin aos dois primeiros campeonatos criados
        $admin = User::where('email', 'admin@footfy.com')->first();
        $championships = Championship::take(2)->pluck('id')->toArray();
        $admin?->championships()->sync($championships);
    }
}
