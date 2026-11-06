<?php

namespace Database\Seeders;

use App\Models\League;
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
            LeagueSeeder::class,
            TeamSeeder::class,
            StadiumSeeder::class,
            PlayerSeeder::class,
            TechnicalCommitteeMemberSeeder::class,
            GameSeeder::class,
            GameEventSeeder::class,
        ]);

        $admin = User::where('email', 'admin@footfy.com')->first();
        $leagues = League::take(2)->pluck('id')->toArray();
        $admin?->leagues()->sync($leagues);
    }
}
