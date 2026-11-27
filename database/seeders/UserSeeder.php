<?php

namespace Database\Seeders;

use App\Models\User;
use Hash;
use Illuminate\Database\Seeder;
use RuntimeException;

class UserSeeder extends Seeder
{
    private const LOCAL_PASSWORD = '123456';

    public function run(): void
    {
        $password = Hash::make($this->password());

        $master = User::create([
            'name' => 'Leonardo Compri',
            'email' => 'leonardocompri46@gmail.com',
            'document' => '45047364895',
            'email_verified_at' => now(),
            'password' => $password,
        ]);

        $master->assignRole('master');

        $admin = User::create([
            'name' => 'Admin Campeonato',
            'email' => 'admin@footfy.com',
            'document' => '12345678901',
            'email_verified_at' => now(),
            'password' => $password,
        ]);

        $admin->assignRole('admin');
    }

    private function password(): string
    {
        $password = (string) config('footfy.seed.password');

        if ($password !== '') {
            return $password;
        }

        if (app()->environment(['local', 'testing'])) {
            return self::LOCAL_PASSWORD;
        }

        throw new RuntimeException('SEED_PASSWORD vazio no .env. Defina uma senha forte para os usuários de demonstração antes de rodar o seed fora de local/testing.');
    }
}
