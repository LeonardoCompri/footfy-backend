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
use Illuminate\Support\Facades\DB;
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

    public function test_guest_cannot_list_leagues(): void
    {
        $response = $this->getJson('/api/leagues');

        $response->assertStatus(401);
    }

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
        $this->authenticateAsAdmin();

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

    private function leagueWithRoster(int $rosterCount, array $leagueAttributes = [], string $teamName = 'Alfa FC'): League
    {
        $league = League::factory()->create(['end_date' => null, ...$leagueAttributes]);
        $team = Team::factory()->create(['name' => $teamName]);
        $team->leagues()->attach($league->id);
        Player::factory()->count($rosterCount)->create(['team_id' => $team->id]);

        return $league;
    }

    public function test_new_league_defaults_to_35_players_per_team(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();

        $response = $this->postJson('/api/leagues', ['name' => 'Série A', 'championship_id' => $championship->id]);

        $response->assertCreated()->assertJsonPath('data.max_players_per_team', 35);
        $this->assertDatabaseHas('leagues', ['id' => $response->json('data.id'), 'max_players_per_team' => 35]);
    }

    public function test_existing_leagues_receive_35_players_per_team_from_migration(): void
    {
        $migration = require database_path('migrations/2026_11_10_000000_add_max_players_per_team_to_leagues_table.php');
        $migration->down();
        $leagueId = DB::table('leagues')->insertGetId(['name' => 'Liga antiga']);

        $migration->up();

        $this->assertDatabaseHas('leagues', ['id' => $leagueId, 'max_players_per_team' => 35]);
    }

    public function test_store_accepts_max_players_per_team(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();

        $this->postJson('/api/leagues', [
            'name' => 'Série B',
            'championship_id' => $championship->id,
            'max_players_per_team' => 25,
        ])
            ->assertCreated()
            ->assertJsonPath('data.max_players_per_team', 25);
    }

    public function test_show_returns_max_players_per_team(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['max_players_per_team' => 28]);

        $this->getJson("/api/leagues/{$league->id}")
            ->assertOk()
            ->assertJsonPath('data.max_players_per_team', 28);
    }

    #[DataProvider('playerLimitValues')]
    public function test_update_validates_max_players_per_team_range(mixed $value, int $status): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        $response = $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => $value]);

        $response->assertStatus($status);

        if ($status === 422) {
            $response->assertJsonValidationErrors('max_players_per_team');
            $this->assertSame(35, $league->fresh()->max_players_per_team);
        } else {
            $response->assertJsonPath('data.max_players_per_team', $value);
        }
    }

    public static function playerLimitValues(): array
    {
        return [
            'abaixo do mínimo' => [10, 422],
            'mínimo' => [11, 200],
            'máximo' => [35, 200],
            'acima do máximo' => [36, 422],
            'nulo' => [null, 422],
            'texto' => ['abc', 422],
            'lista' => [[20], 422],
        ];
    }

    public function test_range_error_uses_readable_attribute_name(): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => 10])
            ->assertStatus(422)
            ->assertJsonPath('errors.max_players_per_team.0', 'O campo limite de jogadores por time deve ser no mínimo 11.');
    }

    public function test_limit_can_go_back_up_from_25_to_30(): void
    {
        $this->authenticate();
        $league = $this->leagueWithRoster(25, ['max_players_per_team' => 25]);

        $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => 30])
            ->assertOk()
            ->assertJsonPath('data.max_players_per_team', 30);
    }

    public function test_reducing_limit_below_roster_is_blocked_citing_the_team(): void
    {
        $this->authenticate();
        $league = $this->leagueWithRoster(32);

        $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => 30])
            ->assertStatus(422)
            ->assertJsonPath('errors.max_players_per_team.0', 'Não é possível reduzir o limite para 30: o time Alfa FC tem 32 atletas.');

        $this->assertSame(35, $league->fresh()->max_players_per_team);
    }

    public function test_reduction_error_cites_the_largest_team_of_the_league(): void
    {
        $this->authenticate();
        $league = $this->leagueWithRoster(31);
        $largest = Team::factory()->create(['name' => 'Beta EC']);
        $largest->leagues()->attach($league->id);
        Player::factory()->count(33)->create(['team_id' => $largest->id]);

        $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => 30])
            ->assertStatus(422)
            ->assertJsonPath('errors.max_players_per_team.0', 'Não é possível reduzir o limite para 30: o time Beta EC tem 33 atletas.');
    }

    public function test_reducing_limit_to_exact_roster_size_is_allowed(): void
    {
        $this->authenticate();
        $league = $this->leagueWithRoster(30);

        $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => 30])
            ->assertOk()
            ->assertJsonPath('data.max_players_per_team', 30);
    }

    public function test_reduction_does_not_count_removed_players(): void
    {
        $this->authenticate();
        $league = $this->leagueWithRoster(32);
        $league->teams()->first()->players()->limit(2)->get()->each->delete();

        $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => 30])->assertOk();
    }

    public function test_reduction_ignores_players_of_teams_outside_the_league(): void
    {
        $this->authenticate();
        $league = $this->leagueWithRoster(20);
        Player::factory()->count(32)->create(['team_id' => Team::factory()->create()->id]);

        $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => 30])->assertOk();
    }

    public function test_resending_or_raising_limit_with_team_already_above_it_is_allowed(): void
    {
        $this->authenticate();
        $league = $this->leagueWithRoster(32, ['max_players_per_team' => 30]);

        $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => 30])->assertOk();
        $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => 31])->assertOk();
    }

    public function test_limit_can_change_on_league_with_drawn_rounds(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin', 'fixture_mode' => 'draw', 'end_date' => null]);
        Game::factory()->create(['league_id' => $league->id, 'round' => 1, 'group_number' => 1]);

        $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => 30])
            ->assertOk()
            ->assertJsonPath('data.max_players_per_team', 30);
    }

    public function test_admin_can_update_limit_of_own_league(): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin([$league->id]);

        $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => 30])
            ->assertOk()
            ->assertJsonPath('data.max_players_per_team', 30);
    }

    public function test_coach_cannot_update_limit(): void
    {
        $league = League::factory()->create();
        $this->authenticateAs('coach', [$league->id]);

        $this->putJson("/api/leagues/{$league->id}", ['max_players_per_team' => 30])->assertForbidden();

        $this->assertSame(35, $league->fresh()->max_players_per_team);
    }

    public function test_championship_form_saves_limit_of_new_and_existing_leagues(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id]);

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [
                ['id' => $league->id, 'name' => $league->name, 'max_players_per_team' => 30],
                ['name' => 'Série B', 'max_players_per_team' => 22],
                ['name' => 'Série C'],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(30, $league->fresh()->max_players_per_team);
        $this->assertDatabaseHas('leagues', ['name' => 'Série B', 'max_players_per_team' => 22]);
        $this->assertDatabaseHas('leagues', ['name' => 'Série C', 'max_players_per_team' => 35]);
    }

    public function test_championship_form_validates_limit_range(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/championships', [
            'name' => 'Copa',
            'leagues' => [['name' => 'Série A', 'max_players_per_team' => 36]],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('leagues.0.max_players_per_team', $response->json('errors'));
    }

    public function test_championship_form_blocks_reduction_below_roster(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        $league = $this->leagueWithRoster(32, ['championship_id' => $championship->id]);

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [['id' => $league->id, 'name' => $league->name, 'max_players_per_team' => 30]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            'Não é possível reduzir o limite para 30: o time Alfa FC tem 32 atletas.',
            $response->json('errors')['leagues.0.max_players_per_team'][0],
        );
        $this->assertSame(35, $league->fresh()->max_players_per_team);
    }
}
