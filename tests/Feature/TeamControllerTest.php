<?php

namespace Tests\Feature;

use App\Models\League;
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
            'name' => 'Sport Club X',
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

    // ── Admin: escopo por liga ───────────────────────────────────────────────

    public function test_admin_index_only_sees_teams_in_assigned_leagues(): void
    {
        $league = League::factory()->create();
        $teamInScope = Team::factory()->create();
        $teamOutOfScope = Team::factory()->create();

        $league->teams()->attach($teamInScope->id);

        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson('/api/teams');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $teamInScope->id);
    }

    public function test_admin_with_no_leagues_sees_empty_team_list(): void
    {
        Team::factory()->count(2)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teams');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    // ── Filtro por league_id ─────────────────────────────────────────────────

    public function test_master_can_filter_index_by_league_id(): void
    {
        $this->authenticate();
        $leagueA = League::factory()->create();
        $leagueB = League::factory()->create();
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $leagueA->teams()->attach($teamA->id);
        $leagueB->teams()->attach($teamB->id);

        $response = $this->getJson("/api/teams?league_id={$leagueA->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $teamA->id);
    }

    public function test_admin_can_filter_index_by_assigned_league_id(): void
    {
        $leagueA = League::factory()->create();
        $leagueB = League::factory()->create();
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $leagueA->teams()->attach($teamA->id);
        $leagueB->teams()->attach($teamB->id);

        $this->authenticateAsAdmin([$leagueA->id, $leagueB->id]);

        $response = $this->getJson("/api/teams?league_id={$leagueA->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $teamA->id);
    }

    public function test_admin_cannot_filter_index_by_unassigned_league_id(): void
    {
        $assignedLeague = League::factory()->create();
        $otherLeague = League::factory()->create();
        $this->authenticateAsAdmin([$assignedLeague->id]);

        $response = $this->getJson("/api/teams?league_id={$otherLeague->id}");

        $response->assertStatus(422)->assertJson(['message' => 'Você não tem acesso a essa liga.']);
    }

    public function test_admin_can_show_team_in_assigned_league(): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);

        $this->authenticateAsAdmin([$league->id]);

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
        $team = Team::factory()->create();
        $player = \App\Models\Player::factory()->create(['team_id' => $team->id]);

        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(204);

        $this->assertDatabaseHas('players', ['id' => $player->id, 'team_id' => null]);
    }

    public function test_cannot_destroy_team_with_games(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        \App\Models\Game::factory()->create(['home_team_id' => $team->id]);

        $response = $this->deleteJson("/api/teams/{$team->id}");

        $response->assertStatus(422)
            ->assertJson(['message' => 'Não é possível remover o time pois existem partidas vinculadas a ele.']);
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    // ── TeamResource: elenco e comissão técnica ─────────────────────────────────

    public function test_show_returns_players_and_technical_committee_members(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $player = \App\Models\Player::factory()->create(['team_id' => $team->id]);
        $member = \App\Models\TechnicalCommitteeMember::factory()->create(['team_id' => $team->id]);

        $response = $this->getJson("/api/teams/{$team->id}");

        $response->assertOk()
            ->assertJsonPath('data.players.0.id', $player->id)
            ->assertJsonPath('data.technicalCommitteeMembers.0.id', $member->id);
    }

    // ── Rotas públicas ────────────────────────────────────────────────────────

    public function test_guest_can_show_team_via_public_route_with_players(): void
    {
        $team = Team::factory()->create();
        $player = \App\Models\Player::factory()->create(['team_id' => $team->id]);
        $member = \App\Models\TechnicalCommitteeMember::factory()->create(['team_id' => $team->id]);

        $response = $this->getJson("/api/public/teams/{$team->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $team->id)
            ->assertJsonPath('data.players.0.id', $player->id)
            ->assertJsonPath('data.technicalCommitteeMembers.0.id', $member->id);
    }

    public function test_guest_show_via_public_route_returns_error_when_not_found(): void
    {
        $response = $this->getJson('/api/public/teams/999');

        $response->assertStatus(422)->assertJson(['message' => 'Time não encontrado!']);
    }

    // ── Vínculo N:N time-liga ─────────────────────────────────────────────────

    public function test_master_can_sync_leagues_to_team(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $league1 = League::factory()->create();
        $league2 = League::factory()->create();

        $response = $this->postJson("/api/teams/{$team->id}/leagues", [
            'league_ids' => [$league1->id, $league2->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('league_team', ['team_id' => $team->id, 'league_id' => $league1->id]);
        $this->assertDatabaseHas('league_team', ['team_id' => $team->id, 'league_id' => $league2->id]);
    }

    public function test_master_can_remove_league_from_team(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $league = League::factory()->create();
        $league->teams()->attach($team->id);

        $response = $this->deleteJson("/api/teams/{$team->id}/leagues/{$league->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('league_team', ['team_id' => $team->id, 'league_id' => $league->id]);
    }

    public function test_sync_leagues_fails_with_invalid_league_id(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();

        $response = $this->postJson("/api/teams/{$team->id}/leagues", [
            'league_ids' => [9999],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('league_ids.0');
    }

    public function test_sync_leagues_fails_without_league_ids(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();

        $response = $this->postJson("/api/teams/{$team->id}/leagues", []);

        $response->assertStatus(422)->assertJsonValidationErrors('league_ids');
    }

    public function test_sync_leagues_fails_when_team_does_not_exist(): void
    {
        $this->authenticate();
        $league = League::factory()->create();

        $response = $this->postJson('/api/teams/999/leagues', [
            'league_ids' => [$league->id],
        ]);

        $response->assertStatus(422)->assertJson(['message' => 'Time não encontrado!']);
    }

    public function test_admin_can_sync_team_to_league_they_have_access_to(): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->postJson("/api/teams/{$team->id}/leagues", [
            'league_ids' => [$league->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('league_team', ['team_id' => $team->id, 'league_id' => $league->id]);
    }

    public function test_admin_cannot_sync_team_to_league_they_do_not_have_access_to(): void
    {
        $otherLeague = League::factory()->create();
        $team = Team::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->postJson("/api/teams/{$team->id}/leagues", [
            'league_ids' => [$otherLeague->id],
        ]);

        $response->assertStatus(422)->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);
        $this->assertDatabaseMissing('league_team', ['team_id' => $team->id, 'league_id' => $otherLeague->id]);
    }

    public function test_admin_cannot_remove_league_from_team_they_do_not_have_access_to(): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);
        $this->authenticateAsAdmin();

        $response = $this->deleteJson("/api/teams/{$team->id}/leagues/{$league->id}");

        $response->assertStatus(422)->assertJson(['message' => 'Você não tem acesso a essa liga.']);
        $this->assertDatabaseHas('league_team', ['team_id' => $team->id, 'league_id' => $league->id]);
    }
}
