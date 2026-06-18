<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\Game;
use App\Models\Goal;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GoalControllerTest extends TestCase
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

    private function authenticateAsAdmin(array $championshipIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        if (!empty($championshipIds)) {
            $user->championships()->sync($championshipIds);
        }
        Sanctum::actingAs($user);
        return $user;
    }

    /**
     * Monta um cenário consistente: campeonato, jogo com home/away times definidos
     * e um jogador vinculado a um dos times do jogo (respeitando a regra de negócio do gol).
     */
    private function buildGameScenario(?int $championshipId = null): array
    {
        $championship = $championshipId
            ? Championship::find($championshipId)
            : Championship::factory()->create();
        $homeTeam = Team::factory()->create();
        $awayTeam = Team::factory()->create();
        $game = Game::factory()->create([
            'championship_id' => $championship->id,
            'home_team_id'    => $homeTeam->id,
            'away_team_id'    => $awayTeam->id,
        ]);
        $player = Player::factory()->create(['team_id' => $homeTeam->id]);

        return [
            'championship' => $championship,
            'homeTeam'      => $homeTeam,
            'awayTeam'      => $awayTeam,
            'game'          => $game,
            'player'        => $player,
        ];
    }

    private function goalPayload(array $overrides = []): array
    {
        $scenario = $this->buildGameScenario();

        return array_merge([
            'game_id'   => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'team_id'   => $scenario['homeTeam']->id,
            'minute'    => 10,
        ], $overrides);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_guest_cannot_list_goals(): void
    {
        $response = $this->getJson('/api/goals');

        $response->assertStatus(401);
    }

    // ── Master ────────────────────────────────────────────────────────────────

    public function test_master_index_returns_all_goals(): void
    {
        $this->authenticate();
        Goal::factory()->count(3)->create();

        $response = $this->getJson('/api/goals');

        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_master_can_create_goal(): void
    {
        $this->authenticate();
        $payload = $this->goalPayload(['minute' => 23]);

        $response = $this->postJson('/api/goals', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.game_id', $payload['game_id'])
            ->assertJsonPath('data.player_id', $payload['player_id'])
            ->assertJsonPath('data.team_id', $payload['team_id'])
            ->assertJsonPath('data.minute', 23);

        $this->assertDatabaseHas('goals', [
            'game_id'   => $payload['game_id'],
            'player_id' => $payload['player_id'],
            'team_id'   => $payload['team_id'],
        ]);
    }

    public function test_master_can_show_goal(): void
    {
        $this->authenticate();
        $goal = Goal::factory()->create();

        $response = $this->getJson("/api/goals/{$goal->id}");

        $response->assertOk()->assertJsonPath('data.id', $goal->id);
    }

    public function test_master_can_update_goal(): void
    {
        $this->authenticate();
        $goal = Goal::factory()->create(['minute' => 10]);

        $response = $this->putJson("/api/goals/{$goal->id}", [
            'minute' => 45,
        ]);

        $response->assertOk()->assertJsonPath('data.minute', 45);

        $this->assertDatabaseHas('goals', ['id' => $goal->id, 'minute' => 45]);
    }

    public function test_master_can_delete_goal(): void
    {
        $this->authenticate();
        $goal = Goal::factory()->create();

        $response = $this->deleteJson("/api/goals/{$goal->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('goals', ['id' => $goal->id]);
    }

    // ── Validações ────────────────────────────────────────────────────────────

    public function test_store_fails_without_required_fields(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/goals', []);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_game_does_not_exist(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();

        $response = $this->postJson('/api/goals', [
            'game_id'   => 999,
            'player_id' => $scenario['player']->id,
            'team_id'   => $scenario['homeTeam']->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_team_does_not_participate_in_game(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $outsiderTeam = Team::factory()->create();

        $response = $this->postJson('/api/goals', [
            'game_id'   => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'team_id'   => $outsiderTeam->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_player_does_not_belong_to_team(): void
    {
        $this->authenticate();
        $scenario = $this->buildGameScenario();
        $otherPlayer = Player::factory()->create(['team_id' => $scenario['awayTeam']->id]);

        $response = $this->postJson('/api/goals', [
            'game_id'   => $scenario['game']->id,
            'player_id' => $otherPlayer->id,
            'team_id'   => $scenario['homeTeam']->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_with_negative_minute(): void
    {
        $this->authenticate();
        $payload = $this->goalPayload(['minute' => -1]);

        $response = $this->postJson('/api/goals', $payload);

        $response->assertStatus(422);
    }

    public function test_update_fails_when_team_does_not_participate_in_game(): void
    {
        $this->authenticate();
        $goal = Goal::factory()->create();
        $outsiderTeam = Team::factory()->create();

        $response = $this->putJson("/api/goals/{$goal->id}", [
            'team_id' => $outsiderTeam->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/goals/999');

        $response->assertStatus(422)->assertJson(['message' => 'Gol não encontrado!']);
    }

    // ── Admin: escopo por campeonato ──────────────────────────────────────────

    public function test_admin_index_only_sees_goals_in_assigned_championships(): void
    {
        $assignedChampionship   = Championship::factory()->create();
        $unassignedChampionship = Championship::factory()->create();

        $scenarioInScope  = $this->buildGameScenario($assignedChampionship->id);
        $scenarioOutScope = $this->buildGameScenario($unassignedChampionship->id);

        $goalInScope = Goal::factory()->create([
            'game_id'   => $scenarioInScope['game']->id,
            'player_id' => $scenarioInScope['player']->id,
            'team_id'   => $scenarioInScope['homeTeam']->id,
        ]);
        Goal::factory()->create([
            'game_id'   => $scenarioOutScope['game']->id,
            'player_id' => $scenarioOutScope['player']->id,
            'team_id'   => $scenarioOutScope['homeTeam']->id,
        ]);

        $this->authenticateAsAdmin([$assignedChampionship->id]);

        $response = $this->getJson('/api/goals');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $goalInScope->id);
    }

    public function test_admin_with_no_championships_sees_empty_goal_list(): void
    {
        Goal::factory()->count(2)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/goals');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_show_goal_in_assigned_championship(): void
    {
        $championship = Championship::factory()->create();
        $scenario = $this->buildGameScenario($championship->id);
        $goal = Goal::factory()->create([
            'game_id'   => $scenario['game']->id,
            'player_id' => $scenario['player']->id,
            'team_id'   => $scenario['homeTeam']->id,
        ]);

        $this->authenticateAsAdmin([$championship->id]);

        $response = $this->getJson("/api/goals/{$goal->id}");

        $response->assertOk()->assertJsonPath('data.id', $goal->id);
    }

    public function test_admin_cannot_show_goal_outside_assignments(): void
    {
        $goal = Goal::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/goals/{$goal->id}");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_delete_goal_outside_assignments(): void
    {
        $goal = Goal::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->deleteJson("/api/goals/{$goal->id}");

        $response->assertForbidden();
    }

    // ── Integridade referencial ───────────────────────────────────────────────

    public function test_destroying_game_cascades_to_goal(): void
    {
        $this->authenticate();
        $goal = Goal::factory()->create();
        $gameId = $goal->game_id;

        $this->deleteJson("/api/games/{$gameId}")->assertStatus(204);

        $this->assertDatabaseMissing('goals', ['id' => $goal->id]);
    }
}
