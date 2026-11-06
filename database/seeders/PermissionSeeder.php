<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $modules = ['tournament', 'team', 'player', 'stadium', 'technical_committee_member', 'game', 'game_event'];
        $actions = ['all', 'create', 'read', 'update', 'delete'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "{$module}:{$action}",
                ]);
            }
        }
    }
}
