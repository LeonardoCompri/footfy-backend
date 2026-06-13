<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChampionshipControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_list_championships(): void
    {
        $response = $this->getJson('/api/tournaments');

        $response->assertStatus(401);
    }

    public function test_index_returns_all_championships(): void
    {
        $this->authenticate();
        Championship::factory()->count(2)->create();

        $response = $this->getJson('/api/tournaments');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_store_creates_championship(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/tournaments', [
            'name' => 'Campeonato Teste',
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-01',
            'organizer' => 'Federação Teste',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Campeonato Teste');

        $this->assertDatabaseHas('championships', ['name' => 'Campeonato Teste']);
    }

    public function test_store_fails_validation_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/tournaments', []);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_end_date_is_before_start_date(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/tournaments', [
            'name' => 'Campeonato Teste',
            'start_date' => '2026-06-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertStatus(422);
    }

    public function test_show_returns_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();

        $response = $this->getJson("/api/tournaments/{$championship->id}");

        $response->assertOk()->assertJsonPath('data.id', $championship->id);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/tournaments/999');

        $response->assertStatus(422)
            ->assertJson(['message' => 'Campeonato não encontrado!']);
    }

    public function test_update_modifies_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create(['organizer' => 'Antigo']);

        $response = $this->putJson("/api/tournaments/{$championship->id}", ['organizer' => 'Novo']);

        $response->assertOk()->assertJsonPath('data.organizer', 'Novo');
        $this->assertDatabaseHas('championships', ['id' => $championship->id, 'organizer' => 'Novo']);
    }

    public function test_destroy_deletes_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();

        $response = $this->deleteJson("/api/tournaments/{$championship->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('championships', ['id' => $championship->id]);
    }
}
