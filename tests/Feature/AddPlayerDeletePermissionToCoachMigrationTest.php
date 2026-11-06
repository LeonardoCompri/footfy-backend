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

class AddPlayerDeletePermissionToCoachMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_COACH = ['player:create', 'player:read', 'player:update', 'stadium:read', 'team:read'];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_28_000002_add_player_delete_permission_to_coach.php');
    }

    private function seedCoachBeforeMigration(): void
    {
        $this->seed(PermissionSeeder::class);
        Role::create(['name' => 'coach'])->givePermissionTo(self::OLD_COACH);
        Role::create(['name' => 'player'])->givePermissionTo('player:read');
    }

    private function permissionNames(string $role): array
    {
        return Role::findByName($role)->permissions()->pluck('name')->sort()->values()->all();
    }

    public function test_up_adds_player_delete_to_coach_and_is_idempotent(): void
    {
        $this->seedCoachBeforeMigration();
        $permissionCount = Permission::count();

        $this->migration()->up();
        $this->migration()->up();

        $expected = collect([...self::OLD_COACH, 'player:delete'])->sort()->values()->all();
        $this->assertSame($expected, $this->permissionNames('coach'));
        $this->assertSame(['player:read'], $this->permissionNames('player'));
        $this->assertSame($permissionCount, Permission::count());
        $this->assertSame(count($expected) + 1, DB::table('role_has_permissions')->count());
    }

    public function test_up_is_a_no_op_when_coach_role_does_not_exist(): void
    {
        $this->migration()->up();

        $this->assertSame(0, Role::count());
        $this->assertSame(0, Permission::count());
    }

    public function test_down_removes_only_player_delete_from_coach(): void
    {
        $this->seedCoachBeforeMigration();
        $this->migration()->up();

        $this->migration()->down();
        $this->migration()->down();

        $this->assertSame(self::OLD_COACH, $this->permissionNames('coach'));
        $this->assertTrue(Permission::where('name', 'player:delete')->exists());
    }

    public function test_down_does_not_fail_when_permission_does_not_exist(): void
    {
        Role::create(['name' => 'coach']);

        $this->migration()->down();

        $this->assertSame([], $this->permissionNames('coach'));
    }

    public function test_migration_matches_role_seeder_list(): void
    {
        $this->seedCoachBeforeMigration();
        $this->migration()->up();
        $migrated = $this->permissionNames('coach');

        $this->seed(RoleSeeder::class);

        $this->assertSame($migrated, $this->permissionNames('coach'));
    }
}
