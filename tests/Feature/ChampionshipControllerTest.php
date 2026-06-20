<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChampionshipControllerTest extends TestCase
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

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_guest_cannot_list_championships(): void
    {
        $response = $this->getJson('/api/tournaments');

        $response->assertStatus(401);
    }

    // ── Master: acesso irrestrito ─────────────────────────────────────────────

    public function test_master_index_returns_all_championships(): void
    {
        $this->authenticate();
        Championship::factory()->count(2)->create();

        $response = $this->getJson('/api/tournaments');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_master_can_create_championship(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/tournaments', [
            'name'       => 'Campeonato Teste',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-06-01',
            'organizer'  => 'Federação Teste',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Campeonato Teste');
        $this->assertDatabaseHas('championships', ['name' => 'Campeonato Teste']);
    }

    public function test_master_can_show_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();

        $response = $this->getJson("/api/tournaments/{$championship->id}");

        $response->assertOk()->assertJsonPath('data.id', $championship->id);
    }

    public function test_master_can_update_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create(['organizer' => 'Antigo']);

        $response = $this->putJson("/api/tournaments/{$championship->id}", ['organizer' => 'Novo']);

        $response->assertOk()->assertJsonPath('data.organizer', 'Novo');
        $this->assertDatabaseHas('championships', ['id' => $championship->id, 'organizer' => 'Novo']);
    }

    public function test_master_can_delete_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();

        $response = $this->deleteJson("/api/tournaments/{$championship->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('championships', ['id' => $championship->id]);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/tournaments/999');

        $response->assertStatus(422)->assertJson(['message' => 'Campeonato não encontrado!']);
    }

    // ── Validações ────────────────────────────────────────────────────────────

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
            'name'       => 'Campeonato Teste',
            'start_date' => '2026-06-01',
            'end_date'   => '2026-01-01',
        ]);

        $response->assertStatus(422);
    }

    // ── Admin: acesso restrito ao seu escopo ──────────────────────────────────

    public function test_admin_index_only_sees_assigned_championships(): void
    {
        $assigned   = Championship::factory()->create();
        $unassigned = Championship::factory()->create();

        $this->authenticateAsAdmin([$assigned->id]);

        $response = $this->getJson('/api/tournaments');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id);
    }

    public function test_admin_can_show_assigned_championship(): void
    {
        $championship = Championship::factory()->create();
        $this->authenticateAsAdmin([$championship->id]);

        $response = $this->getJson("/api/tournaments/{$championship->id}");

        $response->assertOk()->assertJsonPath('data.id', $championship->id);
    }

    public function test_admin_cannot_show_unassigned_championship(): void
    {
        $championship = Championship::factory()->create();
        $this->authenticateAsAdmin(); // sem campeonatos associados

        $response = $this->getJson("/api/tournaments/{$championship->id}");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_create_championship(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/tournaments', ['name' => 'Novo']);

        $response->assertForbidden();
    }

    public function test_admin_can_update_assigned_championship(): void
    {
        $championship = Championship::factory()->create(['organizer' => 'Antigo']);
        $this->authenticateAsAdmin([$championship->id]);

        $response = $this->putJson("/api/tournaments/{$championship->id}", ['organizer' => 'Novo']);

        $response->assertOk()->assertJsonPath('data.organizer', 'Novo');
    }

    public function test_admin_cannot_update_unassigned_championship(): void
    {
        $championship = Championship::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->putJson("/api/tournaments/{$championship->id}", ['organizer' => 'X']);

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_delete_championship(): void
    {
        $championship = Championship::factory()->create();
        $this->authenticateAsAdmin([$championship->id]);

        $response = $this->deleteJson("/api/tournaments/{$championship->id}");

        $response->assertForbidden();
    }

    public function test_admin_with_no_championships_sees_empty_list(): void
    {
        Championship::factory()->count(3)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/tournaments');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    // ── Rotas públicas ────────────────────────────────────────────────────────

    public function test_guest_can_list_championships_via_public_route(): void
    {
        Championship::factory()->count(2)->create();

        $response = $this->getJson('/api/public/championships');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_guest_can_show_championship_via_public_route_with_games(): void
    {
        $championship = Championship::factory()->create();
        $game = Game::factory()->create(['championship_id' => $championship->id]);

        $response = $this->getJson("/api/public/championships/{$championship->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $championship->id)
            ->assertJsonPath('data.games.0.id', $game->id);
    }

    public function test_guest_show_via_public_route_returns_error_when_not_found(): void
    {
        $response = $this->getJson('/api/public/championships/999');

        $response->assertStatus(422)->assertJson(['message' => 'Campeonato não encontrado!']);
    }
}
