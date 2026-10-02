<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    private function createUser(?string $role = null, string $document = '12345678900'): User
    {
        $user = User::factory()->create(['document' => $document]);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function login(string $document = '123.456.789-00', string $password = 'password')
    {
        return $this->postJson('/api/login', [
            'document' => $document,
            'password' => $password,
        ]);
    }

    /** Nomes das permissões de um papel, como definidos no RoleSeeder. */
    private function rolePermissionNames(string $role): array
    {
        return Role::findByName($role)->permissions->pluck('name')->sort()->values()->all();
    }

    private function responsePermissionNames($response): array
    {
        return collect($response->json('user.permissions'))->pluck('name')->sort()->values()->all();
    }

    // ── Permissões efetivas ───────────────────────────────────────────────────

    public function test_admin_login_returns_permissions_derived_from_role(): void
    {
        $this->createUser('admin');

        $response = $this->login();

        $response->assertOk();
        $names = $this->responsePermissionNames($response);
        $this->assertSame($this->rolePermissionNames('admin'), $names);
        $this->assertContains('game_event:create', $names);
        $this->assertContains('game_event:update', $names);
        $this->assertContains('game_event:delete', $names);
        $this->assertNotContains('tournament:create', $names);
        $this->assertSame($names, array_values(array_unique($names)));
    }

    public function test_coach_login_returns_permissions_derived_from_role(): void
    {
        $this->createUser('coach');

        $response = $this->login();

        $response->assertOk();
        $names = $this->responsePermissionNames($response);
        $this->assertSame($this->rolePermissionNames('coach'), $names);
        $this->assertContains('team:update', $names);
        $this->assertContains('stadium:create', $names);
        $this->assertNotContains('game:create', $names);
    }

    public function test_player_login_returns_only_player_role_permissions(): void
    {
        $this->createUser('player');

        $response = $this->login();

        $response->assertOk();
        $this->assertSame(['player:read', 'player:update'], $this->responsePermissionNames($response));
    }

    public function test_login_returns_union_of_direct_and_role_permissions(): void
    {
        $user = $this->createUser('player');
        // player:read já vem do papel — não pode duplicar
        $user->givePermissionTo(['game:read', 'player:read']);

        $response = $this->login();

        $response->assertOk();
        $this->assertSame(['game:read', 'player:read', 'player:update'], $this->responsePermissionNames($response));
    }

    public function test_login_with_multiple_roles_does_not_duplicate_permissions(): void
    {
        $user = $this->createUser('coach');
        $user->assignRole('player');

        $response = $this->login();

        $response->assertOk();
        $names = $this->responsePermissionNames($response);
        $this->assertSame($names, array_values(array_unique($names)));
        $this->assertContains('player:read', $names);
        $this->assertContains('team:update', $names);
    }

    public function test_login_without_role_returns_empty_permissions(): void
    {
        $this->createUser();

        $response = $this->login();

        $response->assertOk()
            ->assertJsonPath('user.roles', [])
            ->assertJsonPath('user.permissions', []);
    }

    // ── Formato da resposta ───────────────────────────────────────────────────

    public function test_login_response_keeps_expected_shape(): void
    {
        $user = $this->createUser('admin');

        $response = $this->login();

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => [
                    'id', 'name', 'email', 'document',
                    'roles' => [['id', 'name', 'guard_name']],
                    'permissions' => [['id', 'name', 'guard_name']],
                ],
            ])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.roles.0.name', 'admin')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.roles.0.permissions');

        $permission = Permission::findByName('game_event:create');
        $item = collect($response->json('user.permissions'))->firstWhere('name', 'game_event:create');
        $this->assertSame([
            'id' => $permission->id,
            'name' => 'game_event:create',
            'guard_name' => $permission->guard_name,
        ], $item);
    }

    // ── Credenciais inválidas ─────────────────────────────────────────────────

    public function test_login_with_invalid_password_returns_401(): void
    {
        $this->createUser('admin');

        $response = $this->login(password: 'senha-errada');

        $response->assertStatus(401)
            ->assertExactJson(['message' => 'CPF ou senha inválidos']);
    }

    public function test_login_with_unknown_document_returns_401(): void
    {
        $response = $this->login(document: '999.999.999-99');

        $response->assertStatus(401)
            ->assertExactJson(['message' => 'CPF ou senha inválidos']);
    }

    // ── Performance ──────────────────────────────────────────────────────────

    public function test_login_query_count_is_small_and_constant_regardless_of_roles(): void
    {
        $this->createUser('player', '11111111111');
        $multiRole = $this->createUser('coach', '22222222222');
        $multiRole->assignRole(['player', 'admin']);
        $multiRole->givePermissionTo('tournament:create');

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->login('111.111.111-11')->assertOk();
        $singleRoleQueries = $queries;

        $queries = 0;
        $this->login('222.222.222-22')->assertOk();
        $multiRoleQueries = $queries;

        $this->assertLessThanOrEqual(6, $singleRoleQueries);
        $this->assertSame($singleRoleQueries, $multiRoleQueries);
    }
}
