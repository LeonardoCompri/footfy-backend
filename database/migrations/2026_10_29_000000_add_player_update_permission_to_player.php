<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'player:update';

    /**
     * Player volta a ter player:update em ambientes existentes (o RoleSeeder não roda em
     * deploy), usado só em PUT /me/player: em PUT /players/{id} o access:player_write segue
     * negando o papel player. Idempotente e no-op em banco novo (sem o papel player, ex.:
     * RefreshDatabase nos testes): não cria papéis, para não colidir com os seeders.
     */
    public function up(): void
    {
        foreach (Role::where('name', 'player')->get() as $role) {
            $permission = Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => $role->guard_name]);

            // givePermissionTo só anexa o que falta: rodar de novo não duplica.
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
