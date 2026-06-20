<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\League;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeagueControllerTest extends TestCase
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

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_guest_cannot_list_leagues(): void
    {
        $response = $this->getJson('/api/leagues');

        $response->assertStatus(401);
    }

    // ── Master: acesso irrestrito ─────────────────────────────────────────────

    public function test_master_index_returns_all_leagues(): void
    {
        $this->authenticate();
        League::factory()->count(2)->create();

        $response = $this->getJson('/api/leagues');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_master_can_create_league(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/leagues', [
            'name' => 'Campeonato Teste',
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-01',
            'organizer' => 'Federação Teste',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Campeonato Teste');
        $this->assertDatabaseHas('leagues', ['name' => 'Campeonato Teste']);
    }

    public function test_master_can_show_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        $response = $this->getJson("/api/leagues/{$league->id}");

        $response->assertOk()->assertJsonPath('data.id', $league->id);
    }

    public function test_master_can_update_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['organizer' => 'Antigo']);

        $response = $this->putJson("/api/leagues/{$league->id}", ['organizer' => 'Novo']);

        $response->assertOk()->assertJsonPath('data.organizer', 'Novo');
        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'organizer' => 'Novo']);
    }

    public function test_master_can_delete_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        $response = $this->deleteJson("/api/leagues/{$league->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('leagues', ['id' => $league->id]);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/leagues/999');

        $response->assertStatus(422)->assertJson(['message' => 'Liga não encontrada!']);
    }

    // ── Validações ────────────────────────────────────────────────────────────

    public function test_store_fails_validation_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/leagues', []);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_end_date_is_before_start_date(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/leagues', [
            'name' => 'Campeonato Teste',
            'start_date' => '2026-06-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertStatus(422);
    }

    // ── Admin: acesso restrito ao seu escopo ──────────────────────────────────

    public function test_admin_index_only_sees_assigned_leagues(): void
    {
        $assigned = League::factory()->create();
        $unassigned = League::factory()->create();

        $this->authenticateAsAdmin([$assigned->id]);

        $response = $this->getJson('/api/leagues');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id);
    }

    public function test_admin_can_show_assigned_league(): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/leagues/{$league->id}");

        $response->assertOk()->assertJsonPath('data.id', $league->id);
    }

    public function test_admin_cannot_show_unassigned_league(): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin(); // sem ligas associadas

        $response = $this->getJson("/api/leagues/{$league->id}");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_create_league(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/leagues', ['name' => 'Novo']);

        $response->assertForbidden();
    }

    public function test_admin_can_update_assigned_league(): void
    {
        $league = League::factory()->create(['organizer' => 'Antigo']);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->putJson("/api/leagues/{$league->id}", ['organizer' => 'Novo']);

        $response->assertOk()->assertJsonPath('data.organizer', 'Novo');
    }

    public function test_admin_cannot_update_unassigned_league(): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->putJson("/api/leagues/{$league->id}", ['organizer' => 'X']);

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_delete_league(): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->deleteJson("/api/leagues/{$league->id}");

        $response->assertForbidden();
    }

    public function test_admin_with_no_leagues_sees_empty_list(): void
    {
        League::factory()->count(3)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/leagues');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    // ── Rotas públicas ────────────────────────────────────────────────────────

    public function test_guest_can_list_leagues_via_public_route(): void
    {
        League::factory()->count(2)->create();

        $response = $this->getJson('/api/public/championships');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_guest_can_show_league_via_public_route_with_games(): void
    {
        $league = League::factory()->create();
        $game = Game::factory()->create(['league_id' => $league->id]);

        $response = $this->getJson("/api/public/championships/{$league->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $league->id)
            ->assertJsonPath('data.games.0.id', $game->id);
    }

    public function test_guest_show_via_public_route_returns_error_when_not_found(): void
    {
        $response = $this->getJson('/api/public/championships/999');

        $response->assertStatus(422)->assertJson(['message' => 'Liga não encontrada!']);
    }
}
