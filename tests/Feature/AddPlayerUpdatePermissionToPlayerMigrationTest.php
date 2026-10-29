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

class AddPlayerUpdatePermissionToPlayerMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** Permissões do coach depois da FOOT-0047 (não mudam aqui). */
    private const COACH = ['player:create', 'player:delete', 'player:read', 'player:update', 'stadium:read', 'team:read'];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_29_000000_add_player_update_permission_to_player.php');
    }

    /** Estado de um ambiente existente antes da FOOT-0048: player só com player:read. */
    private function seedRolesBeforeMigration(): void
    {
        $this->seed(PermissionSeeder::class);
        Role::create(['name' => 'coach'])->givePermissionTo(self::COACH);
        Role::create(['name' => 'player'])->givePermissionTo('player:read');
    }

    private function permissionNames(string $role): array
    {
        return Role::findByName($role)->permissions()->pluck('name')->sort()->values()->all();
    }

    public function test_up_adds_player_update_to_player_and_is_idempotent(): void
    {
        $this->seedRolesBeforeMigration();
        $permissionCount = Permission::count();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(['player:read', 'player:update'], $this->permissionNames('player'));
        $this->assertSame(self::COACH, $this->permissionNames('coach'));
        $this->assertSame($permissionCount, Permission::count());
        $this->assertSame(count(self::COACH) + 2, DB::table('role_has_permissions')->count());
    }

    public function test_up_is_a_no_op_when_player_role_does_not_exist(): void
    {
        $this->migration()->up();

        $this->assertSame(0, Role::count());
        $this->assertSame(0, Permission::count());
    }

    public function test_down_removes_only_player_update_from_player(): void
    {
        $this->seedRolesBeforeMigration();
        $this->migration()->up();

        $this->migration()->down();
        $this->migration()->down();

        $this->assertSame(['player:read'], $this->permissionNames('player'));
        $this->assertSame(self::COACH, $this->permissionNames('coach'));
        $this->assertTrue(Permission::where('name', 'player:update')->exists());
    }

    public function test_down_does_not_fail_when_permission_does_not_exist(): void
    {
        Role::create(['name' => 'player']);

        $this->migration()->down();

        $this->assertSame([], $this->permissionNames('player'));
    }

    public function test_migration_matches_role_seeder_list(): void
    {
        $this->seedRolesBeforeMigration();
        $this->migration()->up();
        $migrated = $this->permissionNames('player');

        $this->seed(RoleSeeder::class);

        $this->assertSame($migrated, $this->permissionNames('player'));
    }
}
