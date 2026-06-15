<?php

namespace Tests\Feature;

use App\Models\Stadium;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StadiumControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_list_stadiums(): void
    {
        $response = $this->getJson('/api/stadiums');

        $response->assertStatus(401);
    }

    public function test_index_returns_all_stadiums(): void
    {
        $this->authenticate();
        Stadium::factory()->count(2)->create();

        $response = $this->getJson('/api/stadiums');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_store_creates_stadium(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/stadiums', [
            'name' => 'Arena Y',
            'city' => 'Rio de Janeiro',
            'capacity' => 60000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Arena Y');

        $this->assertDatabaseHas('stadiums', ['name' => 'Arena Y']);
    }

    public function test_store_fails_validation_without_required_fields(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/stadiums', ['name' => 'Arena Incompleta']);

        $response->assertStatus(422);
    }

    public function test_store_fails_with_negative_capacity(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/stadiums', [
            'name' => 'Arena Z',
            'city' => 'SP',
            'capacity' => -10,
        ]);

        $response->assertStatus(422);
    }

    public function test_show_returns_stadium(): void
    {
        $this->authenticate();
        $stadium = Stadium::factory()->create();

        $response = $this->getJson("/api/stadiums/{$stadium->id}");

        $response->assertOk()->assertJsonPath('data.id', $stadium->id);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/stadiums/999');

        $response->assertStatus(422)
            ->assertJson(['message' => 'Estádio não encontrado!']);
    }

    public function test_update_modifies_stadium(): void
    {
        $this->authenticate();
        $stadium = Stadium::factory()->create(['capacity' => 1000]);

        $response = $this->putJson("/api/stadiums/{$stadium->id}", ['capacity' => 2000]);

        $response->assertOk()->assertJsonPath('data.capacity', 2000);
        $this->assertDatabaseHas('stadiums', ['id' => $stadium->id, 'capacity' => 2000]);
    }

    public function test_destroy_deletes_stadium(): void
    {
        $this->authenticate();
        $stadium = Stadium::factory()->create();

        $response = $this->deleteJson("/api/stadiums/{$stadium->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('stadiums', ['id' => $stadium->id]);
    }
}
