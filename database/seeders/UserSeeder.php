<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $master = User::create([
            'name'               => 'Leonardo Compri',
            'email'              => 'leonardocompri46@gmail.com',
            'document'           => '45047364895',
            'email_verified_at'  => now(),
            'password'           => Hash::make('123456'),
        ]);

        $master->assignRole('master');

        $admin = User::create([
            'name'               => 'Admin Campeonato',
            'email'              => 'admin@footfy.com',
            'document'           => '12345678901',
            'email_verified_at'  => now(),
            'password'           => Hash::make('123456'),
        ]);

        $admin->assignRole('admin');
    }
}
