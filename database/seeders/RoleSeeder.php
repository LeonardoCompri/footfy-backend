<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Idempotente (firstOrCreate + syncPermissions): pode ser rodado de novo em
     * ambientes existentes para realinhar as permissões de cada papel.
     */
    public function run(): void
    {
        $master = Role::firstOrCreate(['name' => 'master']);
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $coach = Role::firstOrCreate(['name' => 'coach']);
        $player = Role::firstOrCreate(['name' => 'player']);

        $master->syncPermissions(Permission::all());

        $admin->syncPermissions([
            'tournament:read',
            'tournament:update',
            'team:read',
            'team:create',
            'team:update',
            'team:delete',
            'player:read',
            'player:create',
            'player:update',
            'player:delete',
            'stadium:read',
            'stadium:create',
            'stadium:update',
            'stadium:delete',
            'technical_committee_member:read',
            'technical_committee_member:create',
            'technical_committee_member:update',
            'technical_committee_member:delete',
            'game:read',
            'game:create',
            'game:update',
            'game:delete',
            'game_event:read',
            'game_event:create',
            'game_event:update',
            'game_event:delete',
        ]);

        // Escrita de jogador só no próprio time (users.team_id): access:player_write e PlayerService.
        $coach->syncPermissions([
            'team:read',
            'stadium:read',
            'player:read',
            'player:create',
            'player:update',
            'player:delete',
        ]);

        // player:update só vale em PUT /me/player (FOOT-0048): em PUT /players/{id} o
        // access:player_write nega o papel player.
        $player->syncPermissions([
            'player:read',
            'player:update',
        ]);
    }
}
