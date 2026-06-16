<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\Game;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): User
    {
        $user = User::factory()->create();
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

    public function test_guest_cannot_list_games(): void
    {
        $response = $this->getJson('/api/games');

        $response->assertStatus(401);
    }

    public function test_index_returns_all_games(): void
    {
        $this->authenticate();
        Game::factory()->count(3)->create();

        $response = $this->getJson('/api/games');

        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_store_creates_game(): void
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

    public function test_show_returns_game(): void
    {
        $this->authenticate();
        $game = Game::factory()->create();

        $response = $this->getJson("/api/games/{$game->id}");

        $response->assertOk()->assertJsonPath('data.id', $game->id);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/games/999');

        $response->assertStatus(422)
            ->assertJson(['message' => 'Partida não encontrada!']);
    }

    public function test_update_modifies_game(): void
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

    public function test_destroy_deletes_game(): void
    {
        $this->authenticate();
        $game = Game::factory()->create();

        $response = $this->deleteJson("/api/games/{$game->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('games', ['id' => $game->id]);
    }

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
