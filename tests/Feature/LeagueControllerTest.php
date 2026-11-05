<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\Player;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\TechnicalCommitteeMember;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class LeagueControllerTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        // Papéis e permissões reais do RoleSeeder: as rotas de escrita exigem `permission:`.
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    private function authenticate(): User
    {
        $user = User::factory()->create();
        $user->assignRole('master');
        Sanctum::actingAs($user);

        return $user;
    }

    private function authenticateAsAdmin(array $leagueIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        if (! empty($leagueIds)) {
            $user->leagues()->sync($leagueIds);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /** Autentica com um papel do RoleSeeder (ex.: coach, player) vinculado às ligas informadas (coach/player via time). */
    private function authenticateAs(string $role, array $leagueIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        if (! empty($leagueIds)) {
            $this->linkUserToLeagues($user, $leagueIds);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_guest_cannot_list_leagues(): void
    {
        $response = $this->getJson('/api/leagues');

        $response->assertStatus(401);
    }

    // ── Master: acesso irrestrito ─────────────────────────────────────────────

    public function test_master_index_returns_all_leagues(): void
    {
        $this->authenticate();
        League::factory()->count(2)->create();

        $response = $this->getJson('/api/leagues');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_master_can_create_league(): void
    {
        $this->authenticate();

        $championship = Championship::factory()->create();

        $response = $this->postJson('/api/leagues', [
            'championship_id' => $championship->id,
            'name' => 'Campeonato Teste',
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-01',
            'organizer' => 'Federação Teste',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Campeonato Teste');
        $this->assertDatabaseHas('leagues', ['name' => 'Campeonato Teste']);
    }

    public function test_master_can_show_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        $response = $this->getJson("/api/leagues/{$league->id}");

        $response->assertOk()->assertJsonPath('data.id', $league->id);
    }

    public function test_master_can_update_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['organizer' => 'Antigo']);

        $response = $this->putJson("/api/leagues/{$league->id}", ['organizer' => 'Novo']);

        $response->assertOk()->assertJsonPath('data.organizer', 'Novo');
        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'organizer' => 'Novo']);
    }

    public function test_master_can_delete_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        $response = $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'password']);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('leagues', ['id' => $league->id]);
    }

    // ── Destroy: senha do master ──────────────────────────────────────────────

    public function test_destroy_without_password_returns_422_and_keeps_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        $response = $this->deleteJson("/api/leagues/{$league->id}");

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertDatabaseHas('leagues', ['id' => $league->id]);
    }

    public function test_destroy_with_wrong_password_returns_422_and_keeps_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        $response = $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'errada']);

        $response->assertStatus(422)->assertJsonPath('errors.password.0', 'Senha incorreta.');
        $this->assertDatabaseHas('leagues', ['id' => $league->id]);
    }

    public function test_destroy_with_correct_password_cascades_and_preserves_teams_and_users(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id]);
        $other = League::factory()->create(['championship_id' => $championship->id]);
        $home = Team::factory()->create();
        $away = Team::factory()->create();
        $home->leagues()->attach([$league->id, $other->id]);
        $away->leagues()->attach($league->id);
        $player = Player::factory()->create(['team_id' => $home->id]);
        $member = TechnicalCommitteeMember::factory()->create(['team_id' => $home->id]);
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'stadium_id' => $stadium->id,
        ]);
        $event = GameEvent::factory()->create([
            'game_id' => $game->id,
            'league_id' => $league->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->leagues()->attach([$league->id, $other->id]);

        $response = $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'password']);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('leagues', ['id' => $league->id]);
        $this->assertDatabaseMissing('games', ['id' => $game->id]);
        $this->assertDatabaseMissing('game_events', ['id' => $event->id]);
        $this->assertDatabaseMissing('stadiums', ['id' => $stadium->id]);
        $this->assertDatabaseMissing('league_team', ['league_id' => $league->id]);
        $this->assertDatabaseMissing('user_leagues', ['league_id' => $league->id]);
        // Preservados: campeonato, outra liga e seus vínculos, times, elenco, comissão e usuários.
        $this->assertDatabaseHas('championships', ['id' => $championship->id]);
        $this->assertDatabaseHas('leagues', ['id' => $other->id]);
        $this->assertDatabaseHas('league_team', ['league_id' => $other->id, 'team_id' => $home->id]);
        $this->assertDatabaseHas('user_leagues', ['league_id' => $other->id, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('teams', ['id' => $home->id]);
        $this->assertDatabaseHas('teams', ['id' => $away->id]);
        $this->assertDatabaseHas('players', ['id' => $player->id, 'team_id' => $home->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('technical_committee_members', ['id' => $member->id, 'team_id' => $home->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_master_can_delete_the_last_league_of_a_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id]);

        $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'password'])->assertStatus(204);

        $this->assertDatabaseHas('championships', ['id' => $championship->id]);
        $this->getJson("/api/championships/{$championship->id}")->assertOk()->assertJsonPath('data.leagues', []);
    }

    public function test_destroy_is_throttled_after_five_attempts(): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'errada'])->assertStatus(422);
        }

        $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'password'])->assertStatus(429);
        $this->assertDatabaseHas('leagues', ['id' => $league->id]);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/leagues/999');

        $response->assertStatus(422)->assertJson(['message' => 'Liga não encontrada!']);
    }

    // ── Validações ────────────────────────────────────────────────────────────

    public function test_store_fails_validation_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/leagues', []);

        $response->assertStatus(422);
    }

    public function test_store_fails_validation_without_championship(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/leagues', ['name' => 'Liga Sem Campeonato']);

        $response->assertStatus(422)->assertJsonValidationErrors(['championship_id']);
        $this->assertDatabaseCount('leagues', 0);
    }

    public function test_store_fails_when_end_date_is_before_start_date(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/leagues', [
            'name' => 'Campeonato Teste',
            'start_date' => '2026-06-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertStatus(422);
    }

    // ── Admin: acesso restrito ao seu escopo ──────────────────────────────────

    public function test_admin_index_only_sees_assigned_leagues(): void
    {
        $assigned = League::factory()->create();
        $unassigned = League::factory()->create();

        $this->authenticateAsAdmin([$assigned->id]);

        $response = $this->getJson('/api/leagues');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id);
    }

    public function test_admin_can_show_assigned_league(): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/leagues/{$league->id}");

        $response->assertOk()->assertJsonPath('data.id', $league->id);
    }

    public function test_admin_cannot_show_unassigned_league(): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin(); // sem ligas associadas

        $response = $this->getJson("/api/leagues/{$league->id}");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_create_league(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/leagues', ['name' => 'Novo']);

        $response->assertForbidden();
    }

    public function test_admin_can_update_assigned_league(): void
    {
        $league = League::factory()->create(['organizer' => 'Antigo']);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->putJson("/api/leagues/{$league->id}", ['organizer' => 'Novo']);

        $response->assertOk()->assertJsonPath('data.organizer', 'Novo');
    }

    public function test_admin_cannot_update_unassigned_league(): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->putJson("/api/leagues/{$league->id}", ['organizer' => 'X']);

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_delete_league(): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'password']);

        $response->assertForbidden();
        $this->assertDatabaseHas('leagues', ['id' => $league->id]);
    }

    public function test_coach_cannot_delete_league(): void
    {
        $league = League::factory()->create();
        $this->authenticateAs('coach', [$league->id]);

        $response = $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'password']);

        $response->assertForbidden();
        $this->assertDatabaseHas('leagues', ['id' => $league->id]);
    }

    public function test_admin_with_no_leagues_sees_empty_list(): void
    {
        League::factory()->count(3)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/leagues');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    // ── Eager-load de championship ────────────────────────────────────────────

    public function test_master_index_eager_loads_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        League::factory()->create(['championship_id' => $championship->id]);

        $response = $this->getJson('/api/leagues');

        $response->assertOk()->assertJsonPath('data.0.championship.id', $championship->id);
    }

    public function test_admin_index_eager_loads_championship(): void
    {
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id]);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson('/api/leagues');

        $response->assertOk()->assertJsonPath('data.0.championship.id', $championship->id);
    }

    // ── Rotas públicas ────────────────────────────────────────────────────────

    public function test_guest_can_show_league_via_public_route_with_related_data(): void
    {
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id]);
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);
        $game = Game::factory()->create(['league_id' => $league->id]);
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);

        $response = $this->getJson("/api/public/leagues/{$league->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $league->id)
            ->assertJsonPath('data.championship.id', $championship->id)
            ->assertJsonPath('data.teams.0.id', $team->id)
            ->assertJsonPath('data.games.0.id', $game->id)
            ->assertJsonPath('data.stadiums.0.id', $stadium->id);
    }

    public function test_guest_show_via_public_route_returns_error_when_not_found(): void
    {
        $response = $this->getJson('/api/public/leagues/999');

        $response->assertStatus(422)->assertJson(['message' => 'Liga não encontrada!']);
    }

    // ── Formato de disputa (format) ─────────────────────────────────────────────

    public function test_master_can_create_league_with_round_robin_format(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/leagues', ['championship_id' => Championship::factory()->create()->id, 'name' => 'Liga RR', 'format' => 'round_robin']);

        $response->assertCreated()->assertJsonPath('data.format', 'round_robin');
        $this->assertDatabaseHas('leagues', ['name' => 'Liga RR', 'format' => 'round_robin']);
    }

    public function test_master_can_create_league_with_knockout_format(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/leagues', ['championship_id' => Championship::factory()->create()->id, 'name' => 'Liga Mata-Mata', 'format' => 'knockout']);

        $response->assertCreated()->assertJsonPath('data.format', 'knockout');
        $this->assertDatabaseHas('leagues', ['name' => 'Liga Mata-Mata', 'format' => 'knockout']);
    }

    public function test_master_can_create_league_with_hybrid_format(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/leagues', ['championship_id' => Championship::factory()->create()->id, 'name' => 'Liga Híbrida', 'format' => 'hybrid']);

        $response->assertCreated()->assertJsonPath('data.format', 'hybrid');
        $this->assertDatabaseHas('leagues', ['name' => 'Liga Híbrida', 'format' => 'hybrid']);
    }

    public function test_league_defaults_to_round_robin_format_when_not_informed(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/leagues', ['championship_id' => Championship::factory()->create()->id, 'name' => 'Liga Padrão']);

        $response->assertCreated()->assertJsonPath('data.format', 'round_robin');
    }

    public function test_store_fails_with_invalid_format(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/leagues', ['name' => 'Liga Inválida', 'format' => 'invalid_format']);

        $response->assertStatus(422);
    }

    public function test_master_can_update_league_format_to_hybrid_with_existing_games_without_phase(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        Game::factory()->create(['league_id' => $league->id, 'round' => 1]);

        $response = $this->putJson("/api/leagues/{$league->id}", ['format' => 'hybrid']);

        $response->assertOk()->assertJsonPath('data.format', 'hybrid');
        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'format' => 'hybrid']);
    }

    // ── Estrutura de resposta (LeagueResource) ──────────────────────────────────

    public function test_league_show_response_does_not_expose_internal_fields(): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        $response = $this->getJson("/api/leagues/{$league->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayNotHasKey('championship_id', $data);
        $this->assertArrayNotHasKey('created_at', $data);
        $this->assertArrayNotHasKey('updated_at', $data);
    }

    // ── Regressão: {id} só aceita dígitos ─────────────────────────────────────

    public function test_admin_non_numeric_league_id_returns_404(): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin([$league->id]);

        $this->getJson("/api/leagues/{$league->id}abc")->assertNotFound();
        $this->getJson("/api/leagues/{$league->id}abc/dashboard")->assertNotFound();
    }

    public function test_public_non_numeric_league_id_returns_404(): void
    {
        $league = League::factory()->create();

        $this->getJson("/api/public/leagues/{$league->id}abc")->assertNotFound();
    }

    // ── Permissões por papel (FOOT-0043) ──────────────────────────────────────

    public static function rolesWithoutTournamentUpdateProvider(): array
    {
        return ['coach' => ['coach'], 'player' => ['player']];
    }

    #[DataProvider('rolesWithoutTournamentUpdateProvider')]
    public function test_role_without_tournament_update_cannot_update_own_league(string $role): void
    {
        $league = League::factory()->create(['organizer' => 'Antigo']);
        $this->authenticateAs($role, [$league->id]);

        $response = $this->putJson("/api/leagues/{$league->id}", ['organizer' => 'Novo']);

        $response->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'organizer' => 'Antigo']);
    }

    #[DataProvider('rolesWithoutTournamentUpdateProvider')]
    public function test_role_without_tournament_update_can_still_show_own_league(string $role): void
    {
        $league = League::factory()->create();
        $this->authenticateAs($role, [$league->id]);

        $this->getJson("/api/leagues/{$league->id}")->assertOk();
    }
}
