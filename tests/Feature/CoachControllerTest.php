<?php

namespace Tests\Feature;

use App\Models\Coach;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoachControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_list_coaches(): void
    {
        $response = $this->getJson('/api/coaches');

        $response->assertStatus(401);
    }

    public function test_index_returns_all_coaches(): void
    {
        $this->authenticate();
        Coach::factory()->count(2)->create();

        $response = $this->getJson('/api/coaches');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_store_creates_coach(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();

        $response = $this->postJson('/api/coaches', [
            'team_id'    => $team->id,
            'name'       => 'Técnico Teste',
            'birth_date' => '1975-03-15',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Técnico Teste')
            ->assertJsonPath('data.team_id', $team->id);

        $this->assertDatabaseHas('coaches', ['name' => 'Técnico Teste', 'team_id' => $team->id]);
    }

    public function test_store_fails_validation_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/coaches', []);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_team_id_does_not_exist(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/coaches', [
            'name'    => 'Técnico Teste',
            'team_id' => 999,
        ]);

        $response->assertStatus(422);
    }

    public function test_show_returns_coach(): void
    {
        $this->authenticate();
        $coach = Coach::factory()->create();

        $response = $this->getJson("/api/coaches/{$coach->id}");

        $response->assertOk()->assertJsonPath('data.id', $coach->id);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/coaches/999');

        $response->assertStatus(422)
            ->assertJson(['message' => 'Técnico não encontrado!']);
    }

    public function test_update_modifies_coach(): void
    {
        $this->authenticate();
        $coach = Coach::factory()->create(['name' => 'Nome Antigo']);

        $response = $this->putJson("/api/coaches/{$coach->id}", ['name' => 'Nome Novo']);

        $response->assertOk()->assertJsonPath('data.name', 'Nome Novo');
        $this->assertDatabaseHas('coaches', ['id' => $coach->id, 'name' => 'Nome Novo']);
    }

    public function test_destroy_deletes_coach(): void
    {
        $this->authenticate();
        $coach = Coach::factory()->create();

        $response = $this->deleteJson("/api/coaches/{$coach->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('coaches', ['id' => $coach->id]);
    }

    public function test_destroying_team_nullifies_coach_team_id(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $coach = Coach::factory()->create(['team_id' => $team->id]);

        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(204);

        $this->assertDatabaseHas('coaches', ['id' => $coach->id, 'team_id' => null]);
    }
}
