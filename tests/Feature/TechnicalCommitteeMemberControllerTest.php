<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Team;
use App\Models\TechnicalCommitteeMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TechnicalCommitteeMemberControllerTest extends TestCase
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

    public function test_guest_cannot_list_technical_committee_members(): void
    {
        $response = $this->getJson('/api/technical-committee-members');

        $response->assertStatus(401);
    }

    // ── Master ────────────────────────────────────────────────────────────────

    public function test_master_index_returns_all_technical_committee_members(): void
    {
        $this->authenticate();
        TechnicalCommitteeMember::factory()->count(2)->create();

        $response = $this->getJson('/api/technical-committee-members');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_master_can_create_technical_committee_member(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();

        $response = $this->postJson('/api/technical-committee-members', [
            'team_id' => $team->id,
            'name' => 'Membro Teste',
            'role' => 'Treinador principal',
            'birth_date' => '1975-03-15',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Membro Teste')
            ->assertJsonPath('data.role', 'Treinador principal')
            ->assertJsonPath('data.team_id', $team->id);

        $this->assertDatabaseHas('technical_committee_members', [
            'name' => 'Membro Teste',
            'role' => 'Treinador principal',
            'team_id' => $team->id,
        ]);
    }

    public function test_master_can_show_technical_committee_member(): void
    {
        $this->authenticate();
        $member = TechnicalCommitteeMember::factory()->create();

        $response = $this->getJson("/api/technical-committee-members/{$member->id}");

        $response->assertOk()->assertJsonPath('data.id', $member->id);
    }

    public function test_master_can_update_technical_committee_member(): void
    {
        $this->authenticate();
        $member = TechnicalCommitteeMember::factory()->create(['name' => 'Nome Antigo']);

        $response = $this->putJson("/api/technical-committee-members/{$member->id}", ['name' => 'Nome Novo']);

        $response->assertOk()->assertJsonPath('data.name', 'Nome Novo');
        $this->assertDatabaseHas('technical_committee_members', ['id' => $member->id, 'name' => 'Nome Novo']);
    }

    public function test_master_can_delete_technical_committee_member(): void
    {
        $this->authenticate();
        $member = TechnicalCommitteeMember::factory()->create();

        $response = $this->deleteJson("/api/technical-committee-members/{$member->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('technical_committee_members', ['id' => $member->id]);
    }

    // ── Validações ────────────────────────────────────────────────────────────

    public function test_store_fails_validation_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/technical-committee-members', ['role' => 'Treinador principal']);

        $response->assertStatus(422);
    }

    public function test_store_fails_validation_without_role(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/technical-committee-members', ['name' => 'Membro Teste']);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_team_id_does_not_exist(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/technical-committee-members', [
            'name' => 'Membro Teste',
            'role' => 'Treinador principal',
            'team_id' => 999,
        ]);

        $response->assertStatus(422);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/technical-committee-members/999');

        $response->assertStatus(422)->assertJson(['message' => 'Membro da comissão técnica não encontrado!']);
    }

    // ── Múltiplos membros por time (regra nova da Fase 3) ───────────────────────

    public function test_team_can_have_multiple_committee_members_with_different_roles(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();

        $this->postJson('/api/technical-committee-members', [
            'team_id' => $team->id,
            'name' => 'Treinador Principal',
            'role' => 'Treinador principal',
        ])->assertCreated();

        $this->postJson('/api/technical-committee-members', [
            'team_id' => $team->id,
            'name' => 'Auxiliar Técnico',
            'role' => 'Auxiliar técnico',
        ])->assertCreated();

        $this->postJson('/api/technical-committee-members', [
            'team_id' => $team->id,
            'name' => 'Preparador Físico',
            'role' => 'Preparador físico',
        ])->assertCreated();

        $response = $this->getJson('/api/technical-committee-members');

        $response->assertOk()->assertJsonCount(3, 'data');
        $this->assertDatabaseCount('technical_committee_members', 3);
        $this->assertEquals(3, $team->technicalCommitteeMembers()->count());
    }

    // ── Admin: escopo por liga ───────────────────────────────────────────────

    public function test_admin_index_only_sees_members_of_teams_in_assigned_leagues(): void
    {
        $league = League::factory()->create();
        $teamInScope = Team::factory()->create();
        $teamOutOfScope = Team::factory()->create();

        $league->teams()->attach($teamInScope->id);

        $memberInScope = TechnicalCommitteeMember::factory()->create(['team_id' => $teamInScope->id]);
        $memberOutScope = TechnicalCommitteeMember::factory()->create(['team_id' => $teamOutOfScope->id]);

        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson('/api/technical-committee-members');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $memberInScope->id);
    }

    public function test_admin_with_no_leagues_sees_empty_member_list(): void
    {
        TechnicalCommitteeMember::factory()->count(2)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/technical-committee-members');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_show_member_in_assigned_league(): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);
        $member = TechnicalCommitteeMember::factory()->create(['team_id' => $team->id]);

        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/technical-committee-members/{$member->id}");

        $response->assertOk()->assertJsonPath('data.id', $member->id);
    }

    public function test_admin_cannot_show_member_outside_assignments(): void
    {
        $member = TechnicalCommitteeMember::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/technical-committee-members/{$member->id}");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_delete_member_outside_assignments(): void
    {
        $member = TechnicalCommitteeMember::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->deleteJson("/api/technical-committee-members/{$member->id}");

        $response->assertForbidden();
    }

    // ── Integridade referencial ───────────────────────────────────────────────

    public function test_destroying_team_nullifies_member_team_id(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $member = TechnicalCommitteeMember::factory()->create(['team_id' => $team->id]);

        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(204);

        $this->assertDatabaseHas('technical_committee_members', ['id' => $member->id, 'team_id' => null]);
    }

    // ── Lookup de funções ────────────────────────────────────────────────────

    public function test_authenticated_user_can_list_roles(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/technical-committee-members/roles');

        $response->assertOk()
            ->assertJsonCount(count(TechnicalCommitteeMember::ROLES), 'data')
            ->assertJsonPath('data.0.value', 'Treinador');
    }

    public function test_guest_cannot_list_roles(): void
    {
        $response = $this->getJson('/api/technical-committee-members/roles');

        $response->assertStatus(401);
    }
}
