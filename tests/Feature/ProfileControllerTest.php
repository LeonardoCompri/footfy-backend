<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Papéis e permissões reais: PUT /me/player exige permission:player:update.
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    /** Autentica um usuário com o papel informado, sem vínculos. */
    private function authenticateAs(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function teamInLeague(): Team
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);

        return $team;
    }

    /** Autentica um player vinculado a um jogador (players.user_id) de um time numa liga. */
    private function authenticateAsLinkedPlayer(array $playerAttributes = []): array
    {
        $team = $this->teamInLeague();
        $user = $this->authenticateAs('player');
        $player = Player::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => 'Nome Antigo',
            'position' => 'ATA',
            'number' => 9,
            'birth_date' => '2000-01-01',
            'document' => '98765432100',
            ...$playerAttributes,
        ]);

        return [$user, $player, $team];
    }

    public static function rolesProvider(): array
    {
        return [
            'master' => ['master'],
            'admin' => ['admin'],
            'coach' => ['coach'],
            'player' => ['player'],
        ];
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public static function profileRoutesProvider(): array
    {
        return [
            'GET /me' => ['GET', '/api/me'],
            'PUT /me' => ['PUT', '/api/me'],
            'PUT /me/player' => ['PUT', '/api/me/player'],
        ];
    }

    #[DataProvider('profileRoutesProvider')]
    public function test_guest_cannot_access_profile_routes(string $method, string $url): void
    {
        $this->json($method, $url, ['name' => 'Intruso'])->assertUnauthorized();
    }

    // ── GET /me ───────────────────────────────────────────────────────────────

    #[DataProvider('rolesProvider')]
    public function test_any_role_can_show_own_profile(string $role): void
    {
        $user = $this->authenticateAs($role);
        User::factory()->create();

        $response = $this->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.roles', [$role])
            ->assertJsonMissingPath('data.password');
    }

    public function test_show_profile_of_coach_includes_linked_team(): void
    {
        $team = $this->teamInLeague();
        $this->authenticateAs('coach', ['team_id' => $team->id]);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.team.id', $team->id)
            ->assertJsonPath('data.player', null);
    }

    public function test_show_profile_of_player_includes_linked_player_and_team(): void
    {
        [, $player, $team] = $this->authenticateAsLinkedPlayer();

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.player.id', $player->id)
            ->assertJsonPath('data.team.id', $team->id);
    }

    // ── PUT /me ───────────────────────────────────────────────────────────────

    #[DataProvider('rolesProvider')]
    public function test_any_role_can_update_own_name_and_email(string $role): void
    {
        $user = $this->authenticateAs($role);

        $response = $this->putJson('/api/me', ['name' => 'Nome Novo', 'email' => 'novo@footfy.test']);

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Nome Novo')
            ->assertJsonPath('data.email', 'novo@footfy.test');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Nome Novo', 'email' => 'novo@footfy.test']);
    }

    public function test_update_profile_ignores_role_document_team_player_and_leagues(): void
    {
        $team = $this->teamInLeague();
        $otherTeam = $this->teamInLeague();
        $freePlayer = Player::factory()->create(['team_id' => $team->id]);
        $league = League::factory()->create();
        $user = $this->authenticateAs('coach', ['team_id' => $team->id, 'document' => '11122233344']);

        $response = $this->putJson('/api/me', [
            'name' => 'Nome Novo',
            'role' => 'master',
            'document' => '55566677788',
            'team_id' => $otherTeam->id,
            'player_id' => $freePlayer->id,
            'league_ids' => [$league->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Nome Novo')
            ->assertJsonPath('data.roles', ['coach'])
            ->assertJsonPath('data.team.id', $team->id);
        $user->refresh();
        $this->assertSame(['coach'], $user->getRoleNames()->all());
        $this->assertSame('11122233344', $user->document);
        $this->assertSame($team->id, $user->team_id);
        $this->assertNull($freePlayer->fresh()->user_id);
        $this->assertSame(0, $user->leagues()->count());
    }

    public function test_admin_sending_role_on_profile_is_ignored_not_rejected(): void
    {
        $user = $this->authenticateAs('admin');

        $this->putJson('/api/me', ['role' => 'master'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['admin']);
        $this->assertFalse($user->fresh()->hasRole('master'));
    }

    public function test_update_profile_keeps_player_link(): void
    {
        [$user, $player] = $this->authenticateAsLinkedPlayer();
        $otherPlayer = Player::factory()->create(['team_id' => $player->team_id]);

        $this->putJson('/api/me', ['name' => 'Nome Novo', 'player_id' => $otherPlayer->id])
            ->assertOk()
            ->assertJsonPath('data.player.id', $player->id);
        $this->assertSame($user->id, $player->fresh()->user_id);
        $this->assertNull($otherPlayer->fresh()->user_id);
    }

    public function test_update_profile_allows_keeping_own_email(): void
    {
        $user = $this->authenticateAs('player', ['email' => 'eu@footfy.test']);

        $this->putJson('/api/me', ['email' => 'eu@footfy.test'])
            ->assertOk()
            ->assertJsonPath('data.email', 'eu@footfy.test');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'eu@footfy.test']);
    }

    public function test_update_profile_rejects_email_of_another_user(): void
    {
        User::factory()->create(['email' => 'ocupado@footfy.test']);
        $user = $this->authenticateAs('coach');

        $this->putJson('/api/me', ['email' => 'ocupado@footfy.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Este e-mail já está em uso por outro usuário.']);
        $this->assertDatabaseMissing('users', ['id' => $user->id, 'email' => 'ocupado@footfy.test']);
    }

    public function test_update_profile_rejects_invalid_email(): void
    {
        $this->authenticateAs('player');

        $this->putJson('/api/me', ['email' => 'nao-e-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_user_can_change_password_and_login_with_it(): void
    {
        $user = $this->authenticateAs('player', ['document' => '12345678900']);

        $this->putJson('/api/me', ['password' => 'senhaNova123', 'password_confirmation' => 'senhaNova123'])
            ->assertOk();

        $this->assertTrue(Hash::check('senhaNova123', $user->fresh()->password));
        $this->postJson('/api/login', ['document' => '12345678900', 'password' => 'senhaNova123'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
        $this->postJson('/api/login', ['document' => '12345678900', 'password' => 'password'])
            ->assertUnauthorized();
    }

    public function test_password_change_requires_matching_confirmation(): void
    {
        $user = $this->authenticateAs('admin');

        $this->putJson('/api/me', ['password' => 'senhaNova123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'A confirmação da senha não confere.']);
        $this->putJson('/api/me', ['password' => 'senhaNova123', 'password_confirmation' => 'outraSenha'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_change_requires_minimum_length(): void
    {
        $this->authenticateAs('coach');

        $this->putJson('/api/me', ['password' => '123', 'password_confirmation' => '123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    // ── PUT /me/player ────────────────────────────────────────────────────────

    public function test_player_updates_only_name_and_birth_date_of_linked_player(): void
    {
        [, $player, $team] = $this->authenticateAsLinkedPlayer();
        $otherTeam = $this->teamInLeague();

        $response = $this->putJson('/api/me/player', [
            'name' => 'Nome Novo',
            'birth_date' => '1999-05-10',
            'team_id' => $otherTeam->id,
            'position' => 'GOL',
            'number' => 1,
            'document' => '11111111111',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $player->id)
            ->assertJsonPath('data.name', 'Nome Novo')
            ->assertJsonPath('data.team_id', $team->id)
            ->assertJsonPath('data.position', 'ATA')
            ->assertJsonPath('data.number', 9)
            ->assertJsonPath('data.document', '98765432100');
        $this->assertDatabaseHas('players', [
            'id' => $player->id,
            'name' => 'Nome Novo',
            'team_id' => $team->id,
            'position' => 'ATA',
            'number' => 9,
            'document' => '98765432100',
        ]);
        $this->assertSame('1999-05-10', substr((string) $player->fresh()->birth_date, 0, 10));
    }

    public function test_player_can_clear_birth_date(): void
    {
        [, $player] = $this->authenticateAsLinkedPlayer();

        $this->putJson('/api/me/player', ['birth_date' => null])->assertOk();

        $this->assertNull($player->fresh()->birth_date);
        $this->assertSame('Nome Antigo', $player->fresh()->name);
    }

    public function test_update_own_player_validates_fields(): void
    {
        [, $player] = $this->authenticateAsLinkedPlayer();

        $this->putJson('/api/me/player', ['name' => null, 'birth_date' => 'nao-e-data'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'birth_date']);
        $this->assertSame('Nome Antigo', $player->fresh()->name);
    }

    public function test_removed_player_cannot_update_own_player(): void
    {
        [, $player] = $this->authenticateAsLinkedPlayer();
        $player->delete();

        $this->putJson('/api/me/player', ['name' => 'Nome Novo'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Você foi removido do elenco.');
        $this->assertSame('Nome Antigo', Player::withTrashed()->find($player->id)->name);
    }

    public function test_player_without_linked_player_gets_404(): void
    {
        $this->authenticateAs('player');
        $orphan = Player::factory()->create(['name' => 'Sem Dono']);

        $this->putJson('/api/me/player', ['name' => 'Nome Novo'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Nenhum jogador vinculado à sua conta.');
        $this->assertSame('Sem Dono', $orphan->fresh()->name);
    }

    // Coach (e admin/master) também têm player:update, mas não têm jogador vinculado.
    public static function rolesWithoutPlayerLinkProvider(): array
    {
        return [
            'master' => ['master'],
            'admin' => ['admin'],
            'coach' => ['coach'],
        ];
    }

    #[DataProvider('rolesWithoutPlayerLinkProvider')]
    public function test_roles_without_linked_player_get_404(string $role): void
    {
        $team = $this->teamInLeague();
        $user = $this->authenticateAs($role, $role === 'coach' ? ['team_id' => $team->id] : []);

        $this->putJson('/api/me/player', ['name' => 'Nome Novo'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Nenhum jogador vinculado à sua conta.');
        $this->assertSame(['name' => $user->name], $user->fresh()->only('name'));
    }

    public function test_user_without_player_update_permission_gets_403_on_own_player(): void
    {
        Role::findByName('player')->revokePermissionTo('player:update');
        [, $player] = $this->authenticateAsLinkedPlayer();

        $this->putJson('/api/me/player', ['name' => 'Nome Novo'])
            ->assertForbidden()
            ->assertJsonPath('message', 'User does not have the right permissions.');
        $this->assertSame('Nome Antigo', $player->fresh()->name);
    }

    // ── player:update do player x PUT /players/{id} ──────────────────────────

    public function test_player_still_cannot_update_own_player_via_players_route(): void
    {
        [, $player] = $this->authenticateAsLinkedPlayer();

        $this->putJson("/api/players/{$player->id}", ['name' => 'Nome Novo', 'number' => 10])
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem acesso a este recurso.');
        $this->assertDatabaseHas('players', ['id' => $player->id, 'name' => 'Nome Antigo', 'number' => 9]);
    }

    // Ambiente existente antes da FOOT-0048 (player só com player:read): a migration libera
    // só o PUT /me/player; PUT /players/{id} segue 403.
    public function test_permission_migration_enables_only_profile_route_for_player(): void
    {
        Role::findByName('player')->syncPermissions(['player:read']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        [$user, $player] = $this->authenticateAsLinkedPlayer();

        $this->putJson('/api/me/player', ['name' => 'Nome Novo'])->assertForbidden();

        (require database_path('migrations/2026_10_29_000000_add_player_update_permission_to_player.php'))->up();
        Sanctum::actingAs($user->fresh());

        $this->putJson('/api/me/player', ['name' => 'Nome Novo'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nome Novo');
        $this->putJson("/api/players/{$player->id}", ['name' => 'Outro Nome'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem acesso a este recurso.');
        $this->assertSame('Nome Novo', $player->fresh()->name);
    }
}
