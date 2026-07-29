<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $master = Role::create(['name' => 'master']);
        $admin = Role::create(['name' => 'admin']);
        $coach = Role::create(['name' => 'coach']);
        $player = Role::create(['name' => 'player']);

        $master->givePermissionTo(Permission::all());

        $admin->givePermissionTo([
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

        $coach->givePermissionTo([
            'team:read',
            'team:update',
            'stadium:read',
            'stadium:create',
            'stadium:update',
            'player:read',
            'player:create',
            'player:update',
        ]);

        $player->givePermissionTo([
            'player:read',
            'player:update',
        ]);
    }
}
