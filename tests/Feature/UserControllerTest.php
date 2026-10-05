<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Role::create(['name' => 'master']);
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'coach']);
        Role::create(['name' => 'player']);
    }

    private function authenticate(): User
    {
        $user = User::factory()->create();
        $user->assignRole('master');
        Sanctum::actingAs($user);

        return $user;
    }

    private function authenticateAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        return $user;
    }

    private function createUserWithRole(string $role, array $leagueIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        if ($leagueIds) {
            $user->leagues()->attach($leagueIds);
        }

        return $user;
    }

    private function validUserPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Novo Usuário',
            'email' => 'novo@footfy.com',
            'document' => '98765432100',
            'password' => '123456',
            'role' => 'admin',
        ], $overrides);
    }

    // ── Auth e controle de acesso ─────────────────────────────────────────────

    public function test_guest_cannot_list_users(): void
    {
        $response = $this->getJson('/api/users');

        $response->assertStatus(401);
    }

    public function test_admin_can_list_users(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $this->createUserWithRole('coach', [$league->id]);
        $this->createUserWithRole('player', [$league->id]);
        // fora do escopo: sem liga e de liga alheia
        User::factory()->create();
        $this->createUserWithRole('coach', [League::factory()->create()->id]);

        $response = $this->getJson('/api/users');

        // 2 da mesma liga + o próprio admin
        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_admin_can_show_user(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $target = $this->createUserWithRole('coach', [$league->id]);

        $response = $this->getJson("/api/users/{$target->id}");

        $response->assertOk()->assertJsonPath('data.id', $target->id);
    }

    public function test_admin_can_update_user_basic_data(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $target = $this->createUserWithRole('player', [$league->id]);

        $response = $this->putJson("/api/users/{$target->id}", ['name' => 'Nome Atualizado']);

        $response->assertOk()->assertJsonPath('data.name', 'Nome Atualizado');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Nome Atualizado']);
    }

    public function test_admin_cannot_change_user_role(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $target = $this->createUserWithRole('coach', [$league->id]);

        $response = $this->putJson("/api/users/{$target->id}", ['role' => 'master']);

        $response->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertTrue($target->fresh()->hasRole('coach'));
        $this->assertFalse($target->fresh()->hasRole('master'));
    }

    // ── Admin x master ────────────────────────────────────────────────────────

    public function test_admin_cannot_show_master(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $master = $this->createUserWithRole('master', [$league->id]);

        $this->getJson("/api/users/{$master->id}")
            ->assertForbidden()
            ->assertJson(['message' => 'Você não tem acesso a este recurso.']);
    }

    public function test_admin_cannot_update_master(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $master = $this->createUserWithRole('master', [$league->id]);
        $original = $master->only(['email', 'document', 'password']);

        $response = $this->putJson("/api/users/{$master->id}", [
            'email' => 'tomada@footfy.com',
            'document' => '00011122233',
            'password' => 'senha-nova',
        ]);

        $response->assertForbidden();
        $fresh = $master->fresh();
        $this->assertSame($original['email'], $fresh->email);
        $this->assertSame($original['document'], $fresh->document);
        $this->assertSame($original['password'], $fresh->password);
    }

    public function test_admin_cannot_delete_master(): void
    {
        $this->authenticateAsAdmin();
        $master = $this->createUserWithRole('master');

        $this->deleteJson("/api/users/{$master->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $master->id]);
    }

    public function test_admin_list_does_not_include_master_of_same_league(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $master = $this->createUserWithRole('master', [$league->id]);

        $ids = collect($this->getJson('/api/users')->assertOk()->json('data'))->pluck('id');

        $this->assertNotContains($master->id, $ids);
        $this->assertContains($admin->id, $ids);
    }

    // ── Admin x usuários de outra liga ────────────────────────────────────────

    public static function rolesProvider(): array
    {
        return [['coach'], ['player'], ['admin']];
    }

    #[DataProvider('rolesProvider')]
    public function test_admin_cannot_access_user_from_other_league(string $role): void
    {
        $admin = $this->authenticateAsAdmin();
        $admin->leagues()->attach(League::factory()->create()->id);
        $target = $this->createUserWithRole($role, [League::factory()->create()->id]);

        $this->getJson("/api/users/{$target->id}")->assertForbidden();
        $this->putJson("/api/users/{$target->id}", ['name' => 'Hack'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['id' => $target->id, 'name' => 'Hack']);

        $ids = collect($this->getJson('/api/users')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($target->id, $ids);
    }

    // ── Admin x coach/player/admin da mesma liga ──────────────────────────────

    #[DataProvider('rolesProvider')]
    public function test_admin_can_show_user_of_same_league(string $role): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $target = $this->createUserWithRole($role, [$league->id]);

        $this->getJson("/api/users/{$target->id}")->assertOk()->assertJsonPath('data.id', $target->id);
    }

    public function test_admin_can_update_coach_of_same_league(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $target = $this->createUserWithRole('coach', [$league->id]);

        $this->putJson("/api/users/{$target->id}", ['name' => 'Treinador Novo', 'email' => 'treinador@footfy.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Treinador Novo')
            ->assertJsonPath('data.email', 'treinador@footfy.com');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Treinador Novo', 'email' => 'treinador@footfy.com']);
    }

    public function test_admin_cannot_update_other_admin_of_same_league(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $other = $this->createUserWithRole('admin', [$league->id]);

        $this->putJson("/api/users/{$other->id}", ['name' => 'Hack'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['id' => $other->id, 'name' => 'Hack']);
    }

    // ── Admin x ele mesmo ─────────────────────────────────────────────────────

    public function test_admin_can_show_and_update_himself(): void
    {
        $admin = $this->authenticateAsAdmin();

        $this->getJson("/api/users/{$admin->id}")->assertOk()->assertJsonPath('data.id', $admin->id);
        $this->putJson("/api/users/{$admin->id}", ['name' => 'Admin Novo'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Admin Novo');
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $admin = $this->authenticateAsAdmin();

        $this->putJson("/api/users/{$admin->id}", ['role' => 'master'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertFalse($admin->fresh()->hasRole('master'));
    }

    // ── Filtro ?league_id= ────────────────────────────────────────────────────

    public function test_admin_cannot_filter_by_league_he_does_not_belong_to(): void
    {
        $admin = $this->authenticateAsAdmin();
        $admin->leagues()->attach(League::factory()->create()->id);
        $other = League::factory()->create();

        $this->getJson("/api/users?league_id={$other->id}")
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a essa liga.']);
    }

    public function test_admin_can_filter_by_own_league(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league1 = League::factory()->create();
        $league2 = League::factory()->create();
        $admin->leagues()->attach([$league1->id, $league2->id]);
        $inLeague1 = $this->createUserWithRole('coach', [$league1->id]);
        $inLeague2 = $this->createUserWithRole('player', [$league2->id]);

        $ids = collect($this->getJson("/api/users?league_id={$league1->id}")->assertOk()->json('data'))->pluck('id');

        $this->assertContains($inLeague1->id, $ids);
        $this->assertNotContains($inLeague2->id, $ids);
        $this->assertContains($admin->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_master_can_filter_users_by_league(): void
    {
        $master = $this->authenticate();
        $league = League::factory()->create();
        $inLeague = $this->createUserWithRole('admin', [$league->id]);
        $this->createUserWithRole('coach', [League::factory()->create()->id]);
        User::factory()->create();

        $this->getJson("/api/users?league_id={$league->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inLeague->id);
    }

    public function test_master_without_filter_sees_all_users(): void
    {
        $this->authenticate();
        $this->createUserWithRole('master');
        $this->createUserWithRole('admin', [League::factory()->create()->id]);
        User::factory()->create();

        $this->getJson('/api/users')->assertOk()->assertJsonCount(4, 'data');
    }

    // ── Regressão: ações exclusivas de master ─────────────────────────────────

    public function test_admin_cannot_remove_league_from_user(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $target = $this->createUserWithRole('coach', [$league->id]);

        $this->deleteJson("/api/users/{$target->id}/leagues/{$league->id}")->assertForbidden();
        $this->assertDatabaseHas('user_leagues', ['user_id' => $target->id, 'league_id' => $league->id]);
    }

    public function test_admin_cannot_delete_user_of_same_league(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $target = $this->createUserWithRole('player', [$league->id]);

        $this->deleteJson("/api/users/{$target->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_master_can_update_master(): void
    {
        $this->authenticate();
        $other = $this->createUserWithRole('master');

        $this->putJson("/api/users/{$other->id}", ['name' => 'Outro Master'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Outro Master');
    }

    public function test_admin_cannot_create_user(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/users', $this->validUserPayload());

        $response->assertForbidden();
    }

    // ── Master: CRUD de usuários ──────────────────────────────────────────────

    public function test_master_can_list_users(): void
    {
        $this->authenticate();
        User::factory()->count(2)->create();

        $response = $this->getJson('/api/users');

        // +1 porque o próprio master também é listado
        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_master_can_create_user_with_admin_role(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/users', $this->validUserPayload(['role' => 'admin']));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Novo Usuário')
            ->assertJsonPath('data.roles.0', 'admin');

        $this->assertDatabaseHas('users', ['document' => '98765432100']);
    }

    public function test_master_can_create_user_with_master_role(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/users', $this->validUserPayload(['role' => 'master']));

        $response->assertCreated()->assertJsonPath('data.roles.0', 'master');
    }

    public function test_master_can_show_user(): void
    {
        $this->authenticate();
        $target = User::factory()->create();

        $response = $this->getJson("/api/users/{$target->id}");

        $response->assertOk()->assertJsonPath('data.id', $target->id);
    }

    public function test_master_can_update_user_name(): void
    {
        $this->authenticate();
        $target = User::factory()->create();

        $response = $this->putJson("/api/users/{$target->id}", ['name' => 'Nome Atualizado']);

        $response->assertOk()->assertJsonPath('data.name', 'Nome Atualizado');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Nome Atualizado']);
    }

    public function test_master_can_update_user_role(): void
    {
        $this->authenticate();
        $target = User::factory()->create();
        $target->assignRole('admin');

        $response = $this->putJson("/api/users/{$target->id}", ['role' => 'coach']);

        $response->assertOk();
        $this->assertTrue($target->fresh()->hasRole('coach'));
        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    public function test_master_can_delete_user(): void
    {
        $this->authenticate();
        $target = User::factory()->create();

        $response = $this->deleteJson("/api/users/{$target->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    // ── Validações de criação ─────────────────────────────────────────────────

    public function test_store_fails_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/users', $this->validUserPayload(['name' => '']));

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_store_fails_without_password(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/users', $this->validUserPayload(['password' => '']));

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_store_fails_with_invalid_role(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/users', $this->validUserPayload(['role' => 'superuser']));

        $response->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_store_fails_with_document_shorter_than_11_digits(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/users', $this->validUserPayload(['document' => '12345']));

        $response->assertStatus(422)->assertJsonValidationErrors('document');
    }

    public function test_store_fails_with_duplicate_document(): void
    {
        $this->authenticate();
        User::factory()->create(['document' => '11122233344']);

        $response = $this->postJson('/api/users', $this->validUserPayload(['document' => '11122233344']));

        $response->assertStatus(422)->assertJsonValidationErrors('document');
    }

    public function test_store_fails_with_duplicate_email(): void
    {
        $this->authenticate();
        User::factory()->create(['email' => 'existing@footfy.com']);

        $response = $this->postJson('/api/users', $this->validUserPayload(['email' => 'existing@footfy.com']));

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_show_returns_error_when_user_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/users/999');

        $response->assertStatus(422)->assertJson(['message' => 'Usuário não encontrado!']);
    }

    // ── Associação de ligas ────────────────────────────────────────────────────

    public function test_master_can_sync_leagues_to_admin(): void
    {
        $this->authenticate();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $league1 = League::factory()->create();
        $league2 = League::factory()->create();

        $response = $this->postJson("/api/users/{$admin->id}/leagues", [
            'league_ids' => [$league1->id, $league2->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('user_leagues', ['user_id' => $admin->id, 'league_id' => $league1->id]);
        $this->assertDatabaseHas('user_leagues', ['user_id' => $admin->id, 'league_id' => $league2->id]);
    }

    public function test_master_can_remove_league_from_admin(): void
    {
        $this->authenticate();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);

        $response = $this->deleteJson("/api/users/{$admin->id}/leagues/{$league->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_leagues', [
            'user_id' => $admin->id,
            'league_id' => $league->id,
        ]);
    }

    public function test_sync_leagues_fails_with_invalid_league_id(): void
    {
        $this->authenticate();
        $admin = User::factory()->create();

        $response = $this->postJson("/api/users/{$admin->id}/leagues", [
            'league_ids' => [9999],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('league_ids.0');
    }

    public function test_sync_leagues_fails_without_league_ids(): void
    {
        $this->authenticate();
        $admin = User::factory()->create();

        $response = $this->postJson("/api/users/{$admin->id}/leagues", []);

        $response->assertStatus(422)->assertJsonValidationErrors('league_ids');
    }

    public function test_admin_cannot_sync_leagues(): void
    {
        $this->authenticateAsAdmin();
        $target = User::factory()->create();
        $league = League::factory()->create();

        $response = $this->postJson("/api/users/{$target->id}/leagues", [
            'league_ids' => [$league->id],
        ]);

        $response->assertForbidden();
    }

    public function test_admin_cannot_delete_user(): void
    {
        $this->authenticateAsAdmin();
        $target = User::factory()->create();

        $response = $this->deleteJson("/api/users/{$target->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }
}
