<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class GameEventControllerTest extends TestCase
{
    use RefreshDatabase, LinksUsersToLeagues;

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

    /**
     * Monta um cenário consistente: liga, jogo com home/away times definidos
     * e um jogador vinculado a um dos times do jogo (respeitando a regra de negócio do evento).
     */
    private function buildGameScenario(?int $leagueId = null): array
    {
        $league = $leagueId
            ? League::find($leagueId)
            : League::factory()->create();
        $homeTeam = Team::factory()->create();
        $awayTeam = Team::factory()->create();
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
        ]);
        $player = Player::factory()->create(['team_id' => $homeTeam->id]);
        $teammate = Player::factory()->create(['team_id' => $homeTeam->id]);

        return [
            'league' => $league,
            'homeTeam' => $homeTeam,
            'awayTeam' => $awayTeam,
            'game' => $game,
            'player' => $player,
            'teammate' => $teammate,
        ];
    }

    private function eventPayload(array $overrides = []): array
    {
        $scenario = $this->buildGameScenario();

        return array_merge([
            'game_id' => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'team_id' => $scenario['homeTeam']->id,
            'type' => 'goal',
            'minute' => 10,
        ], $overrides);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_guest_cannot_list_game_events(): void
    {
        $response = $this->getJson('/api/game-events');

        $response->assertStatus(401);
    }

    // ── Master ────────────────────────────────────────────────────────────────

    public function test_master_index_returns_all_game_events(): void
    {
        $this->authenticate();
        GameEvent::factory()->count(3)->create();

        $response = $this->getJson('/api/game-events');

        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_master_can_filter_index_by_type(): void
    {
        $this->authenticate();
        GameEvent::factory()->count(2)->create(['type' => 'goal']);
        GameEvent::factory()->yellowCard()->create();

        $response = $this->getJson('/api/game-events?type=yellow_card');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_master_can_filter_index_by_game_id(): void
    {
        $this->authenticate();
        $scenarioA = $this->buildGameScenario();
        $scenarioB = $this->buildGameScenario();
        $eventA = GameEvent::factory()->create([
            'game_id' => $scenarioA['game']->id,
            'league_id' => $scenarioA['league']->id,
            'team_id' => $scenarioA['homeTeam']->id,
            'player_id' => $scenarioA['player']->id,
        ]);
        GameEvent::factory()->create([
            'game_id' => $scenarioB['game']->id,
            'league_id' => $scenarioB['league']->id,
            'team_id' => $scenarioB['homeTeam']->id,
            'player_id' => $scenarioB['player']->id,
        ]);

        $response = $this->getJson("/api/game-events?game_id={$scenarioA['game']->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eventA->id);
    }

    public function test_admin_can_filter_index_by_game_id_of_assigned_league(): void
    {
        $league = League::factory()->create();
        $scenario = $this->buildGameScenario($league->id);
        $event = GameEvent::factory()->create([
            'game_id' => $scenario['game']->id,
            'league_id' => $league->id,
            'team_id' => $scenario['homeTeam']->id,
            'player_id' => $scenario['player']->id,
        ]);

        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/game-events?game_id={$scenario['game']->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $event->id);
    }

    public function test_admin_cannot_filter_index_by_game_id_outside_assignments(): void
    {
        $scenario = $this->buildGameScenario();
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/game-events?game_id={$scenario['game']->id}");

        $response->assertStatus(422)->assertJson(['message' => 'Você não tem acesso a essa partida.']);
    }

    public function test_master_can_create_goal_event(): void
    {
        $this->authenticate();
        $payload = $this->eventPayload(['type' => 'goal', 'minute' => 23]);

        $response = $this->postJson('/api/game-events', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.game_id', $payload['game_id'])
            ->assertJsonPath('data.player_id', $payload['player_id'])
            ->assertJsonPath('data.team_id', $payload['team_id'])
            ->assertJsonPath('data.type', 'goal')
            ->assertJsonPath('data.minute', 23);

        $this->assertDatabaseHas('game_events', [
            'game_id' => $payload['game_id'],
            'player_id' => $payload['player_id'],
            'team_id' => $payload['team_id'],
            'type' => 'goal',
        ]);
    }

    public function test_master_can_create_yellow_card_event(): void
    {
        $this->authenticate();
        $payload = $this->eventPayload(['type' => 'yellow_card']);

        $response = $this->postJson('/api/game-events', $payload);

        $response->assertCreated()->assertJsonPath('data.type', 'yellow_card');
    }

    public function test_master_can_create_red_card_event(): void
    {
        $this->authenticate();
        $payload = $this->eventPayload(['type' => 'red_card']);

        $response = $this->postJson('/api/game-events', $payload);

        $response->assertCreated()->assertJsonPath('data.type', 'red_card');
    }

    public function test_master_can_create_goal_event_with_assist(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $payload = [
            'game_id' => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'related_player_id' => $scenario['teammate']->id,
            'team_id' => $scenario['homeTeam']->id,
            'type' => 'goal',
            'minute' => 30,
        ];

        $response = $this->postJson('/api/game-events', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.related_player_id', $scenario['teammate']->id);
    }

    public function test_league_id_is_automatically_filled_from_game(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $payload = $this->eventPayload([
            'game_id' => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'team_id' => $scenario['homeTeam']->id,
        ]);

        $response = $this->postJson('/api/game-events', $payload);

        $response->assertCreated()->assertJsonPath('data.league_id', $scenario['game']->league_id);

        $this->assertDatabaseHas('game_events', [
            'game_id' => $scenario['game']->id,
            'league_id' => $scenario['game']->league_id,
        ]);
    }

    public function test_league_id_payload_field_is_ignored(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $otherLeague = League::factory()->create();
        $payload = $this->eventPayload([
            'game_id' => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'team_id' => $scenario['homeTeam']->id,
            'league_id' => $otherLeague->id,
        ]);

        $response = $this->postJson('/api/game-events', $payload);

        $response->assertCreated()->assertJsonPath('data.league_id', $scenario['game']->league_id);
    }

    public function test_master_can_show_game_event(): void
    {
        $this->authenticate();
        $event = GameEvent::factory()->create();

        $response = $this->getJson("/api/game-events/{$event->id}");

        $response->assertOk()->assertJsonPath('data.id', $event->id);
    }

    public function test_master_can_update_game_event(): void
    {
        $this->authenticate();
        $event = GameEvent::factory()->create(['minute' => 10]);

        $response = $this->putJson("/api/game-events/{$event->id}", [
            'minute' => 45,
        ]);

        $response->assertOk()->assertJsonPath('data.minute', 45);

        $this->assertDatabaseHas('game_events', ['id' => $event->id, 'minute' => 45]);
    }

    public function test_master_can_delete_game_event(): void
    {
        $this->authenticate();
        $event = GameEvent::factory()->create();

        $response = $this->deleteJson("/api/game-events/{$event->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('game_events', ['id' => $event->id]);
    }

    // ── Validações ────────────────────────────────────────────────────────────

    public function test_store_fails_without_required_fields(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/game-events', []);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_game_does_not_exist(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();

        $response = $this->postJson('/api/game-events', [
            'game_id' => 999,
            'player_id' => $scenario['player']->id,
            'team_id' => $scenario['homeTeam']->id,
            'type' => 'goal',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_team_does_not_participate_in_game(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $outsiderTeam = Team::factory()->create();

        $response = $this->postJson('/api/game-events', [
            'game_id' => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'team_id' => $outsiderTeam->id,
            'type' => 'goal',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_player_does_not_belong_to_team(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $otherPlayer = Player::factory()->create(['team_id' => $scenario['awayTeam']->id]);

        $response = $this->postJson('/api/game-events', [
            'game_id' => $scenario['game']->id,
            'player_id' => $otherPlayer->id,
            'team_id' => $scenario['homeTeam']->id,
            'type' => 'goal',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_with_negative_minute(): void
    {
        $this->authenticate();
        $payload = $this->eventPayload(['minute' => -1]);

        $response = $this->postJson('/api/game-events', $payload);

        $response->assertStatus(422);
    }

    public function test_store_fails_with_invalid_type(): void
    {
        $this->authenticate();
        $payload = $this->eventPayload(['type' => 'penalty_miss']);

        $response = $this->postJson('/api/game-events', $payload);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_related_player_equals_player(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();

        $response = $this->postJson('/api/game-events', [
            'game_id' => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'related_player_id' => $scenario['player']->id,
            'team_id' => $scenario['homeTeam']->id,
            'type' => 'goal',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_related_player_belongs_to_other_team(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $outsiderPlayer = Player::factory()->create(['team_id' => $scenario['awayTeam']->id]);

        $response = $this->postJson('/api/game-events', [
            'game_id' => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'related_player_id' => $outsiderPlayer->id,
            'team_id' => $scenario['homeTeam']->id,
            'type' => 'goal',
        ]);

        $response->assertStatus(422);
    }

    public function test_update_fails_when_team_does_not_participate_in_game(): void
    {
        $this->authenticate();
        $event = GameEvent::factory()->create();
        $outsiderTeam = Team::factory()->create();

        $response = $this->putJson("/api/game-events/{$event->id}", [
            'team_id' => $outsiderTeam->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/game-events/999');

        $response->assertStatus(422)->assertJson(['message' => 'Evento não encontrado!']);
    }

    // ── Admin: escopo por liga ───────────────────────────────────────────────

    public function test_admin_index_only_sees_game_events_in_assigned_leagues(): void
    {
        $assignedLeague = League::factory()->create();
        $unassignedLeague = League::factory()->create();

        $scenarioInScope = $this->buildGameScenario($assignedLeague->id);
        $scenarioOutScope = $this->buildGameScenario($unassignedLeague->id);

        $eventInScope = GameEvent::factory()->create([
            'game_id' => $scenarioInScope['game']->id,
            'player_id' => $scenarioInScope['player']->id,
            'team_id' => $scenarioInScope['homeTeam']->id,
            'league_id' => $assignedLeague->id,
        ]);
        GameEvent::factory()->create([
            'game_id' => $scenarioOutScope['game']->id,
            'player_id' => $scenarioOutScope['player']->id,
            'team_id' => $scenarioOutScope['homeTeam']->id,
            'league_id' => $unassignedLeague->id,
        ]);

        $this->authenticateAsAdmin([$assignedLeague->id]);

        $response = $this->getJson('/api/game-events');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eventInScope->id);
    }

    public function test_admin_with_no_leagues_sees_empty_game_event_list(): void
    {
        GameEvent::factory()->count(2)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/game-events');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_show_game_event_in_assigned_league(): void
    {
        $league = League::factory()->create();
        $scenario = $this->buildGameScenario($league->id);
        $event = GameEvent::factory()->create([
            'game_id' => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'team_id' => $scenario['homeTeam']->id,
            'league_id' => $league->id,
        ]);

        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/game-events/{$event->id}");

        $response->assertOk()->assertJsonPath('data.id', $event->id);
    }

    public function test_admin_cannot_show_game_event_outside_assignments(): void
    {
        $event = GameEvent::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/game-events/{$event->id}");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_delete_game_event_outside_assignments(): void
    {
        $event = GameEvent::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->deleteJson("/api/game-events/{$event->id}");

        $response->assertForbidden();
    }

    // ── Integridade referencial ───────────────────────────────────────────────

    public function test_destroying_game_cascades_to_game_event(): void
    {
        $this->authenticate();
        $event = GameEvent::factory()->create();
        $gameId = $event->game_id;

        $this->deleteJson("/api/games/{$gameId}")->assertStatus(204);

        $this->assertDatabaseMissing('game_events', ['id' => $event->id]);
    }

    // ── Regressão: filtros ?game_id=/?type= inválidos ─────────────────────────

    public static function invalidIndexQueryProvider(): array
    {
        return [
            'game_id array' => ['game_id[]=1', 'game_id'],
            'game_id sufixo não numérico' => ['game_id=1abc', 'game_id'],
            'game_id texto' => ['game_id=abc', 'game_id'],
            'type array' => ['type[]=goal', 'type'],
        ];
    }

    #[DataProvider('invalidIndexQueryProvider')]
    public function test_invalid_index_filter_returns_422(string $query, string $field): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin([$league->id]);

        $this->getJson("/api/game-events?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $this->authenticate();

        $this->getJson('/api/game-events/1abc')->assertNotFound();
    }

    // ── Permissões por papel (FOOT-0043) ──────────────────────────────────────

    public static function rolesWithoutGameEventPermissionsProvider(): array
    {
        return ['coach' => ['coach'], 'player' => ['player']];
    }

    #[DataProvider('rolesWithoutGameEventPermissionsProvider')]
    public function test_role_without_game_event_permissions_cannot_write_events(string $role): void
    {
        $scenario = $this->buildGameScenario();
        $event = GameEvent::factory()->create([
            'game_id' => $scenario['game']->id,
            'team_id' => $scenario['homeTeam']->id,
            'player_id' => $scenario['player']->id,
            'league_id' => $scenario['league']->id,
            'minute' => 10,
        ]);
        $this->authenticateAs($role, [$scenario['league']->id]);

        $this->postJson('/api/game-events', [
            'game_id' => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'team_id' => $scenario['homeTeam']->id,
            'type' => 'goal',
            'minute' => 50,
        ])->assertForbidden()->assertJsonPath('message', 'User does not have the right permissions.');
        $this->putJson("/api/game-events/{$event->id}", ['minute' => 45])
            ->assertForbidden()
            ->assertJsonPath('message', 'User does not have the right permissions.');
        $this->deleteJson("/api/game-events/{$event->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'User does not have the right permissions.');

        $this->assertDatabaseCount('game_events', 1);
        $this->assertDatabaseHas('game_events', ['id' => $event->id, 'minute' => 10]);
    }

    public function test_admin_can_create_update_and_delete_game_event_in_assigned_league(): void
    {
        $payload = $this->eventPayload(['minute' => 23]);
        $leagueId = Game::find($payload['game_id'])->league_id;
        $this->authenticateAsAdmin([$leagueId]);

        $id = $this->postJson('/api/game-events', $payload)->assertCreated()->json('data.id');
        $this->putJson("/api/game-events/{$id}", ['minute' => 45])
            ->assertOk()
            ->assertJsonPath('data.minute', 45);
        $this->deleteJson("/api/game-events/{$id}")->assertStatus(204);

        $this->assertDatabaseMissing('game_events', ['id' => $id]);
    }

    public function test_admin_outside_league_is_blocked_by_resource_access_not_by_permission(): void
    {
        $event = GameEvent::factory()->create();
        $this->authenticateAsAdmin([League::factory()->create()->id]);

        $this->putJson("/api/game-events/{$event->id}", ['minute' => 45])
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    // ── Liga da partida na escrita (FOOT-0044) ────────────────────────────────

    public function test_admin_cannot_create_event_in_game_of_unassigned_league(): void
    {
        $payload = $this->eventPayload();
        $this->authenticateAsAdmin([League::factory()->create()->id]);

        $this->postJson('/api/game-events', $payload)
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);

        $this->assertDatabaseCount('game_events', 0);
    }

    /** Evento na liga A e outra partida, na liga B, com o mesmo time (aceita pelo request). */
    private function eventAndGameOfAnotherLeague(): array
    {
        $scenario = $this->buildGameScenario();
        $event = GameEvent::factory()->create([
            'game_id' => $scenario['game']->id,
            'league_id' => $scenario['league']->id,
            'team_id' => $scenario['homeTeam']->id,
            'player_id' => $scenario['player']->id,
        ]);
        $otherGame = Game::factory()->create(['home_team_id' => $scenario['homeTeam']->id]);

        return [$scenario, $event, $otherGame];
    }

    public function test_admin_cannot_move_event_to_game_of_unassigned_league(): void
    {
        [$scenario, $event, $otherGame] = $this->eventAndGameOfAnotherLeague();
        $this->authenticateAsAdmin([$scenario['league']->id]);

        $this->putJson("/api/game-events/{$event->id}", ['game_id' => $otherGame->id])
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);

        $this->assertDatabaseHas('game_events', [
            'id' => $event->id,
            'game_id' => $scenario['game']->id,
            'league_id' => $scenario['league']->id,
        ]);
    }

    public function test_master_can_create_event_and_move_it_to_game_of_any_league(): void
    {
        $this->authenticate();
        [, $event, $otherGame] = $this->eventAndGameOfAnotherLeague();

        $this->postJson('/api/game-events', $this->eventPayload())->assertCreated();
        $this->putJson("/api/game-events/{$event->id}", ['game_id' => $otherGame->id])
            ->assertOk()
            ->assertJsonPath('data.game_id', $otherGame->id);

        $this->assertDatabaseHas('game_events', ['id' => $event->id, 'league_id' => $otherGame->league_id]);
    }

    public static function arrayFieldStoreProvider(): array
    {
        return [
            'game_id' => ['game_id'],
            'player_id' => ['player_id'],
            'related_player_id' => ['related_player_id'],
        ];
    }

    #[DataProvider('arrayFieldStoreProvider')]
    public function test_store_with_field_sent_as_array_returns_422_instead_of_500(string $field): void
    {
        $this->authenticate();
        // Cenário antes do payload: o player_id do payload não é 1, então `(int) [id]` (= 1) não cai
        // por acaso na checagem "relacionado igual ao principal" e o after() chegaria ao find(array).
        $scenario = $this->buildGameScenario();
        $payload = $this->eventPayload();
        $payload[$field] = [$field === 'game_id' ? $scenario['game']->id : $scenario['player']->id];

        $this->postJson('/api/game-events', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('game_events', 0);
    }

    public static function arrayFieldUpdateProvider(): array
    {
        return ['game_id' => ['game_id'], 'player_id' => ['player_id']];
    }

    #[DataProvider('arrayFieldUpdateProvider')]
    public function test_update_with_field_sent_as_array_returns_422_instead_of_500(string $field): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $event = GameEvent::factory()->create([
            'game_id' => $scenario['game']->id,
            'league_id' => $scenario['league']->id,
            'team_id' => $scenario['homeTeam']->id,
            'player_id' => $scenario['player']->id,
        ]);
        $value = $field === 'game_id' ? $scenario['game']->id : $scenario['teammate']->id;

        $this->putJson("/api/game-events/{$event->id}", [$field => [$value]])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseHas('game_events', ['id' => $event->id, 'player_id' => $scenario['player']->id]);
    }

    // ── Jogador removido (soft delete, FOOT-0046) ─────────────────────────────

    /** Evento do cenário (gol do `player`, assistência opcional), gravado direto no banco. */
    private function eventOf(array $scenario, ?Player $assist = null): GameEvent
    {
        return GameEvent::create([
            'game_id' => $scenario['game']->id,
            'league_id' => $scenario['league']->id,
            'team_id' => $scenario['homeTeam']->id,
            'player_id' => $scenario['player']->id,
            'related_player_id' => $assist?->id,
            'type' => 'goal',
            'minute' => 10,
        ]);
    }

    public static function playerFieldProvider(): array
    {
        return ['player_id' => ['player_id'], 'related_player_id' => ['related_player_id']];
    }

    #[DataProvider('playerFieldProvider')]
    public function test_store_fails_with_removed_player(string $field): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $removed = Player::factory()->create(['team_id' => $scenario['homeTeam']->id]);
        $removed->delete();
        $payload = [
            'game_id' => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'team_id' => $scenario['homeTeam']->id,
            'type' => 'goal',
            $field => $removed->id,
        ];

        $this->postJson('/api/game-events', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field => 'O jogador informado foi removido ou não existe.']);

        $this->assertDatabaseCount('game_events', 0);
    }

    public function test_store_response_includes_player_and_related_player(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();

        $this->postJson('/api/game-events', [
            'game_id' => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'related_player_id' => $scenario['teammate']->id,
            'team_id' => $scenario['homeTeam']->id,
            'type' => 'goal',
        ])
            ->assertCreated()
            ->assertJsonPath('data.player', [
                'id' => $scenario['player']->id,
                'name' => $scenario['player']->name,
                'removed' => false,
            ])
            ->assertJsonPath('data.related_player', [
                'id' => $scenario['teammate']->id,
                'name' => $scenario['teammate']->name,
                'removed' => false,
            ]);
    }

    public function test_index_by_game_includes_removed_player_and_null_related_player(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $this->eventOf($scenario);
        $scenario['player']->delete();

        $this->getJson("/api/game-events?game_id={$scenario['game']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.player', [
                'id' => $scenario['player']->id,
                'name' => $scenario['player']->name,
                'removed' => true,
            ])
            ->assertJsonPath('data.0.related_player', null);
    }

    public function test_update_keeping_removed_player_already_recorded_succeeds(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $event = $this->eventOf($scenario, $scenario['teammate']);
        $scenario['player']->delete();
        $scenario['teammate']->delete();

        $this->putJson("/api/game-events/{$event->id}", ['minute' => 55])
            ->assertOk()
            ->assertJsonPath('data.minute', 55)
            ->assertJsonPath('data.player.removed', true)
            ->assertJsonPath('data.related_player.removed', true);

        // Reenviar os mesmos jogadores removidos também passa (o front costuma mandar o form inteiro).
        $this->putJson("/api/game-events/{$event->id}", [
            'player_id' => $scenario['player']->id,
            'related_player_id' => $scenario['teammate']->id,
            'minute' => 56,
        ])->assertOk();
    }

    #[DataProvider('playerFieldProvider')]
    public function test_update_to_another_removed_player_fails(string $field): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $event = $this->eventOf($scenario, $scenario['teammate']);
        $scenario[$field === 'player_id' ? 'player' : 'teammate']->delete();
        $otherRemoved = Player::factory()->create(['team_id' => $scenario['homeTeam']->id]);
        $otherRemoved->delete();

        $this->putJson("/api/game-events/{$event->id}", [$field => $otherRemoved->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field => 'O jogador informado foi removido ou não existe.']);

        $this->assertDatabaseHas('game_events', [
            'id' => $event->id,
            'player_id' => $scenario['player']->id,
            'related_player_id' => $scenario['teammate']->id,
        ]);
    }

    public function test_update_changing_player_returns_new_player_in_response(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $event = $this->eventOf($scenario);
        $newScorer = Player::factory()->create(['team_id' => $scenario['homeTeam']->id]);

        $this->putJson("/api/game-events/{$event->id}", ['player_id' => $newScorer->id])
            ->assertOk()
            ->assertJsonPath('data.player.id', $newScorer->id)
            ->assertJsonPath('data.player.name', $newScorer->name);
    }
}
