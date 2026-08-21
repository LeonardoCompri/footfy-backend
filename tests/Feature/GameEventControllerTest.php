<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GameEventControllerTest extends TestCase
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
}
