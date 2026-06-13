<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlayerControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_list_players(): void
    {
        $response = $this->getJson('/api/players');

        $response->assertStatus(401);
    }

    public function test_index_returns_all_players(): void
    {
        $this->authenticate();
        Player::factory()->count(2)->create();

        $response = $this->getJson('/api/players');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_store_creates_player_with_team(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();

        $response = $this->postJson('/api/players', [
            'team_id' => $team->id,
            'name' => 'Jogador Teste',
            'position' => 'ATA',
            'number' => 9,
            'document' => '12345678901',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.team_id', $team->id)
            ->assertJsonPath('data.document', '12345678901');

        $this->assertDatabaseHas('players', ['document' => '12345678901', 'team_id' => $team->id]);
    }

    public function test_store_fails_validation_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/players', []);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_team_id_does_not_exist(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/players', [
            'name' => 'Jogador Teste',
            'team_id' => 999,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_document_is_not_11_digits(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/players', [
            'name' => 'Jogador Teste',
            'document' => '123',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_document_already_exists(): void
    {
        $this->authenticate();
        Player::factory()->create(['document' => '11122233344']);

        $response = $this->postJson('/api/players', [
            'name' => 'Outro Jogador',
            'document' => '11122233344',
        ]);

        $response->assertStatus(422);
    }

    public function test_show_returns_player(): void
    {
        $this->authenticate();
        $player = Player::factory()->create();

        $response = $this->getJson("/api/players/{$player->id}");

        $response->assertOk()->assertJsonPath('data.id', $player->id);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/players/999');

        $response->assertStatus(422)
            ->assertJson(['message' => 'Jogador não encontrado!']);
    }

    public function test_update_modifies_player(): void
    {
        $this->authenticate();
        $player = Player::factory()->create(['position' => 'ZAG']);

        $response = $this->putJson("/api/players/{$player->id}", ['position' => 'LAT']);

        $response->assertOk()->assertJsonPath('data.position', 'LAT');
        $this->assertDatabaseHas('players', ['id' => $player->id, 'position' => 'LAT']);
    }

    public function test_update_allows_keeping_own_document(): void
    {
        $this->authenticate();
        $player = Player::factory()->create(['document' => '55566677788']);

        $response = $this->putJson("/api/players/{$player->id}", [
            'name' => $player->name,
            'document' => '55566677788',
        ]);

        $response->assertOk();
    }

    public function test_destroy_deletes_player(): void
    {
        $this->authenticate();
        $player = Player::factory()->create();

        $response = $this->deleteJson("/api/players/{$player->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('players', ['id' => $player->id]);
    }

    public function test_destroying_team_nullifies_player_team_id(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $player = Player::factory()->create(['team_id' => $team->id]);

        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(204);

        $this->assertDatabaseHas('players', ['id' => $player->id, 'team_id' => null]);
    }
}
