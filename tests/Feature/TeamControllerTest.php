<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_list_teams(): void
    {
        $response = $this->getJson('/api/teams');

        $response->assertStatus(401);
    }

    public function test_index_returns_all_teams(): void
    {
        $this->authenticate();
        Team::factory()->count(3)->create();

        $response = $this->getJson('/api/teams');

        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_store_creates_team(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teams', [
            'name' => 'Sport Club X',
            'nickname' => 'SCX',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Sport Club X');

        $this->assertDatabaseHas('teams', ['name' => 'Sport Club X']);
    }

    public function test_store_fails_validation_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teams', []);

        $response->assertStatus(422);
    }

    public function test_show_returns_team(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();

        $response = $this->getJson("/api/teams/{$team->id}");

        $response->assertOk()->assertJsonPath('data.id', $team->id);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teams/999');

        $response->assertStatus(422)
            ->assertJson(['message' => 'Time não encontrado!']);
    }

    public function test_update_modifies_team(): void
    {
        $this->authenticate();
        $team = Team::factory()->create(['nickname' => 'OLD']);

        $response = $this->putJson("/api/teams/{$team->id}", ['nickname' => 'NEW']);

        $response->assertOk()->assertJsonPath('data.nickname', 'NEW');
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'nickname' => 'NEW']);
    }

    public function test_destroy_deletes_team(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();

        $response = $this->deleteJson("/api/teams/{$team->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }
}
