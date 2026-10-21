<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlayerControllerTest extends TestCase
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

    public function test_guest_cannot_list_players(): void
    {
        $response = $this->getJson('/api/players');

        $response->assertStatus(401);
    }

    // ── Master ────────────────────────────────────────────────────────────────

    public function test_master_index_returns_all_players(): void
    {
        $this->authenticate();
        Player::factory()->count(2)->create();

        $response = $this->getJson('/api/players');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_master_can_create_player_with_team(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();

        $response = $this->postJson('/api/players', [
            'team_id' => $team->id,
            'name' => 'Jogador Teste',
            'position' => 'ATA',
            'number' => 9,
            'document' => '12345678901',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.team_id', $team->id)
            ->assertJsonPath('data.document', '12345678901');

        $this->assertDatabaseHas('players', ['document' => '12345678901', 'team_id' => $team->id]);
    }

    public function test_master_can_show_player(): void
    {
        $this->authenticate();
        $player = Player::factory()->create();

        $response = $this->getJson("/api/players/{$player->id}");

        $response->assertOk()->assertJsonPath('data.id', $player->id);
    }

    public function test_master_can_update_player(): void
    {
        $this->authenticate();
        $player = Player::factory()->create(['position' => 'ZAG']);

        $response = $this->putJson("/api/players/{$player->id}", ['position' => 'LAT']);

        $response->assertOk()->assertJsonPath('data.position', 'LAT');
        $this->assertDatabaseHas('players', ['id' => $player->id, 'position' => 'LAT']);
    }

    public function test_master_can_delete_player(): void
    {
        $this->authenticate();
        $player = Player::factory()->create();

        $response = $this->deleteJson("/api/players/{$player->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('players', ['id' => $player->id]);
    }

    // ── Validações ────────────────────────────────────────────────────────────

    public function test_store_fails_validation_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/players', []);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_team_id_does_not_exist(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/players', [
            'name' => 'Jogador Teste',
            'team_id' => 999,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_document_is_not_11_digits(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/players', [
            'team_id' => Team::factory()->create()->id,
            'name' => 'Jogador Teste',
            'document' => '123',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_document_already_exists(): void
    {
        $this->authenticate();
        Player::factory()->create(['document' => '11122233344']);

        $response = $this->postJson('/api/players', [
            'team_id' => Team::factory()->create()->id,
            'name' => 'Outro Jogador',
            'document' => '11122233344',
        ]);

        $response->assertStatus(422);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/players/999');

        $response->assertStatus(422)->assertJson(['message' => 'Jogador não encontrado!']);
    }

    public function test_update_allows_keeping_own_document(): void
    {
        $this->authenticate();
        $player = Player::factory()->create(['document' => '55566677788']);

        $response = $this->putJson("/api/players/{$player->id}", [
            'name' => $player->name,
            'document' => '55566677788',
        ]);

        $response->assertOk();
    }

    // ── Admin: escopo por liga ───────────────────────────────────────────────

    public function test_admin_index_only_sees_players_of_teams_in_assigned_leagues(): void
    {
        $league = League::factory()->create();
        $teamInScope = Team::factory()->create();
        $teamOutScope = Team::factory()->create();

        $league->teams()->attach($teamInScope->id);

        $playerInScope = Player::factory()->create(['team_id' => $teamInScope->id]);
        $playerOutScope = Player::factory()->create(['team_id' => $teamOutScope->id]);

        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson('/api/players');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $playerInScope->id);
    }

    public function test_admin_with_no_leagues_sees_empty_player_list(): void
    {
        Player::factory()->count(2)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/players');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_show_player_in_assigned_league(): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);
        $player = Player::factory()->create(['team_id' => $team->id]);

        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/players/{$player->id}");

        $response->assertOk()->assertJsonPath('data.id', $player->id);
    }

    public function test_admin_cannot_show_player_outside_assignments(): void
    {
        $player = Player::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/players/{$player->id}");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_delete_player_outside_assignments(): void
    {
        $player = Player::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->deleteJson("/api/players/{$player->id}");

        $response->assertForbidden();
    }

    // ── Integridade referencial ───────────────────────────────────────────────

    public function test_destroying_team_nullifies_player_team_id(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $player = Player::factory()->create(['team_id' => $team->id]);

        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(204);

        $this->assertDatabaseHas('players', ['id' => $player->id, 'team_id' => null]);
    }

    // ── Rotas públicas ────────────────────────────────────────────────────────

    public function test_guest_can_show_player_via_public_route(): void
    {
        $player = Player::factory()->create();

        $response = $this->getJson("/api/public/players/{$player->id}");

        $response->assertOk()->assertJsonPath('data.id', $player->id);
    }

    public function test_guest_show_via_public_route_returns_error_when_not_found(): void
    {
        $response = $this->getJson('/api/public/players/999');

        $response->assertStatus(422)->assertJson(['message' => 'Jogador não encontrado!']);
    }

    // ── Validação de posição contra a lista oficial ──────────────────────────

    public function test_store_fails_when_position_is_not_in_official_list(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/players', ['team_id' => Team::factory()->create()->id, 'name' => 'Jogador', 'position' => 'XXX']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['position' => 'A posição informada é inválida.']);
        $this->assertDatabaseCount('players', 0);
    }

    public function test_store_fails_when_position_is_a_title_instead_of_code(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/players', ['team_id' => Team::factory()->create()->id, 'name' => 'Jogador', 'position' => 'Atacante']);

        $response->assertStatus(422)->assertJsonValidationErrors('position');
    }

    public function test_store_accepts_null_position(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/players', ['team_id' => Team::factory()->create()->id, 'name' => 'Jogador', 'position' => null]);

        $response->assertCreated()->assertJsonPath('data.position', null);
    }

    public function test_update_fails_when_position_is_not_in_official_list(): void
    {
        $this->authenticate();
        $player = Player::factory()->create(['position' => 'ZAG']);

        $response = $this->putJson("/api/players/{$player->id}", ['position' => 'XXX']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['position' => 'A posição informada é inválida.']);
        $this->assertDatabaseHas('players', ['id' => $player->id, 'position' => 'ZAG']);
    }

    public function test_update_accepts_null_position(): void
    {
        $this->authenticate();
        $player = Player::factory()->create(['position' => 'ZAG']);

        $response = $this->putJson("/api/players/{$player->id}", ['position' => null]);

        $response->assertOk()->assertJsonPath('data.position', null);
    }

    public function test_update_only_name_keeps_position(): void
    {
        $this->authenticate();
        $player = Player::factory()->create(['position' => 'ATA']);

        $response = $this->putJson("/api/players/{$player->id}", ['name' => 'Nome Novo']);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Nome Novo')
            ->assertJsonPath('data.position', 'ATA');
    }

    // ── Lookup de posições ───────────────────────────────────────────────────

    public function test_authenticated_user_can_list_positions(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/players/positions');

        $response->assertOk()
            ->assertJsonCount(count(Player::POSITIONS), 'data')
            ->assertJsonPath('data.0.value', 'GOL');
    }

    public function test_guest_cannot_list_positions(): void
    {
        $response = $this->getJson('/api/players/positions');

        $response->assertStatus(401);
    }

    // ── Permissões por papel (FOOT-0043) ──────────────────────────────────────

    /** Time vinculado a uma liga nova, para cenários de papel com acesso à liga. */
    private function teamInLeague(): array
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);

        return [$league, $team];
    }

    public function test_coach_can_create_player_in_team_of_own_league(): void
    {
        [$league, $team] = $this->teamInLeague();
        $this->authenticateAs('coach', [$league->id]);

        $response = $this->postJson('/api/players', ['team_id' => $team->id, 'name' => 'Jogador do Coach']);

        $response->assertCreated()->assertJsonPath('data.team_id', $team->id);
        $this->assertDatabaseHas('players', ['name' => 'Jogador do Coach', 'team_id' => $team->id]);
    }

    public function test_coach_can_update_player_in_team_of_own_league(): void
    {
        [$league, $team] = $this->teamInLeague();
        $player = Player::factory()->create(['team_id' => $team->id, 'name' => 'Antigo']);
        $this->authenticateAs('coach', [$league->id]);

        $response = $this->putJson("/api/players/{$player->id}", ['name' => 'Novo']);

        $response->assertOk()->assertJsonPath('data.name', 'Novo');
    }

    public function test_coach_cannot_delete_player(): void
    {
        [$league, $team] = $this->teamInLeague();
        $player = Player::factory()->create(['team_id' => $team->id]);
        $this->authenticateAs('coach', [$league->id]);

        $response = $this->deleteJson("/api/players/{$player->id}");

        $response->assertForbidden()
            ->assertJsonPath('message', 'User does not have the right permissions.');
        $this->assertDatabaseHas('players', ['id' => $player->id]);
    }

    public function test_player_cannot_update_player(): void
    {
        [$league, $team] = $this->teamInLeague();
        $player = Player::factory()->create(['team_id' => $team->id, 'name' => 'Antigo']);
        $this->authenticateAs('player', [$league->id]);

        $response = $this->putJson("/api/players/{$player->id}", ['name' => 'Novo']);

        $response->assertForbidden()
            ->assertJsonPath('message', 'User does not have the right permissions.');
        $this->assertDatabaseHas('players', ['id' => $player->id, 'name' => 'Antigo']);
    }

    public function test_player_cannot_create_player(): void
    {
        [$league, $team] = $this->teamInLeague();
        $this->authenticateAs('player', [$league->id]);

        $response = $this->postJson('/api/players', ['team_id' => $team->id, 'name' => 'Intruso']);

        $response->assertForbidden()
            ->assertJsonPath('message', 'User does not have the right permissions.');
        $this->assertDatabaseMissing('players', ['name' => 'Intruso']);
    }

    public function test_player_cannot_delete_player(): void
    {
        [$league, $team] = $this->teamInLeague();
        $player = Player::factory()->create(['team_id' => $team->id]);
        $this->authenticateAs('player', [$league->id]);

        $response = $this->deleteJson("/api/players/{$player->id}");

        $response->assertForbidden()
            ->assertJsonPath('message', 'User does not have the right permissions.');
        $this->assertDatabaseHas('players', ['id' => $player->id]);
    }

    public function test_player_can_list_players(): void
    {
        [$league, $team] = $this->teamInLeague();
        Player::factory()->create(['team_id' => $team->id]);
        $this->authenticateAs('player', [$league->id]);

        $this->getJson('/api/players')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_create_update_and_delete_player_in_assigned_league(): void
    {
        [$league, $team] = $this->teamInLeague();
        $this->authenticateAsAdmin([$league->id]);

        $id = $this->postJson('/api/players', ['team_id' => $team->id, 'name' => 'Do Admin'])
            ->assertCreated()
            ->json('data.id');
        $this->putJson("/api/players/{$id}", ['name' => 'Editado'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Editado');
        $this->deleteJson("/api/players/{$id}")->assertStatus(204);

        $this->assertDatabaseMissing('players', ['id' => $id]);
    }

    public function test_admin_outside_league_is_blocked_by_resource_access_not_by_permission(): void
    {
        [, $team] = $this->teamInLeague();
        $player = Player::factory()->create(['team_id' => $team->id]);
        $this->authenticateAsAdmin([League::factory()->create()->id]);

        $this->putJson("/api/players/{$player->id}", ['name' => 'Novo'])
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

        $this->postJson('/api/players', array_merge(['name' => 'Jogador Novo'], ['team_id' => $outsideTeam->id]))
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);

        $this->assertDatabaseCount('players', 0);
    }

    public function test_admin_cannot_move_to_team_of_unassigned_league(): void
    {
        [$league, $team, $outsideTeam] = $this->teamsInAndOutOfLeague();
        $model = Player::factory()->create(['team_id' => $team->id]);
        $this->authenticateAsAdmin([$league->id]);

        $this->putJson("/api/players/{$model->id}", ['team_id' => $outsideTeam->id])
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);

        $this->assertDatabaseHas('players', ['id' => $model->id, 'team_id' => $team->id]);
    }

    public function test_admin_can_create_and_move_within_own_league(): void
    {
        [$league, $team] = $this->teamsInAndOutOfLeague();
        $otherTeam = Team::factory()->create();
        $league->teams()->attach($otherTeam->id);
        $this->authenticateAsAdmin([$league->id]);

        $id = $this->postJson('/api/players', array_merge(['name' => 'Jogador Novo'], ['team_id' => $team->id]))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/players/{$id}", ['team_id' => $otherTeam->id])
            ->assertOk()
            ->assertJsonPath('data.team_id', $otherTeam->id);
    }

    public function test_master_can_create_and_move_to_any_team(): void
    {
        [, $team, $outsideTeam] = $this->teamsInAndOutOfLeague();
        $this->authenticate();

        $id = $this->postJson('/api/players', array_merge(['name' => 'Jogador Novo'], ['team_id' => $team->id]))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/players/{$id}", ['team_id' => $outsideTeam->id])
            ->assertOk()
            ->assertJsonPath('data.team_id', $outsideTeam->id);
    }

    public function test_store_without_team_id_fails_even_for_master(): void
    {
        $this->authenticate();

        $this->postJson('/api/players', ['name' => 'Jogador Novo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_id');

        $this->assertDatabaseCount('players', 0);
    }

    public function test_update_with_null_team_id_fails(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $model = Player::factory()->create(['team_id' => $team->id]);

        $this->putJson("/api/players/{$model->id}", ['team_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_id');

        $this->assertDatabaseHas('players', ['id' => $model->id, 'team_id' => $team->id]);
    }
}
