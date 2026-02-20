<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Hash;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::create([
            'name' => 'Leonardo Compri',
            'email' => 'leonardocompri46@gmail.com',
            'document' => '45047364895',
            'email_verified_at' => now(),
            'password' => Hash::make('123456'),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $user->assignRole('master');
    }
}
