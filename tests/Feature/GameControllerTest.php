<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\League;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GameControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Role::create(['name' => 'master']);
        Role::create(['name' => 'admin']);
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

    private function gamePayload(array $overrides = []): array
    {
        $league = League::factory()->create();
        $home = Team::factory()->create();
        $away = Team::factory()->create();

        return array_merge([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'scheduled',
        ], $overrides);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_guest_cannot_list_games(): void
    {
        $response = $this->getJson('/api/games');

        $response->assertStatus(401);
    }

    // ── Master ────────────────────────────────────────────────────────────────

    public function test_master_index_returns_all_games(): void
    {
        $this->authenticate();
        Game::factory()->count(3)->create();

        $response = $this->getJson('/api/games');

        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_master_can_create_game(): void
    {
        $this->authenticate();
        $payload = $this->gamePayload(['round' => 1, 'status' => 'scheduled']);

        $response = $this->postJson('/api/games', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.league_id', $payload['league_id'])
            ->assertJsonPath('data.home_team_id', $payload['home_team_id'])
            ->assertJsonPath('data.status', 'scheduled');

        $this->assertDatabaseHas('games', [
            'league_id' => $payload['league_id'],
            'home_team_id' => $payload['home_team_id'],
            'away_team_id' => $payload['away_team_id'],
        ]);
    }

    public function test_master_can_show_game(): void
    {
        $this->authenticate();
        $game = Game::factory()->create();

        $response = $this->getJson("/api/games/{$game->id}");

        $response->assertOk()->assertJsonPath('data.id', $game->id);
    }

    public function test_master_can_update_game(): void
    {
        $this->authenticate();
        $game = Game::factory()->create(['status' => 'scheduled']);

        $response = $this->putJson("/api/games/{$game->id}", [
            'status' => 'finished',
            'home_score' => 2,
            'away_score' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.home_score', 2);

        $this->assertDatabaseHas('games', ['id' => $game->id, 'status' => 'finished']);
    }

    public function test_master_can_delete_game(): void
    {
        $this->authenticate();
        $game = Game::factory()->create();

        $response = $this->deleteJson("/api/games/{$game->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('games', ['id' => $game->id]);
    }

    // ── Validações ────────────────────────────────────────────────────────────

    public function test_store_fails_without_required_fields(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/games', []);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_home_and_away_team_are_the_same(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $team = Team::factory()->create();

        $response = $this->postJson('/api/games', [
            'league_id' => $league->id,
            'home_team_id' => $team->id,
            'away_team_id' => $team->id,
            'status' => 'scheduled',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_with_invalid_status(): void
    {
        $this->authenticate();
        $payload = $this->gamePayload(['status' => 'invalid_status']);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_league_does_not_exist(): void
    {
        $this->authenticate();
        $home = Team::factory()->create();
        $away = Team::factory()->create();

        $response = $this->postJson('/api/games', [
            'league_id' => 999,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'scheduled',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_accepts_optional_stadium(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $payload = $this->gamePayload(['league_id' => $league->id, 'stadium_id' => $stadium->id]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertCreated()->assertJsonPath('data.stadium_id', $stadium->id);
    }

    public function test_store_fails_when_stadium_belongs_to_a_different_league(): void
    {
        $this->authenticate();
        $stadium = Stadium::factory()->create();
        $payload = $this->gamePayload(['stadium_id' => $stadium->id]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422);
    }

    public function test_update_fails_when_stadium_belongs_to_a_different_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $game = Game::factory()->create(['league_id' => $league->id]);
        $stadiumFromOtherLeague = Stadium::factory()->create();

        $response = $this->putJson("/api/games/{$game->id}", ['stadium_id' => $stadiumFromOtherLeague->id]);

        $response->assertStatus(422);
    }

    public function test_update_accepts_stadium_from_the_same_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $game = Game::factory()->create(['league_id' => $league->id]);
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);

        $response = $this->putJson("/api/games/{$game->id}", ['stadium_id' => $stadium->id]);

        $response->assertOk()->assertJsonPath('data.stadium_id', $stadium->id);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/games/999');

        $response->assertStatus(422)->assertJson(['message' => 'Partida não encontrada!']);
    }

    // ── Admin: escopo por liga ───────────────────────────────────────────────

    public function test_admin_index_only_sees_games_in_assigned_leagues(): void
    {
        $assignedLeague = League::factory()->create();
        $unassignedLeague = League::factory()->create();
        $home = Team::factory()->create();
        $away = Team::factory()->create();

        $gameInScope = Game::factory()->create(['league_id' => $assignedLeague->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id]);
        $gameOutScope = Game::factory()->create(['league_id' => $unassignedLeague->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id]);

        $this->authenticateAsAdmin([$assignedLeague->id]);

        $response = $this->getJson('/api/games');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $gameInScope->id);
    }

    public function test_admin_with_no_leagues_sees_empty_game_list(): void
    {
        Game::factory()->count(2)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/games');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    // ── Filtro por league_id ─────────────────────────────────────────────────

    public function test_master_can_filter_index_by_league_id(): void
    {
        $this->authenticate();
        $leagueA = League::factory()->create();
        $leagueB = League::factory()->create();
        $gameA = Game::factory()->create(['league_id' => $leagueA->id]);
        Game::factory()->create(['league_id' => $leagueB->id]);

        $response = $this->getJson("/api/games?league_id={$leagueA->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $gameA->id);
    }

    public function test_admin_can_filter_index_by_assigned_league_id(): void
    {
        $leagueA = League::factory()->create();
        $leagueB = League::factory()->create();
        $gameA = Game::factory()->create(['league_id' => $leagueA->id]);
        Game::factory()->create(['league_id' => $leagueB->id]);

        $this->authenticateAsAdmin([$leagueA->id, $leagueB->id]);

        $response = $this->getJson("/api/games?league_id={$leagueA->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $gameA->id);
    }

    public function test_admin_cannot_filter_index_by_unassigned_league_id(): void
    {
        $assignedLeague = League::factory()->create();
        $otherLeague = League::factory()->create();
        $this->authenticateAsAdmin([$assignedLeague->id]);

        $response = $this->getJson("/api/games?league_id={$otherLeague->id}");

        $response->assertStatus(422)->assertJson(['message' => 'Você não tem acesso a essa liga.']);
    }

    // ── GameResource: relações aninhadas ─────────────────────────────────────

    public function test_show_returns_nested_home_team_away_team_stadium_and_events(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $home = Team::factory()->create();
        $away = Team::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'stadium_id' => $stadium->id,
        ]);
        $player = \App\Models\Player::factory()->create(['team_id' => $home->id]);
        \App\Models\GameEvent::factory()->create([
            'game_id' => $game->id,
            'league_id' => $league->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
        ]);

        $response = $this->getJson("/api/games/{$game->id}");

        $response->assertOk()
            ->assertJsonPath('data.home_team_id', $home->id)
            ->assertJsonPath('data.homeTeam.id', $home->id)
            ->assertJsonPath('data.awayTeam.id', $away->id)
            ->assertJsonPath('data.stadium.id', $stadium->id)
            ->assertJsonCount(1, 'data.events');
    }

    public function test_index_does_not_include_nested_relations_by_default(): void
    {
        $this->authenticate();
        Game::factory()->create();

        $response = $this->getJson('/api/games');

        $response->assertOk();
        $this->assertArrayNotHasKey('homeTeam', $response->json('data.0'));
    }

    public function test_admin_can_show_game_in_assigned_league(): void
    {
        $league = League::factory()->create();
        $home = Team::factory()->create();
        $away = Team::factory()->create();
        $game = Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id]);

        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/games/{$game->id}");

        $response->assertOk()->assertJsonPath('data.id', $game->id);
    }

    public function test_admin_cannot_show_game_outside_assignments(): void
    {
        $game = Game::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/games/{$game->id}");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_delete_game_outside_assignments(): void
    {
        $game = Game::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->deleteJson("/api/games/{$game->id}");

        $response->assertForbidden();
    }

    // ── Integridade referencial ───────────────────────────────────────────────

    public function test_destroying_league_cascades_to_game(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $game = Game::factory()->create(['league_id' => $league->id]);

        $this->deleteJson("/api/leagues/{$league->id}")->assertStatus(204);

        $this->assertDatabaseMissing('games', ['id' => $game->id]);
    }

    public function test_deleting_stadium_with_games_via_api_is_blocked_and_keeps_game_stadium_id(): void
    {
        Role::findByName('master')->givePermissionTo(Permission::create(['name' => 'stadium:delete']));
        $this->authenticate();
        $stadium = Stadium::factory()->create();
        $game = Game::factory()->create(['stadium_id' => $stadium->id]);

        $this->deleteJson("/api/stadiums/{$stadium->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Não é possível remover o estádio pois existem partidas vinculadas a ele.');

        $this->assertDatabaseHas('games', ['id' => $game->id, 'stadium_id' => $stadium->id]);
    }

    public function test_stadium_fk_on_games_is_null_on_delete(): void
    {
        // A API bloqueia a exclusão (StadiumService::destroy), mas a FK em si continua
        // nullOnDelete: uma exclusão direta no banco não apaga as partidas.
        $stadium = Stadium::factory()->create();
        $game = Game::factory()->create(['stadium_id' => $stadium->id]);

        $stadium->delete();

        $this->assertDatabaseHas('games', ['id' => $game->id, 'stadium_id' => null]);
    }

    // ── Rodada x Fase (formato da liga) ─────────────────────────────────────────

    public function test_store_accepts_round_for_round_robin_league(): void
    {
        $this->authenticate();
        $payload = $this->gamePayload(['round' => 3]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.round', 3)
            ->assertJsonPath('data.phase', null);
    }

    public function test_store_accepts_phase_tie_key_and_leg_for_knockout_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        $payload = $this->gamePayload([
            'league_id' => $league->id,
            'phase' => 'Semifinal',
            'phase_order' => 3,
            'tie_key' => 'semi-1',
            'leg' => 1,
        ]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.round', null)
            ->assertJsonPath('data.phase', 'Semifinal')
            ->assertJsonPath('data.tie_key', 'semi-1')
            ->assertJsonPath('data.leg', 1);

        $this->assertDatabaseHas('games', [
            'league_id' => $league->id,
            'phase' => 'Semifinal',
            'tie_key' => 'semi-1',
            'leg' => 1,
            'round' => null,
        ]);
    }

    public function test_store_fails_when_leg_is_sent_without_tie_key(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        $payload = $this->gamePayload([
            'league_id' => $league->id,
            'phase' => 'Semifinal',
            'phase_order' => 1,
            'leg' => 1,
        ]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tie_key'])
            ->assertJsonPath('errors.tie_key.0', 'Informe a chave do confronto para partidas de ida e volta.');
    }

    public function test_update_fails_when_leg_is_sent_for_game_without_tie_key(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'round' => null,
            'phase' => 'Final',
            'phase_order' => 1,
            'tie_key' => null,
            'leg' => null,
        ]);

        $response = $this->putJson("/api/games/{$game->id}", ['leg' => 1]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tie_key'])
            ->assertJsonPath('errors.tie_key.0', 'Informe a chave do confronto para partidas de ida e volta.');
        $this->assertDatabaseHas('games', ['id' => $game->id, 'leg' => null]);
    }

    public function test_update_accepts_only_leg_when_game_already_has_tie_key(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'round' => null,
            'phase' => 'Final',
            'phase_order' => 1,
            'tie_key' => 'final-1',
            'leg' => 1,
        ]);

        $response = $this->putJson("/api/games/{$game->id}", ['leg' => 2]);

        $response->assertOk()
            ->assertJsonPath('data.leg', 2)
            ->assertJsonPath('data.tie_key', 'final-1');
    }

    public function test_store_fails_when_round_and_phase_are_sent_together(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'hybrid']);
        $payload = $this->gamePayload(['league_id' => $league->id, 'round' => 1, 'phase' => 'Final']);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_knockout_league_game_informs_round_instead_of_phase(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        $payload = $this->gamePayload(['league_id' => $league->id, 'round' => 1]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_knockout_league_game_informs_neither_round_nor_phase(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        $payload = $this->gamePayload(['league_id' => $league->id]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_round_robin_league_game_informs_phase(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        $payload = $this->gamePayload(['league_id' => $league->id, 'phase' => 'Final']);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422);
    }

    public function test_update_league_from_round_robin_to_hybrid_does_not_break_existing_games_with_round(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        $game = Game::factory()->create(['league_id' => $league->id, 'round' => 5]);

        $this->putJson("/api/leagues/{$league->id}", ['format' => 'hybrid'])->assertOk();

        $response = $this->putJson("/api/games/{$game->id}", ['status' => 'finished']);

        $response->assertOk()->assertJsonPath('data.round', 5);
    }

    // ── Rotas públicas ────────────────────────────────────────────────────────

    public function test_guest_can_show_game_via_public_route_with_related_data(): void
    {
        $league = League::factory()->create();
        $home = Team::factory()->create();
        $away = Team::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'stadium_id' => $stadium->id,
        ]);
        $player = \App\Models\Player::factory()->create(['team_id' => $home->id]);
        \App\Models\GameEvent::factory()->create([
            'game_id' => $game->id,
            'league_id' => $league->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
        ]);

        $response = $this->getJson("/api/public/games/{$game->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $game->id)
            ->assertJsonPath('data.homeTeam.id', $home->id)
            ->assertJsonPath('data.awayTeam.id', $away->id)
            ->assertJsonPath('data.stadium.id', $stadium->id)
            ->assertJsonCount(1, 'data.events');
    }

    public function test_guest_show_via_public_route_returns_error_when_not_found(): void
    {
        $response = $this->getJson('/api/public/games/999');

        $response->assertStatus(422)->assertJson(['message' => 'Partida não encontrada!']);
    }

    // ── Index: paginação opcional e filtros ───────────────────────────────────

    public function test_index_without_pagination_params_returns_all_games_without_meta(): void
    {
        $this->authenticate();
        Game::factory()->count(25)->create();

        $response = $this->getJson('/api/games');

        $response->assertOk()->assertJsonCount(25, 'data')->assertJsonMissingPath('meta');
    }

    public function test_index_paginates_45_games_in_pages_of_20(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        Game::factory()->count(45)->create(['league_id' => $league->id]);

        $ids = [];
        foreach ([1 => 20, 2 => 20, 3 => 5] as $page => $expected) {
            $response = $this->getJson("/api/games?page={$page}");

            $response->assertOk()
                ->assertJsonCount($expected, 'data')
                ->assertJsonPath('meta.current_page', $page)
                ->assertJsonPath('meta.per_page', 20)
                ->assertJsonPath('meta.total', 45)
                ->assertJsonPath('meta.last_page', 3);

            $ids = array_merge($ids, array_column($response->json('data'), 'id'));
        }

        $this->assertCount(45, $ids);
        $this->assertCount(45, array_unique($ids));
    }

    public function test_index_per_page_alone_enables_pagination(): void
    {
        $this->authenticate();
        Game::factory()->count(12)->create();

        $this->getJson('/api/games?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_index_page_beyond_last_returns_empty_data_with_consistent_meta(): void
    {
        $this->authenticate();
        Game::factory()->count(45)->create();

        $this->getJson('/api/games?page=99')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 99)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 45)
            ->assertJsonPath('meta.last_page', 3);
    }

    // Cria um cenário fixo para os testes de filtro, numa única liga.
    private function seedFilterScenario(): array
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();

        $homeR1 = Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $team->id, 'round' => 1, 'phase' => 'Grupos', 'status' => 'finished']);
        $awayR1 = Game::factory()->create(['league_id' => $league->id, 'away_team_id' => $team->id, 'round' => 1, 'phase' => 'Grupos', 'status' => 'scheduled']);
        $otherR1 = Game::factory()->create(['league_id' => $league->id, 'round' => 1, 'phase' => 'Grupos', 'status' => 'finished']);
        $awayR2 = Game::factory()->create(['league_id' => $league->id, 'away_team_id' => $team->id, 'round' => 2, 'phase' => 'Final', 'status' => 'finished']);
        $otherR2 = Game::factory()->create(['league_id' => $league->id, 'round' => 2, 'phase' => 'Final', 'status' => 'cancelled']);

        return compact('league', 'team', 'homeR1', 'awayR1', 'otherR1', 'awayR2', 'otherR2');
    }

    private function assertGameIds(array $expected, $response): void
    {
        $ids = array_column($response->json('data'), 'id');
        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public static function paginationModeProvider(): array
    {
        return [
            'sem paginação' => [false],
            'paginado' => [true],
        ];
    }

    #[DataProvider('paginationModeProvider')]
    public function test_index_filters_isolated_and_combined(bool $paginated): void
    {
        $this->authenticate();
        $s = $this->seedFilterScenario();
        $suffix = $paginated ? '&page=1&per_page=10' : '';

        $cases = [
            "round=1" => [$s['homeR1'], $s['awayR1'], $s['otherR1']],
            "phase=Final" => [$s['awayR2'], $s['otherR2']],
            "status=finished" => [$s['homeR1'], $s['otherR1'], $s['awayR2']],
            "team_id={$s['team']->id}" => [$s['homeR1'], $s['awayR1'], $s['awayR2']],
            "team_id={$s['team']->id}&round=1" => [$s['homeR1'], $s['awayR1']],
            "team_id={$s['team']->id}&status=finished" => [$s['homeR1'], $s['awayR2']],
            "round=2&phase=Final&status=cancelled" => [$s['otherR2']],
            "league_id={$s['league']->id}&team_id={$s['team']->id}&phase=Grupos&status=scheduled" => [$s['awayR1']],
        ];

        foreach ($cases as $query => $games) {
            $response = $this->getJson("/api/games?{$query}{$suffix}");

            $response->assertOk();
            $this->assertGameIds(array_map(fn ($g) => $g->id, $games), $response);

            if ($paginated) {
                $response->assertJsonPath('meta.total', count($games));
            } else {
                $response->assertJsonMissingPath('meta');
            }
        }
    }

    public function test_index_team_id_filter_does_not_leak_other_leagues(): void
    {
        $league = League::factory()->create();
        $otherLeague = League::factory()->create();
        $team = Team::factory()->create();
        $mine = Game::factory()->create(['league_id' => $league->id, 'away_team_id' => $team->id]);
        Game::factory()->create(['league_id' => $otherLeague->id, 'home_team_id' => $team->id]);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/games?team_id={$team->id}&per_page=10");

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertGameIds([$mine->id], $response);
    }

    public static function invalidIndexQueryProvider(): array
    {
        return [
            'per_page zero' => ['per_page=0', 'per_page'],
            'per_page acima do máximo' => ['per_page=500', 'per_page'],
            'page texto' => ['page=abc', 'page'],
            'page zero' => ['page=0', 'page'],
            'team_id texto' => ['team_id=abc', 'team_id'],
            'team_id sufixo não numérico' => ['team_id=1abc', 'team_id'],
            'status inválido' => ['status=xpto', 'status'],
            'round zero' => ['round=0', 'round'],
        ];
    }

    #[DataProvider('invalidIndexQueryProvider')]
    public function test_index_invalid_filter_or_pagination_returns_422(string $query, string $field): void
    {
        $this->authenticate();

        $this->getJson("/api/games?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public function test_admin_cannot_filter_paginated_index_by_unassigned_league_id(): void
    {
        $assignedLeague = League::factory()->create();
        $otherLeague = League::factory()->create();
        $this->authenticateAsAdmin([$assignedLeague->id]);

        $this->getJson("/api/games?league_id={$otherLeague->id}&page=1")
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a essa liga.']);
    }

    public function test_admin_paginated_index_without_league_id_only_sees_assigned_leagues(): void
    {
        $leagueA = League::factory()->create();
        $leagueB = League::factory()->create();
        $otherLeague = League::factory()->create();
        Game::factory()->count(3)->create(['league_id' => $leagueA->id]);
        Game::factory()->count(2)->create(['league_id' => $leagueB->id]);
        Game::factory()->count(4)->create(['league_id' => $otherLeague->id]);
        $this->authenticateAsAdmin([$leagueA->id, $leagueB->id]);

        $response = $this->getJson('/api/games?per_page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);

        $all = $this->getJson('/api/games?per_page=100')->json('data');
        $this->assertEqualsCanonicalizing(
            [$leagueA->id, $leagueB->id],
            array_values(array_unique(array_column($all, 'league_id')))
        );
    }

    public function test_admin_paginated_index_with_multiple_leagues_groups_games_by_league(): void
    {
        $leagueA = League::factory()->create();
        $leagueB = League::factory()->create();
        // Rodadas intercaladas entre as ligas: sem league_id na ordenação viriam misturadas.
        $gamesB = Game::factory()->count(13)->sequence(fn ($s) => ['round' => $s->index % 4 + 1])->create(['league_id' => $leagueB->id]);
        $gamesA = Game::factory()->count(12)->sequence(fn ($s) => ['round' => $s->index % 4 + 1])->create(['league_id' => $leagueA->id]);
        Game::factory()->count(3)->create();
        $this->authenticateAsAdmin([$leagueA->id, $leagueB->id]);

        $items = [];
        foreach ([1 => 10, 2 => 10, 3 => 5] as $page => $expected) {
            $response = $this->getJson("/api/games?page={$page}&per_page=10");

            $response->assertOk()
                ->assertJsonCount($expected, 'data')
                ->assertJsonPath('meta.total', 25)
                ->assertJsonPath('meta.last_page', 3);

            $items = array_merge($items, $response->json('data'));
        }

        $ids = array_column($items, 'id');
        $this->assertCount(25, array_unique($ids));
        $this->assertEqualsCanonicalizing($gamesA->merge($gamesB)->pluck('id')->all(), $ids);

        // Todas as partidas da liga A (id menor) antes das da liga B, cada bloco por rodada.
        $leagueIds = array_column($items, 'league_id');
        $this->assertSame(
            array_merge(array_fill(0, 12, $leagueA->id), array_fill(0, 13, $leagueB->id)),
            $leagueIds
        );
        foreach ([array_slice($items, 0, 12), array_slice($items, 12)] as $block) {
            $rounds = array_column($block, 'round');
            $sorted = $rounds;
            sort($sorted);
            $this->assertSame($sorted, $rounds);
        }
    }

    public function test_paginated_index_runs_constant_number_of_queries(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        Game::factory()->count(30)->create(['league_id' => $league->id, 'round' => 1]);

        DB::enableQueryLog();
        $this->getJson("/api/games?league_id={$league->id}&round=1&per_page=20")->assertOk();
        $gameQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], '"games"'));
        DB::disableQueryLog();

        // Deferred join: count + ids da página + linhas desses ids, sem N+1 por partida.
        $this->assertCount(3, $gameQueries);
    }

    public function test_paginated_index_beyond_last_page_only_runs_count_query(): void
    {
        $this->authenticate();
        Game::factory()->count(5)->create();

        DB::enableQueryLog();
        $this->getJson('/api/games?page=3&per_page=5')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 5);
        $gameQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], '"games"'));
        DB::disableQueryLog();

        $this->assertCount(1, $gameQueries);
    }

    // ── Regressão: filtro ?league_id= inválido ────────────────────────────────

    public static function invalidLeagueIdQueryProvider(): array
    {
        return [
            'array' => ['league_id[]=1'],
            'sufixo não numérico' => ['league_id=1abc'],
            'texto' => ['league_id=abc'],
        ];
    }

    #[DataProvider('invalidLeagueIdQueryProvider')]
    public function test_invalid_league_id_filter_returns_422(string $query): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin([$league->id]);

        $this->getJson("/api/games?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('league_id');
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $this->authenticate();

        $this->getJson('/api/games/1abc')->assertNotFound();
    }
}
