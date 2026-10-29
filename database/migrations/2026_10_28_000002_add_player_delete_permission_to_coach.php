<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'player:delete';

    /**
     * Coach passa a excluir jogadores (só do próprio time, via access:player_write) em
     * ambientes existentes; o RoleSeeder não roda em deploy. Idempotente e no-op em banco
     * novo (sem o papel coach, ex.: RefreshDatabase nos testes): não cria papéis, para não
     * colidir com os seeders.
     */
    public function up(): void
    {
        foreach (Role::where('name', 'coach')->get() as $role) {
            $permission = Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => $role->guard_name]);

            // givePermissionTo só anexa o que falta: rodar de novo não duplica.
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (Role::where('name', 'coach')->get() as $role) {
            $permission = Permission::where('name', self::PERMISSION)->where('guard_name', $role->guard_name)->first();

            if ($permission) {
                $role->revokePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
