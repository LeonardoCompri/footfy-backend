<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const TARGET = [
        'coach' => ['team:read', 'stadium:read', 'player:read', 'player:create', 'player:update'],
        'player' => ['player:read'],
    ];

    private const PREVIOUS = [
        'coach' => ['team:read', 'team:update', 'stadium:read', 'stadium:create', 'stadium:update', 'player:read', 'player:create', 'player:update'],
        'player' => ['player:read', 'player:update'],
    ];

    public function up(): void
    {
        $this->apply(self::TARGET);
    }

    public function down(): void
    {
        $this->apply(self::PREVIOUS);
    }

    private function apply(array $map): void
    {
        foreach ($map as $roleName => $permissions) {
            foreach (Role::where('name', $roleName)->get() as $role) {
                foreach ($permissions as $name) {
                    Permission::firstOrCreate(['name' => $name, 'guard_name' => $role->guard_name]);
                }

                $role->syncPermissions($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
