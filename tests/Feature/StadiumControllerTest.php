<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Stadium;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StadiumControllerTest extends TestCase
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

    public function test_guest_cannot_list_stadiums(): void
    {
        $response = $this->getJson('/api/stadiums');

        $response->assertStatus(401);
    }

    // ── Master: acesso irrestrito ao index ────────────────────────────────────

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
        $league = League::factory()->create();

        $response = $this->postJson('/api/stadiums', [
            'league_id' => $league->id,
            'name' => 'Arena Y',
            'city' => 'Rio de Janeiro',
            'capacity' => 60000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Arena Y')
            ->assertJsonPath('data.league_id', $league->id);
        $this->assertDatabaseHas('stadiums', ['name' => 'Arena Y', 'league_id' => $league->id]);
    }

    public function test_store_fails_validation_without_required_fields(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/stadiums', ['name' => 'Arena Incompleta']);

        $response->assertStatus(422);
    }

    public function test_store_fails_without_league_id(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/stadiums', [
            'name' => 'Arena Sem Liga',
            'city' => 'SP',
            'capacity' => 1000,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_with_negative_capacity(): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        $response = $this->postJson('/api/stadiums', [
            'league_id' => $league->id,
            'name' => 'Arena Z',
            'city' => 'SP',
            'capacity' => -10,
        ]);

        $response->assertStatus(422);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/stadiums/999');

        $response->assertStatus(422)->assertJson(['message' => 'Estádio não encontrado!']);
    }

    // ── Master: acesso irrestrito ───────────────────────────────────────────────

    public function test_master_can_show_any_stadium(): void
    {
        $this->authenticate();
        $stadium = Stadium::factory()->create();

        $response = $this->getJson("/api/stadiums/{$stadium->id}");

        $response->assertOk()->assertJsonPath('data.id', $stadium->id);
    }

    public function test_master_can_update_any_stadium(): void
    {
        $this->authenticate();
        $stadium = Stadium::factory()->create(['capacity' => 1000]);

        $response = $this->putJson("/api/stadiums/{$stadium->id}", ['capacity' => 2000]);

        $response->assertOk()->assertJsonPath('data.capacity', 2000);
        $this->assertDatabaseHas('stadiums', ['id' => $stadium->id, 'capacity' => 2000]);
    }

    public function test_master_can_delete_stadium(): void
    {
        $this->authenticate();
        $stadium = Stadium::factory()->create();

        $response = $this->deleteJson("/api/stadiums/{$stadium->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('stadiums', ['id' => $stadium->id]);
    }

    // ── Admin: escopo por liga ───────────────────────────────────────────────

    public function test_admin_index_only_sees_stadiums_in_assigned_leagues(): void
    {
        $leagueA = League::factory()->create();
        $leagueB = League::factory()->create();
        $stadiumInScope = Stadium::factory()->create(['league_id' => $leagueA->id]);
        Stadium::factory()->create(['league_id' => $leagueB->id]);

        $this->authenticateAsAdmin([$leagueA->id]);

        $response = $this->getJson('/api/stadiums');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $stadiumInScope->id);
    }

    public function test_admin_with_no_leagues_sees_empty_stadium_list(): void
    {
        Stadium::factory()->count(2)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/stadiums');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    // ── Filtro por league_id ─────────────────────────────────────────────────

    public function test_master_can_filter_index_by_league_id(): void
    {
        $this->authenticate();
        $leagueA = League::factory()->create();
        $leagueB = League::factory()->create();
        $stadiumA = Stadium::factory()->create(['league_id' => $leagueA->id]);
        Stadium::factory()->create(['league_id' => $leagueB->id]);

        $response = $this->getJson("/api/stadiums?league_id={$leagueA->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $stadiumA->id);
    }

    public function test_admin_can_filter_index_by_assigned_league_id(): void
    {
        $leagueA = League::factory()->create();
        $leagueB = League::factory()->create();
        $stadiumA = Stadium::factory()->create(['league_id' => $leagueA->id]);
        Stadium::factory()->create(['league_id' => $leagueB->id]);

        $this->authenticateAsAdmin([$leagueA->id, $leagueB->id]);

        $response = $this->getJson("/api/stadiums?league_id={$leagueA->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $stadiumA->id);
    }

    public function test_admin_cannot_filter_index_by_unassigned_league_id(): void
    {
        $assignedLeague = League::factory()->create();
        $otherLeague = League::factory()->create();
        $this->authenticateAsAdmin([$assignedLeague->id]);

        $response = $this->getJson("/api/stadiums?league_id={$otherLeague->id}");

        $response->assertStatus(422)->assertJson(['message' => 'Você não tem acesso a essa liga.']);
    }

    public function test_admin_with_league_access_can_show_stadium(): void
    {
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/stadiums/{$stadium->id}");

        $response->assertOk()->assertJsonPath('data.id', $stadium->id);
    }

    public function test_admin_without_league_access_cannot_show_stadium(): void
    {
        $stadium = Stadium::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/stadiums/{$stadium->id}");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_with_league_access_can_update_stadium(): void
    {
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id, 'capacity' => 1000]);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->putJson("/api/stadiums/{$stadium->id}", ['capacity' => 5000]);

        $response->assertOk()->assertJsonPath('data.capacity', 5000);
    }

    public function test_admin_without_league_access_cannot_update_stadium(): void
    {
        $stadium = Stadium::factory()->create(['capacity' => 1000]);
        $this->authenticateAsAdmin();

        $response = $this->putJson("/api/stadiums/{$stadium->id}", ['capacity' => 5000]);

        $response->assertForbidden();
    }

    public function test_admin_cannot_delete_stadium_even_with_league_access(): void
    {
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->deleteJson("/api/stadiums/{$stadium->id}");

        $response->assertForbidden();
    }

    public function test_admin_without_league_access_cannot_delete_stadium(): void
    {
        $stadium = Stadium::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->deleteJson("/api/stadiums/{$stadium->id}");

        $response->assertForbidden();
    }
}
