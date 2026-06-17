<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\Coach;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CoachControllerTest extends TestCase
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

    public function test_guest_cannot_list_coaches(): void
    {
        $response = $this->getJson('/api/coaches');

        $response->assertStatus(401);
    }

    // ── Master ────────────────────────────────────────────────────────────────

    public function test_master_index_returns_all_coaches(): void
    {
        $this->authenticate();
        Coach::factory()->count(2)->create();

        $response = $this->getJson('/api/coaches');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_master_can_create_coach(): void
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

    public function test_master_can_show_coach(): void
    {
        $this->authenticate();
        $coach = Coach::factory()->create();

        $response = $this->getJson("/api/coaches/{$coach->id}");

        $response->assertOk()->assertJsonPath('data.id', $coach->id);
    }

    public function test_master_can_update_coach(): void
    {
        $this->authenticate();
        $coach = Coach::factory()->create(['name' => 'Nome Antigo']);

        $response = $this->putJson("/api/coaches/{$coach->id}", ['name' => 'Nome Novo']);

        $response->assertOk()->assertJsonPath('data.name', 'Nome Novo');
        $this->assertDatabaseHas('coaches', ['id' => $coach->id, 'name' => 'Nome Novo']);
    }

    public function test_master_can_delete_coach(): void
    {
        $this->authenticate();
        $coach = Coach::factory()->create();

        $response = $this->deleteJson("/api/coaches/{$coach->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('coaches', ['id' => $coach->id]);
    }

    // ── Validações ────────────────────────────────────────────────────────────

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

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/coaches/999');

        $response->assertStatus(422)->assertJson(['message' => 'Técnico não encontrado!']);
    }

    // ── Admin: escopo por campeonato ──────────────────────────────────────────

    public function test_admin_index_only_sees_coaches_of_teams_in_assigned_championships(): void
    {
        $championship   = Championship::factory()->create();
        $teamInScope    = Team::factory()->create();
        $teamOutOfScope = Team::factory()->create();

        $championship->teams()->attach($teamInScope->id);

        $coachInScope  = Coach::factory()->create(['team_id' => $teamInScope->id]);
        $coachOutScope = Coach::factory()->create(['team_id' => $teamOutOfScope->id]);

        $this->authenticateAsAdmin([$championship->id]);

        $response = $this->getJson('/api/coaches');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $coachInScope->id);
    }

    public function test_admin_with_no_championships_sees_empty_coach_list(): void
    {
        Coach::factory()->count(2)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/coaches');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_show_coach_in_assigned_championship(): void
    {
        $championship = Championship::factory()->create();
        $team         = Team::factory()->create();
        $championship->teams()->attach($team->id);
        $coach = Coach::factory()->create(['team_id' => $team->id]);

        $this->authenticateAsAdmin([$championship->id]);

        $response = $this->getJson("/api/coaches/{$coach->id}");

        $response->assertOk()->assertJsonPath('data.id', $coach->id);
    }

    public function test_admin_cannot_show_coach_outside_assignments(): void
    {
        $coach = Coach::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/coaches/{$coach->id}");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_delete_coach_outside_assignments(): void
    {
        $coach = Coach::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->deleteJson("/api/coaches/{$coach->id}");

        $response->assertForbidden();
    }

    // ── Integridade referencial ───────────────────────────────────────────────

    public function test_destroying_team_nullifies_coach_team_id(): void
    {
        $this->authenticate();
        $team  = Team::factory()->create();
        $coach = Coach::factory()->create(['team_id' => $team->id]);

        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(204);

        $this->assertDatabaseHas('coaches', ['id' => $coach->id, 'team_id' => null]);
    }
}
