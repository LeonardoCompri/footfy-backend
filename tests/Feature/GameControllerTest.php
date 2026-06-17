<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\Game;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    private function gamePayload(array $overrides = []): array
    {
        $championship = Championship::factory()->create();
        $home         = Team::factory()->create();
        $away         = Team::factory()->create();

        return array_merge([
            'championship_id' => $championship->id,
            'home_team_id'    => $home->id,
            'away_team_id'    => $away->id,
            'status'          => 'scheduled',
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
            ->assertJsonPath('data.championship_id', $payload['championship_id'])
            ->assertJsonPath('data.home_team_id', $payload['home_team_id'])
            ->assertJsonPath('data.status', 'scheduled');

        $this->assertDatabaseHas('games', [
            'championship_id' => $payload['championship_id'],
            'home_team_id'    => $payload['home_team_id'],
            'away_team_id'    => $payload['away_team_id'],
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
            'status'     => 'finished',
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
        $championship = Championship::factory()->create();
        $team         = Team::factory()->create();

        $response = $this->postJson('/api/games', [
            'championship_id' => $championship->id,
            'home_team_id'    => $team->id,
            'away_team_id'    => $team->id,
            'status'          => 'scheduled',
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

    public function test_store_fails_when_championship_does_not_exist(): void
    {
        $this->authenticate();
        $home = Team::factory()->create();
        $away = Team::factory()->create();

        $response = $this->postJson('/api/games', [
            'championship_id' => 999,
            'home_team_id'    => $home->id,
            'away_team_id'    => $away->id,
            'status'          => 'scheduled',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_accepts_optional_stadium(): void
    {
        $this->authenticate();
        $stadium = Stadium::factory()->create();
        $payload = $this->gamePayload(['stadium_id' => $stadium->id]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertCreated()->assertJsonPath('data.stadium_id', $stadium->id);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/games/999');

        $response->assertStatus(422)->assertJson(['message' => 'Partida não encontrada!']);
    }

    // ── Admin: escopo por campeonato ──────────────────────────────────────────

    public function test_admin_index_only_sees_games_in_assigned_championships(): void
    {
        $assignedChampionship   = Championship::factory()->create();
        $unassignedChampionship = Championship::factory()->create();
        $home                   = Team::factory()->create();
        $away                   = Team::factory()->create();

        $gameInScope  = Game::factory()->create(['championship_id' => $assignedChampionship->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id]);
        $gameOutScope = Game::factory()->create(['championship_id' => $unassignedChampionship->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id]);

        $this->authenticateAsAdmin([$assignedChampionship->id]);

        $response = $this->getJson('/api/games');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $gameInScope->id);
    }

    public function test_admin_with_no_championships_sees_empty_game_list(): void
    {
        Game::factory()->count(2)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/games');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_show_game_in_assigned_championship(): void
    {
        $championship = Championship::factory()->create();
        $home         = Team::factory()->create();
        $away         = Team::factory()->create();
        $game         = Game::factory()->create(['championship_id' => $championship->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id]);

        $this->authenticateAsAdmin([$championship->id]);

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

    public function test_destroying_championship_cascades_to_game(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        $game         = Game::factory()->create(['championship_id' => $championship->id]);

        $this->deleteJson("/api/tournaments/{$championship->id}")->assertStatus(204);

        $this->assertDatabaseMissing('games', ['id' => $game->id]);
    }

    public function test_destroying_stadium_nullifies_game_stadium_id(): void
    {
        $this->authenticate();
        $stadium = Stadium::factory()->create();
        $game    = Game::factory()->create(['stadium_id' => $stadium->id]);

        $this->deleteJson("/api/stadiums/{$stadium->id}")->assertStatus(204);

        $this->assertDatabaseHas('games', ['id' => $game->id, 'stadium_id' => null]);
    }
}
