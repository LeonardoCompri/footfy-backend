<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

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

    /** Usuário com o papel nas ligas informadas (coach/player via time, ver LinksUsersToLeagues). */
    private function createUserWithRole(string $role, array $leagueIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        if ($leagueIds) {
            $this->linkUserToLeagues($user, $leagueIds);
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

    public static function invalidLeagueIdQueryProvider(): array
    {
        return [
            'array' => ['league_id[]=1'],
            'sufixo não numérico' => ['league_id=1abc'],
            'texto' => ['league_id=abc'],
            'hexadecimal' => ['league_id=0x1'],
            'notação científica' => ['league_id=1e0'],
            'negativo' => ['league_id=-1'],
            'sinal de mais' => ['league_id=%2B1'],
        ];
    }

    #[DataProvider('invalidLeagueIdQueryProvider')]
    public function test_admin_invalid_league_id_filter_returns_422(string $query): void
    {
        $admin = $this->authenticateAsAdmin();
        $admin->leagues()->attach(League::factory()->create()->id);

        $this->getJson("/api/users?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('league_id');
    }

    #[DataProvider('invalidLeagueIdQueryProvider')]
    public function test_master_invalid_league_id_filter_returns_422(string $query): void
    {
        $this->authenticate();
        League::factory()->create(['id' => 1]);

        $this->getJson("/api/users?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('league_id');
    }

    public function test_admin_filter_by_nonexistent_league_keeps_access_message(): void
    {
        $admin = $this->authenticateAsAdmin();
        $admin->leagues()->attach(League::factory()->create()->id);

        $this->getJson('/api/users?league_id=999999')
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a essa liga.']);
    }

    // O TrimStrings global remove espaços das pontas antes da validação: " 1" vira "1".
    public function test_league_id_with_surrounding_spaces_is_trimmed(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);

        $this->getJson("/api/users?league_id=%20{$league->id}%20")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $admin->id);
    }

    public function test_master_filter_by_nonexistent_league_returns_empty_list(): void
    {
        $this->authenticate();
        $this->createUserWithRole('admin', [League::factory()->create()->id]);

        $this->getJson('/api/users?league_id=999999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── Regressão: {id}/{lid} só aceitam dígitos ──────────────────────────────

    public static function nonNumericIdSuffixProvider(): array
    {
        return [
            'sufixo texto' => ['abc'],
            'hexadecimal' => ['x1'],
            'notação científica' => ['e0'],
        ];
    }

    #[DataProvider('nonNumericIdSuffixProvider')]
    public function test_show_with_non_numeric_id_returns_404(string $suffix): void
    {
        $master = $this->authenticate();

        $this->getJson("/api/users/{$master->id}{$suffix}")->assertNotFound();
    }

    #[DataProvider('nonNumericIdSuffixProvider')]
    public function test_admin_show_own_profile_with_non_numeric_id_returns_404(string $suffix): void
    {
        $admin = $this->authenticateAsAdmin();

        $this->getJson("/api/users/{$admin->id}{$suffix}")->assertNotFound();
    }

    #[DataProvider('nonNumericIdSuffixProvider')]
    public function test_update_with_non_numeric_id_returns_404(string $suffix): void
    {
        $admin = $this->authenticateAsAdmin();

        $this->putJson("/api/users/{$admin->id}{$suffix}", ['name' => 'Outro Nome'])->assertNotFound();
        $this->assertNotSame('Outro Nome', $admin->fresh()->name);
    }

    public function test_destroy_with_non_numeric_id_returns_404(): void
    {
        $this->authenticate();
        $target = $this->createUserWithRole('coach');

        $this->deleteJson("/api/users/{$target->id}abc")->assertNotFound();
        $this->assertNotNull($target->fresh());
    }

    public function test_remove_league_with_non_numeric_lid_returns_404(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $target = $this->createUserWithRole('admin', [$league->id]);

        $this->deleteJson("/api/users/{$target->id}/leagues/{$league->id}abc")->assertNotFound();
        $this->assertTrue($target->fresh()->leagues->contains($league->id));
    }

    public function test_sync_leagues_with_non_numeric_id_returns_404(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $target = $this->createUserWithRole('coach');

        $this->postJson("/api/users/{$target->id}abc/leagues", ['league_ids' => [$league->id]])->assertNotFound();
        $this->assertCount(0, $target->fresh()->leagues);
    }

    // ── Regressão: ações exclusivas de master ─────────────────────────────────

    public function test_admin_cannot_remove_league_from_user(): void
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);
        $target = $this->createUserWithRole('admin', [$league->id]);

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

    public function test_admin_cannot_create_admin_user(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/users', $this->validUserPayload());

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['document' => '98765432100']);
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

    // ── Vínculo coach → time e player → jogador (FOOT-0047) ──────────────────

    /** Time vinculado às ligas informadas. */
    private function teamInLeagues(array $leagueIds): Team
    {
        $team = Team::factory()->create();
        $team->leagues()->attach($leagueIds);

        return $team;
    }

    /** Admin autenticado numa liga nova; devolve [admin, liga]. */
    private function adminWithLeague(): array
    {
        $admin = $this->authenticateAsAdmin();
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);

        return [$admin, $league];
    }

    public function test_admin_can_create_coach_with_team_of_own_league(): void
    {
        [, $league] = $this->adminWithLeague();
        $team = $this->teamInLeagues([$league->id]);

        $response = $this->postJson('/api/users', $this->validUserPayload(['role' => 'coach', 'team_id' => $team->id]));

        $response->assertCreated()
            ->assertJsonPath('data.roles.0', 'coach')
            ->assertJsonPath('data.team.id', $team->id)
            ->assertJsonPath('data.player', null);
        $coach = User::where('document', '98765432100')->first();
        $this->assertSame($team->id, $coach->team_id);
        $this->assertSame([$league->id], $coach->leagueIds());

        // E passa a aparecer na listagem do admin (liga derivada do time).
        $ids = collect($this->getJson('/api/users')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($coach->id, $ids);
    }

    public function test_admin_can_create_player_linked_to_player_of_own_league(): void
    {
        [, $league] = $this->adminWithLeague();
        $team = $this->teamInLeagues([$league->id]);
        $player = Player::factory()->create(['team_id' => $team->id]);

        $response = $this->postJson('/api/users', $this->validUserPayload(['role' => 'player', 'player_id' => $player->id]));

        $response->assertCreated()
            ->assertJsonPath('data.player.id', $player->id)
            ->assertJsonPath('data.team.id', $team->id);
        $user = User::where('document', '98765432100')->first();
        $this->assertSame($user->id, $player->fresh()->user_id);
        $this->assertSame([$league->id], $user->leagueIds());
    }

    public function test_admin_cannot_create_coach_with_team_of_other_league(): void
    {
        $this->adminWithLeague();
        $team = $this->teamInLeagues([League::factory()->create()->id]);

        $this->postJson('/api/users', $this->validUserPayload(['role' => 'coach', 'team_id' => $team->id]))
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);
        $this->assertDatabaseMissing('users', ['document' => '98765432100']);
    }

    public function test_admin_cannot_create_player_linked_to_player_of_other_league_or_without_team(): void
    {
        $this->adminWithLeague();
        $outside = Player::factory()->create(['team_id' => $this->teamInLeagues([League::factory()->create()->id])->id]);
        $withoutTeam = Player::factory()->create(['team_id' => null]);

        foreach ([$outside, $withoutTeam] as $player) {
            $this->postJson('/api/users', $this->validUserPayload(['role' => 'player', 'player_id' => $player->id]))
                ->assertStatus(422)
                ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);
        }
        $this->assertDatabaseMissing('users', ['document' => '98765432100']);
    }

    public static function privilegedRolesProvider(): array
    {
        return ['admin' => ['admin'], 'master' => ['master']];
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_admin_cannot_create_admin_or_master(string $role): void
    {
        $this->adminWithLeague();

        $this->postJson('/api/users', $this->validUserPayload(['role' => $role]))
            ->assertForbidden()
            ->assertJson(['message' => 'Você não tem acesso a este recurso.']);
        $this->assertDatabaseMissing('users', ['document' => '98765432100']);
    }

    public function test_admin_must_link_team_or_player_when_creating_coach_or_player(): void
    {
        $this->adminWithLeague();

        $this->postJson('/api/users', $this->validUserPayload(['role' => 'coach']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['team_id' => 'Informe o time do coach.']);
        $this->postJson('/api/users', $this->validUserPayload(['role' => 'player']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['player_id' => 'Informe o jogador vinculado ao usuário.']);
    }

    public function test_master_can_create_coach_without_team(): void
    {
        $this->authenticate();

        $this->postJson('/api/users', $this->validUserPayload(['role' => 'coach']))
            ->assertCreated()
            ->assertJsonPath('data.team', null);
    }

    public function test_master_can_create_coach_with_team_of_any_league(): void
    {
        $this->authenticate();
        $team = $this->teamInLeagues([League::factory()->create()->id]);

        $this->postJson('/api/users', $this->validUserPayload(['role' => 'coach', 'team_id' => $team->id]))
            ->assertCreated()
            ->assertJsonPath('data.team.id', $team->id);
    }

    public static function linkNotAllowedForRoleProvider(): array
    {
        return [
            'time em admin' => ['admin', 'team_id'],
            'time em master' => ['master', 'team_id'],
            'time em player' => ['player', 'team_id'],
            'jogador em admin' => ['admin', 'player_id'],
            'jogador em coach' => ['coach', 'player_id'],
        ];
    }

    #[DataProvider('linkNotAllowedForRoleProvider')]
    public function test_store_rejects_link_that_does_not_match_role(string $role, string $field): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $value = $field === 'team_id' ? $team->id : Player::factory()->create(['team_id' => $team->id])->id;

        $this->postJson('/api/users', $this->validUserPayload(['role' => $role, $field => $value]))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
        $this->assertDatabaseMissing('users', ['document' => '98765432100']);
    }

    public function test_admin_cannot_link_team_to_himself(): void
    {
        [$admin, $league] = $this->adminWithLeague();
        $team = $this->teamInLeagues([$league->id]);

        $this->putJson("/api/users/{$admin->id}", ['team_id' => $team->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['team_id' => 'Só usuários com papel coach podem ser vinculados a um time.']);
        $this->assertNull($admin->fresh()->team_id);
    }

    public function test_team_cannot_be_linked_to_two_coaches(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        User::factory()->create(['team_id' => $team->id])->assignRole('coach');

        $this->postJson('/api/users', $this->validUserPayload(['role' => 'coach', 'team_id' => $team->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['team_id' => 'Este time já está vinculado a outro coach.']);
    }

    public function test_player_cannot_be_linked_to_two_users(): void
    {
        $this->authenticate();
        $other = $this->createUserWithRole('player');
        $player = Player::factory()->create(['team_id' => Team::factory()->create()->id, 'user_id' => $other->id]);

        $this->postJson('/api/users', $this->validUserPayload(['role' => 'player', 'player_id' => $player->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['player_id' => 'Este jogador já está vinculado a outro usuário.']);
        $this->assertSame($other->id, $player->fresh()->user_id);
    }

    public function test_removed_player_cannot_be_linked(): void
    {
        $this->authenticate();
        $player = Player::factory()->create(['team_id' => Team::factory()->create()->id]);
        $player->delete();

        $this->postJson('/api/users', $this->validUserPayload(['role' => 'player', 'player_id' => $player->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['player_id' => 'O jogador informado foi removido ou não existe.']);
    }

    public function test_link_fields_sent_as_array_return_422(): void
    {
        $this->authenticate();
        $player = Player::factory()->create(['team_id' => Team::factory()->create()->id]);

        $this->postJson('/api/users', $this->validUserPayload(['role' => 'player', 'player_id' => [$player->id]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('player_id');
        $this->postJson('/api/users', $this->validUserPayload(['role' => ['coach'], 'team_id' => 1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_admin_can_change_team_of_coach_within_own_leagues_only(): void
    {
        [, $league] = $this->adminWithLeague();
        $coach = $this->createUserWithRole('coach', [$league->id]);
        $newTeam = $this->teamInLeagues([$league->id]);
        $outsideTeam = $this->teamInLeagues([League::factory()->create()->id]);

        $this->putJson("/api/users/{$coach->id}", ['team_id' => $outsideTeam->id])
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);
        $this->putJson("/api/users/{$coach->id}", ['team_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_id');

        $this->putJson("/api/users/{$coach->id}", ['team_id' => $newTeam->id])
            ->assertOk()
            ->assertJsonPath('data.team.id', $newTeam->id);
        $this->assertSame($newTeam->id, $coach->fresh()->team_id);
    }

    public function test_master_can_switch_linked_player_and_unlink(): void
    {
        $this->authenticate();
        $user = $this->createUserWithRole('player', [League::factory()->create()->id]);
        $old = $user->player;
        $new = Player::factory()->create(['team_id' => Team::factory()->create()->id]);

        $this->putJson("/api/users/{$user->id}", ['player_id' => $new->id])
            ->assertOk()
            ->assertJsonPath('data.player.id', $new->id);
        $this->assertNull($old->fresh()->user_id);
        $this->assertSame($user->id, $new->fresh()->user_id);

        $this->putJson("/api/users/{$user->id}", ['player_id' => null])
            ->assertOk()
            ->assertJsonPath('data.player', null)
            ->assertJsonPath('data.team', null);
        $this->assertNull($new->fresh()->user_id);
    }

    public function test_changing_role_clears_previous_link(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $coach = $this->createUserWithRole('coach', [$league->id]);
        $player = $this->createUserWithRole('player', [$league->id]);
        $linkedPlayer = $player->player;

        $this->putJson("/api/users/{$coach->id}", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.team', null);
        $this->assertNull($coach->fresh()->team_id);

        $team = Team::factory()->create();
        $this->putJson("/api/users/{$player->id}", ['role' => 'coach', 'team_id' => $team->id])
            ->assertOk()
            ->assertJsonPath('data.team.id', $team->id)
            ->assertJsonPath('data.player', null);
        $this->assertNull($linkedPlayer->fresh()->user_id);
    }

    public function test_changing_role_to_coach_drops_manual_leagues(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $admin = $this->createUserWithRole('admin', [$league->id]);
        $team = $this->teamInLeagues([$teamLeague = League::factory()->create()->id]);

        $this->putJson("/api/users/{$admin->id}", ['role' => 'coach', 'team_id' => $team->id])
            ->assertOk()
            ->assertJsonPath('data.leagues', []);

        $this->assertDatabaseMissing('user_leagues', ['user_id' => $admin->id]);
        $this->assertSame([$teamLeague], $admin->fresh()->leagueIds());
    }

    public function test_show_returns_team_and_player_links(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $coach = $this->createUserWithRole('coach', [$league->id]);
        $player = $this->createUserWithRole('player', [$league->id]);

        $this->getJson("/api/users/{$coach->id}")
            ->assertOk()
            ->assertJsonPath('data.team.id', $coach->team_id)
            ->assertJsonPath('data.team.logo', null)
            ->assertJsonPath('data.player', null);
        $this->getJson("/api/users/{$player->id}")
            ->assertOk()
            ->assertJsonPath('data.player.id', $player->player->id)
            ->assertJsonPath('data.team.id', $player->player->team_id);
        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'team', 'player', 'leagues']]]);
    }

    public function test_master_cannot_sync_leagues_of_coach_or_player(): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        foreach (['coach', 'player'] as $role) {
            $target = $this->createUserWithRole($role);

            $this->postJson("/api/users/{$target->id}/leagues", ['league_ids' => [$league->id]])
                ->assertStatus(422)
                ->assertJson(['message' => 'As ligas de coach e player vêm do time vinculado; não é possível vincular ligas manualmente.']);
            $this->assertDatabaseMissing('user_leagues', ['user_id' => $target->id]);
        }
    }

    public function test_master_can_remove_legacy_manual_league_of_coach(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $coach = $this->createUserWithRole('coach');
        $coach->leagues()->attach($league->id);

        $this->deleteJson("/api/users/{$coach->id}/leagues/{$league->id}")->assertOk();

        $this->assertDatabaseMissing('user_leagues', ['user_id' => $coach->id]);
    }

    // Ligas de coach/player vêm só do time: vínculo antigo em user_leagues não conta.
    public function test_admin_does_not_see_coach_linked_only_by_legacy_user_leagues(): void
    {
        [, $league] = $this->adminWithLeague();
        $legacy = $this->createUserWithRole('coach');
        $legacy->leagues()->attach($league->id);

        $this->getJson("/api/users/{$legacy->id}")->assertForbidden();
        $this->putJson("/api/users/{$legacy->id}", ['name' => 'Hack'])->assertForbidden();
        $ids = collect($this->getJson('/api/users')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($legacy->id, $ids);
    }

    public function test_league_ids_of_coach_and_player_come_from_team(): void
    {
        $league1 = League::factory()->create();
        $league2 = League::factory()->create();
        $team = $this->teamInLeagues([$league1->id, $league2->id]);
        $coach = User::factory()->create(['team_id' => $team->id]);
        $coach->assignRole('coach');
        $playerUser = $this->createUserWithRole('player');
        $player = Player::factory()->create(['team_id' => $team->id, 'user_id' => $playerUser->id]);
        $unlinked = $this->createUserWithRole('coach');

        $this->assertEqualsCanonicalizing([$league1->id, $league2->id], $coach->leagueIds());
        $this->assertEqualsCanonicalizing([$league1->id, $league2->id], $playerUser->leagueIds());
        $this->assertTrue($playerUser->hasLeagueId($league2->id));
        $this->assertSame([], $unlinked->leagueIds());

        // Jogador removido (soft delete): o usuário player fica sem ligas.
        $player->delete();
        $this->assertSame([], $playerUser->fresh()->leagueIds());
        $this->assertFalse($playerUser->fresh()->hasLeagueId($league1->id));
    }

    public function test_deleting_team_or_user_keeps_the_other_side_without_link(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $coach = $this->createUserWithRole('coach', [$league->id]);
        $playerUser = $this->createUserWithRole('player', [$league->id]);
        $player = $playerUser->player;

        Team::find($coach->team_id)->delete();
        $this->deleteJson("/api/users/{$playerUser->id}")->assertStatus(204);

        $this->assertNull($coach->fresh()->team_id);
        $this->assertNull($player->fresh()->user_id);
    }
}
