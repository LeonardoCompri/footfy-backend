<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
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

    private function rolePermissionNames(string $role): array
    {
        return Role::findByName($role)->permissions->pluck('name')->sort()->values()->all();
    }

    private function responsePermissionNames($response): array
    {
        return collect($response->json('user.permissions'))->pluck('name')->sort()->values()->all();
    }

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
        $this->assertContains('player:create', $names);
        $this->assertContains('player:update', $names);
        $this->assertContains('player:delete', $names);
        $this->assertNotContains('team:update', $names);
        $this->assertNotContains('stadium:create', $names);
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
        $this->assertContains('player:create', $names);
    }

    public function test_login_without_role_returns_empty_permissions(): void
    {
        $this->createUser();

        $response = $this->login();

        $response->assertOk()
            ->assertJsonPath('user.roles', [])
            ->assertJsonPath('user.permissions', []);
    }

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

    public function test_coach_login_returns_linked_team(): void
    {
        $team = Team::factory()->create();
        $this->createUser('coach')->update(['team_id' => $team->id]);

        $this->login()
            ->assertOk()
            ->assertJsonPath('user.team', ['id' => $team->id, 'name' => $team->name, 'logo' => null])
            ->assertJsonPath('user.player', null);
    }

    public function test_player_login_returns_linked_player_and_its_team(): void
    {
        $team = Team::factory()->create(['logo' => 'teams/escudo.png']);
        $user = $this->createUser('player');
        $player = Player::factory()->create(['team_id' => $team->id, 'user_id' => $user->id]);

        $this->login()
            ->assertOk()
            ->assertJsonPath('user.team.id', $team->id)
            ->assertJsonPath('user.team.logo', $team->logoUrl())
            ->assertJsonPath('user.player', ['id' => $player->id, 'name' => $player->name, 'team_id' => $team->id]);
    }

    public function test_admin_login_returns_null_team(): void
    {
        $this->createUser('admin');

        $this->login()
            ->assertOk()
            ->assertJsonPath('user.team', null)
            ->assertJsonPath('user.player', null);
    }

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

    private function exhaustLoginAttempts(string $document = '123.456.789-00'): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->login($document, 'senha-errada')->assertStatus(401);
        }
    }

    public function test_sixth_login_attempt_with_the_same_document_is_throttled(): void
    {
        $this->createUser('admin');

        $this->exhaustLoginAttempts();

        $this->login()
            ->assertStatus(429)
            ->assertHeader('Retry-After', 60)
            ->assertExactJson(['message' => 'Muitas tentativas de login. Aguarde um minuto e tente novamente.']);
    }

    public function test_login_throttle_is_counted_per_document(): void
    {
        $this->createUser('admin');
        $this->createUser('admin', '98765432100');

        $this->exhaustLoginAttempts();

        $this->login('987.654.321-00')->assertOk();
        $this->login()->assertStatus(429);
    }

    public function test_masked_and_unmasked_document_share_the_same_counter(): void
    {
        $this->createUser('admin');

        foreach (['123.456.789-00', '12345678900', '123456789-00', '123.456.78900', '12345678900'] as $document) {
            $this->login($document, 'senha-errada')->assertStatus(401);
        }

        $this->login('12345678900')->assertStatus(429);
    }

    public function test_valid_login_within_the_limit_succeeds_and_counts_as_an_attempt(): void
    {
        $this->createUser('admin');

        foreach (range(1, 4) as $attempt) {
            $this->login(password: 'senha-errada')->assertStatus(401);
        }

        $this->login()->assertOk()->assertJsonStructure(['token']);
        $this->login()->assertStatus(429);
    }

    public function test_login_is_released_after_sixty_seconds(): void
    {
        $this->createUser('admin');

        $this->exhaustLoginAttempts();
        $this->login()->assertStatus(429);

        $this->travel(59)->seconds();
        $this->login()->assertStatus(429);

        $this->travel(2)->seconds();
        $this->login()->assertOk();
    }

    public function test_login_throttle_limit_comes_from_config(): void
    {
        config(['auth.login_throttle.per_minute' => 2]);
        $this->createUser('admin');

        $this->login(password: 'senha-errada')->assertStatus(401);
        $this->login(password: 'senha-errada')->assertStatus(401);
        $this->login()->assertStatus(429);
    }

    public function test_requests_without_document_are_not_throttled(): void
    {
        foreach (range(1, 7) as $attempt) {
            $this->postJson('/api/login', ['password' => 'password'])->assertUnprocessable();
            $this->postJson('/api/login', ['document' => '', 'password' => 'password'])->assertUnprocessable();
            $this->postJson('/api/login', ['document' => ['123'], 'password' => 'password'])->assertUnprocessable();
        }

        $this->createUser('admin');
        $this->login()->assertOk();
    }

    public function test_login_throttle_is_not_shared_with_other_throttled_routes(): void
    {
        $master = $this->createUser('master');
        $league = League::factory()->for(Championship::factory())->create();
        Sanctum::actingAs($master);

        foreach (range(1, 5) as $attempt) {
            $this->get('/api/audit-logs/export', ['Accept' => 'application/json'])->assertOk()->streamedContent();
            $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'errada'])->assertUnprocessable();
        }

        $this->login()->assertOk();

        foreach (range(1, 4) as $attempt) {
            $this->login(password: 'senha-errada')->assertStatus(401);
        }

        $this->login()->assertStatus(429);

        $this->get('/api/audit-logs/export', ['Accept' => 'application/json'])->assertStatus(429);
        $this->deleteJson('/api/championships/'.$league->championship_id, ['password' => 'errada'])->assertUnprocessable();
    }
}
