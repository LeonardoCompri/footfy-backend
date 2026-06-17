<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamControllerTest extends TestCase
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

    public function test_guest_cannot_list_teams(): void
    {
        $response = $this->getJson('/api/teams');

        $response->assertStatus(401);
    }

    // ── Master ────────────────────────────────────────────────────────────────

    public function test_master_index_returns_all_teams(): void
    {
        $this->authenticate();
        Team::factory()->count(3)->create();

        $response = $this->getJson('/api/teams');

        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_master_can_create_team(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teams', [
            'name'     => 'Sport Club X',
            'nickname' => 'SCX',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Sport Club X');
        $this->assertDatabaseHas('teams', ['name' => 'Sport Club X']);
    }

    public function test_master_can_show_team(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();

        $response = $this->getJson("/api/teams/{$team->id}");

        $response->assertOk()->assertJsonPath('data.id', $team->id);
    }

    public function test_master_can_update_team(): void
    {
        $this->authenticate();
        $team = Team::factory()->create(['nickname' => 'OLD']);

        $response = $this->putJson("/api/teams/{$team->id}", ['nickname' => 'NEW']);

        $response->assertOk()->assertJsonPath('data.nickname', 'NEW');
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'nickname' => 'NEW']);
    }

    public function test_master_can_delete_team(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();

        $response = $this->deleteJson("/api/teams/{$team->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teams/999');

        $response->assertStatus(422)->assertJson(['message' => 'Time não encontrado!']);
    }

    // ── Validações ────────────────────────────────────────────────────────────

    public function test_store_fails_validation_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teams', []);

        $response->assertStatus(422);
    }

    // ── Admin: escopo por campeonato ──────────────────────────────────────────

    public function test_admin_index_only_sees_teams_in_assigned_championships(): void
    {
        $championship   = Championship::factory()->create();
        $teamInScope    = Team::factory()->create();
        $teamOutOfScope = Team::factory()->create();

        $championship->teams()->attach($teamInScope->id);

        $this->authenticateAsAdmin([$championship->id]);

        $response = $this->getJson('/api/teams');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $teamInScope->id);
    }

    public function test_admin_with_no_championships_sees_empty_team_list(): void
    {
        Team::factory()->count(2)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teams');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_show_team_in_assigned_championship(): void
    {
        $championship = Championship::factory()->create();
        $team         = Team::factory()->create();
        $championship->teams()->attach($team->id);

        $this->authenticateAsAdmin([$championship->id]);

        $response = $this->getJson("/api/teams/{$team->id}");

        $response->assertOk()->assertJsonPath('data.id', $team->id);
    }

    public function test_admin_cannot_show_team_outside_assignments(): void
    {
        $team = Team::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/teams/{$team->id}");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_update_team_outside_assignments(): void
    {
        $team = Team::factory()->create(['nickname' => 'OLD']);
        $this->authenticateAsAdmin();

        $response = $this->putJson("/api/teams/{$team->id}", ['nickname' => 'NEW']);

        $response->assertForbidden();
    }

    public function test_admin_cannot_delete_team_outside_assignments(): void
    {
        $team = Team::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->deleteJson("/api/teams/{$team->id}");

        $response->assertForbidden();
    }

    // ── Integridade referencial ───────────────────────────────────────────────

    public function test_destroying_team_nullifies_player_team_id(): void
    {
        $this->authenticate();
        $team   = Team::factory()->create();
        $player = \App\Models\Player::factory()->create(['team_id' => $team->id]);

        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(204);

        $this->assertDatabaseHas('players', ['id' => $player->id, 'team_id' => null]);
    }
}
