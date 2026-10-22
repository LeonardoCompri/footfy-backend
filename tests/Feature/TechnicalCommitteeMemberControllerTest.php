<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Team;
use App\Models\TechnicalCommitteeMember;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TechnicalCommitteeMemberControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        // Papéis e permissões reais do RoleSeeder: as rotas de escrita exigem `permission:`.
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
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

    /** Autentica com um papel do RoleSeeder (ex.: coach, player) vinculado às ligas informadas. */
    private function authenticateAs(string $role, array $leagueIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
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
            'role' => 'Treinador',
            'birth_date' => '1975-03-15',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Membro Teste')
            ->assertJsonPath('data.role', 'Treinador')
            ->assertJsonPath('data.team_id', $team->id);

        $this->assertDatabaseHas('technical_committee_members', [
            'name' => 'Membro Teste',
            'role' => 'Treinador',
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

        $response = $this->postJson('/api/technical-committee-members', ['team_id' => Team::factory()->create()->id, 'role' => 'Treinador']);

        $response->assertStatus(422);
    }

    public function test_store_fails_validation_without_role(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/technical-committee-members', ['team_id' => Team::factory()->create()->id, 'name' => 'Membro Teste']);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_team_id_does_not_exist(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/technical-committee-members', [
            'name' => 'Membro Teste',
            'role' => 'Treinador',
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
            'role' => 'Treinador',
        ])->assertCreated();

        $this->postJson('/api/technical-committee-members', [
            'team_id' => $team->id,
            'name' => 'Auxiliar Técnico',
            'role' => 'Auxiliar Técnico',
        ])->assertCreated();

        $this->postJson('/api/technical-committee-members', [
            'team_id' => $team->id,
            'name' => 'Preparador Físico',
            'role' => 'Preparador Físico',
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

    // ── Validação de função contra a lista oficial ───────────────────────────

    public function test_store_fails_when_role_is_not_in_official_list(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/technical-committee-members', [
            'team_id' => Team::factory()->create()->id,
            'name' => 'Membro Teste',
            'role' => 'Astronauta',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role' => 'A função informada é inválida.']);
        $this->assertDatabaseCount('technical_committee_members', 0);
    }

    public function test_store_accepts_role_from_official_list(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/technical-committee-members', [
            'team_id' => Team::factory()->create()->id,
            'name' => 'Membro Teste',
            'role' => 'Preparador Físico',
        ]);

        $response->assertCreated()->assertJsonPath('data.role', 'Preparador Físico');
    }

    public function test_update_fails_when_role_is_not_in_official_list(): void
    {
        $this->authenticate();
        $member = TechnicalCommitteeMember::factory()->create(['role' => 'Treinador']);

        $response = $this->putJson("/api/technical-committee-members/{$member->id}", ['role' => 'Astronauta']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role' => 'A função informada é inválida.']);
        $this->assertDatabaseHas('technical_committee_members', ['id' => $member->id, 'role' => 'Treinador']);
    }

    public function test_update_accepts_role_from_official_list(): void
    {
        $this->authenticate();
        $member = TechnicalCommitteeMember::factory()->create(['role' => 'Treinador']);

        $response = $this->putJson("/api/technical-committee-members/{$member->id}", ['role' => 'Médico']);

        $response->assertOk()->assertJsonPath('data.role', 'Médico');
    }

    public function test_update_of_legacy_member_keeping_legacy_role_is_accepted(): void
    {
        $this->authenticate();
        $member = TechnicalCommitteeMember::factory()->create(['role' => 'Massagista', 'name' => 'Nome Antigo']);

        $response = $this->putJson("/api/technical-committee-members/{$member->id}", [
            'name' => 'Nome Novo',
            'role' => 'Massagista',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Nome Novo')
            ->assertJsonPath('data.role', 'Massagista');
    }

    public function test_update_of_legacy_member_without_role_is_accepted(): void
    {
        $this->authenticate();
        $member = TechnicalCommitteeMember::factory()->create(['role' => 'Massagista']);

        $response = $this->putJson("/api/technical-committee-members/{$member->id}", ['name' => 'Nome Novo']);

        $response->assertOk()->assertJsonPath('data.role', 'Massagista');
    }

    public function test_update_of_legacy_member_to_another_invalid_role_fails(): void
    {
        $this->authenticate();
        $member = TechnicalCommitteeMember::factory()->create(['role' => 'Massagista']);

        $response = $this->putJson("/api/technical-committee-members/{$member->id}", ['role' => 'Astronauta']);

        $response->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertDatabaseHas('technical_committee_members', ['id' => $member->id, 'role' => 'Massagista']);
    }

    public function test_legacy_role_of_one_member_is_not_accepted_for_another_member(): void
    {
        $this->authenticate();
        TechnicalCommitteeMember::factory()->create(['role' => 'Massagista']);
        $member = TechnicalCommitteeMember::factory()->create(['role' => 'Treinador']);

        $response = $this->putJson("/api/technical-committee-members/{$member->id}", ['role' => 'Massagista']);

        $response->assertStatus(422)->assertJsonValidationErrors('role');
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

    // ── Permissões por papel (FOOT-0043) ──────────────────────────────────────

    public static function rolesWithoutCommitteePermissionsProvider(): array
    {
        return ['coach' => ['coach'], 'player' => ['player']];
    }

    #[DataProvider('rolesWithoutCommitteePermissionsProvider')]
    public function test_role_without_committee_permissions_cannot_write_members(string $role): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);
        $member = TechnicalCommitteeMember::factory()->create(['team_id' => $team->id, 'name' => 'Original']);
        $this->authenticateAs($role, [$league->id]);

        $this->postJson('/api/technical-committee-members', [
            'team_id' => $team->id,
            'name' => 'Intruso',
            'role' => 'Treinador',
        ])->assertForbidden()->assertJsonPath('message', 'User does not have the right permissions.');
        $this->putJson("/api/technical-committee-members/{$member->id}", ['name' => 'Novo'])
            ->assertForbidden()
            ->assertJsonPath('message', 'User does not have the right permissions.');
        $this->deleteJson("/api/technical-committee-members/{$member->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'User does not have the right permissions.');

        $this->assertDatabaseMissing('technical_committee_members', ['name' => 'Intruso']);
        $this->assertDatabaseHas('technical_committee_members', ['id' => $member->id, 'name' => 'Original']);
    }

    public function test_admin_can_create_update_and_delete_member_in_assigned_league(): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);
        $this->authenticateAsAdmin([$league->id]);

        $id = $this->postJson('/api/technical-committee-members', [
            'team_id' => $team->id,
            'name' => 'Do Admin',
            'role' => 'Treinador',
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/technical-committee-members/{$id}", ['name' => 'Editado'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Editado');
        $this->deleteJson("/api/technical-committee-members/{$id}")->assertStatus(204);

        $this->assertDatabaseMissing('technical_committee_members', ['id' => $id]);
    }

    public function test_admin_outside_league_is_blocked_by_resource_access_not_by_permission(): void
    {
        $member = TechnicalCommitteeMember::factory()->create();
        $this->authenticateAsAdmin([League::factory()->create()->id]);

        $this->putJson("/api/technical-committee-members/{$member->id}", ['name' => 'Novo'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    // ── Time de destino na escrita (FOOT-0044) ────────────────────────────────

    /** @return array{0: League, 1: Team, 2: Team} liga do admin, time dela e time só de outra liga */
    private function teamsInAndOutOfLeague(): array
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);
        $outsideTeam = Team::factory()->create();
        League::factory()->create()->teams()->attach($outsideTeam->id);

        return [$league, $team, $outsideTeam];
    }

    public function test_admin_cannot_create_in_team_of_unassigned_league(): void
    {
        [$league, , $outsideTeam] = $this->teamsInAndOutOfLeague();
        $this->authenticateAsAdmin([$league->id]);

        $this->postJson('/api/technical-committee-members', array_merge(['name' => 'Membro Novo', 'role' => 'Treinador'], ['team_id' => $outsideTeam->id]))
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);

        $this->assertDatabaseCount('technical_committee_members', 0);
    }

    public function test_admin_cannot_move_to_team_of_unassigned_league(): void
    {
        [$league, $team, $outsideTeam] = $this->teamsInAndOutOfLeague();
        $model = TechnicalCommitteeMember::factory()->create(['team_id' => $team->id]);
        $this->authenticateAsAdmin([$league->id]);

        $this->putJson("/api/technical-committee-members/{$model->id}", ['team_id' => $outsideTeam->id])
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);

        $this->assertDatabaseHas('technical_committee_members', ['id' => $model->id, 'team_id' => $team->id]);
    }

    public function test_admin_can_create_and_move_within_own_league(): void
    {
        [$league, $team] = $this->teamsInAndOutOfLeague();
        $otherTeam = Team::factory()->create();
        $league->teams()->attach($otherTeam->id);
        $this->authenticateAsAdmin([$league->id]);

        $id = $this->postJson('/api/technical-committee-members', array_merge(['name' => 'Membro Novo', 'role' => 'Treinador'], ['team_id' => $team->id]))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/technical-committee-members/{$id}", ['team_id' => $otherTeam->id])
            ->assertOk()
            ->assertJsonPath('data.team_id', $otherTeam->id);
    }

    public function test_master_can_create_and_move_to_any_team(): void
    {
        [, $team, $outsideTeam] = $this->teamsInAndOutOfLeague();
        $this->authenticate();

        $id = $this->postJson('/api/technical-committee-members', array_merge(['name' => 'Membro Novo', 'role' => 'Treinador'], ['team_id' => $team->id]))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/technical-committee-members/{$id}", ['team_id' => $outsideTeam->id])
            ->assertOk()
            ->assertJsonPath('data.team_id', $outsideTeam->id);
    }

    public function test_store_without_team_id_fails_even_for_master(): void
    {
        $this->authenticate();

        $this->postJson('/api/technical-committee-members', ['name' => 'Membro Novo', 'role' => 'Treinador'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_id');

        $this->assertDatabaseCount('technical_committee_members', 0);
    }

    public function test_update_with_null_team_id_fails(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $model = TechnicalCommitteeMember::factory()->create(['team_id' => $team->id]);

        $this->putJson("/api/technical-committee-members/{$model->id}", ['team_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_id');

        $this->assertDatabaseHas('technical_committee_members', ['id' => $model->id, 'team_id' => $team->id]);
    }
}
