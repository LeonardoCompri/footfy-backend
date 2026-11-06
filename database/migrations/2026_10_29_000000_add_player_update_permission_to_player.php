<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'player:update';

    public function up(): void
    {
        foreach (Role::where('name', 'player')->get() as $role) {
            $permission = Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => $role->guard_name]);

            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (Role::where('name', 'player')->get() as $role) {
            $permission = Permission::where('name', self::PERMISSION)->where('guard_name', $role->guard_name)->first();

            if ($permission) {
                $role->revokePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
