<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UpdateCoachAndPlayerPermissionsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_COACH = ['team:read', 'team:update', 'stadium:read', 'stadium:create', 'stadium:update', 'player:read', 'player:create', 'player:update'];

    private const OLD_PLAYER = ['player:read', 'player:update'];

    private const NEW_COACH = ['player:create', 'player:read', 'player:update', 'stadium:read', 'team:read'];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_20_000000_update_coach_and_player_permissions.php');
    }

    /** Estado de um ambiente existente: permissões seedadas e papéis com as listas anteriores ao FOOT-0043. */
    private function seedLegacyRoles(): void
    {
        $this->seed(PermissionSeeder::class);
        Role::create(['name' => 'coach'])->givePermissionTo(self::OLD_COACH);
        Role::create(['name' => 'player'])->givePermissionTo(self::OLD_PLAYER);
    }

    private function permissionNames(string $role): array
    {
        return Role::findByName($role)->permissions()->pluck('name')->sort()->values()->all();
    }

    public function test_up_applies_new_coach_and_player_permissions_and_is_idempotent(): void
    {
        $this->seedLegacyRoles();
        $permissionCount = Permission::count();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(self::NEW_COACH, $this->permissionNames('coach'));
        $this->assertSame(['player:read'], $this->permissionNames('player'));
        $this->assertSame($permissionCount, Permission::count());
        $this->assertSame(2, Role::count());
        $this->assertSame(
            count(self::NEW_COACH) + 1,
            DB::table('role_has_permissions')->count()
        );
    }

    public function test_up_is_a_no_op_when_roles_do_not_exist(): void
    {
        $this->migration()->up();

        $this->assertSame(0, Role::count());
        $this->assertSame(0, Permission::count());
    }

    public function test_down_restores_previous_permissions(): void
    {
        $this->seedLegacyRoles();
        $this->migration()->up();

        $this->migration()->down();

        $this->assertSame(collect(self::OLD_COACH)->sort()->values()->all(), $this->permissionNames('coach'));
        $this->assertSame(self::OLD_PLAYER, $this->permissionNames('player'));
    }

    public function test_migration_matches_role_seeder_lists(): void
    {
        $this->seedLegacyRoles();
        $this->migration()->up();
        $migrated = ['coach' => $this->permissionNames('coach'), 'player' => $this->permissionNames('player')];

        $this->seed(RoleSeeder::class);

        $this->assertSame($migrated['coach'], $this->permissionNames('coach'));
        $this->assertSame($migrated['player'], $this->permissionNames('player'));
    }

    public function test_running_permission_and_role_seeders_twice_does_not_fail_nor_duplicate(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $permissions = Permission::count();
        $pivotRows = DB::table('role_has_permissions')->count();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->assertSame($permissions, Permission::count());
        $this->assertSame(4, Role::count());
        $this->assertSame($pivotRows, DB::table('role_has_permissions')->count());
        $this->assertSame($permissions, Role::findByName('master')->permissions()->count());
        $this->assertSame(self::NEW_COACH, $this->permissionNames('coach'));
        $this->assertSame(['player:read'], $this->permissionNames('player'));
    }
}
