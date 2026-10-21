<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permissões de coach e player a partir do FOOT-0043 (mesmas listas do RoleSeeder).
     */
    private const TARGET = [
        'coach' => ['team:read', 'stadium:read', 'player:read', 'player:create', 'player:update'],
        'player' => ['player:read'],
    ];

    /**
     * Permissões anteriores ao FOOT-0043, restauradas no down().
     */
    private const PREVIOUS = [
        'coach' => ['team:read', 'team:update', 'stadium:read', 'stadium:create', 'stadium:update', 'player:read', 'player:create', 'player:update'],
        'player' => ['player:read', 'player:update'],
    ];

    /**
     * Ajusta as permissões de coach e player em ambientes existentes (o RoleSeeder
     * não roda em deploy). Não altera schema.
     */
    public function up(): void
    {
        $this->apply(self::TARGET);
    }

    public function down(): void
    {
        $this->apply(self::PREVIOUS);
    }

    /**
     * Sincroniza as permissões dos papéis que existirem. Idempotente; em banco novo
     * (sem papéis, ex.: RefreshDatabase nos testes) é no-op e o RoleSeeder cria depois.
     * Não cria papéis nem permissões fora desse caso, para não colidir com os seeders.
     */
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
