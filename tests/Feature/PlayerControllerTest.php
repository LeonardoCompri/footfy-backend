<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class PlayerControllerTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
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

    private function authenticateAs(string $role, array $leagueIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        if (! empty($leagueIds)) {
            $this->linkUserToLeagues($user, $leagueIds);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_list_players(): void
    {
        $response = $this->getJson('/api/players');

        $response->assertStatus(401);
    }

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
        $this->assertSoftDeleted('players', ['id' => $player->id]);
    }

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

    public function test_destroying_team_nullifies_player_team_id(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $player = Player::factory()->create(['team_id' => $team->id]);

        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(204);

        $this->assertDatabaseHas('players', ['id' => $player->id, 'team_id' => null]);
    }

    public function test_guest_can_show_player_via_public_route(): void
    {
        $player = Player::factory()->create();

        $response = $this->getJson("/api/public/players/{$player->id}");

        $response->assertOk()->assertJsonPath('data.id', $player->id);
    }

    public function test_public_show_hides_player_document_but_keeps_birth_date(): void
    {
        $player = Player::factory()->create(['birth_date' => '2000-05-10']);

        $this->getJson("/api/public/players/{$player->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.document')
            ->assertJsonPath('data.name', $player->name)
            ->assertJsonPath('data.birth_date', '2000-05-10');
    }

    public function test_authenticated_show_still_returns_player_document(): void
    {
        $this->authenticate();
        $player = Player::factory()->create();

        $this->getJson("/api/players/{$player->id}")
            ->assertOk()
            ->assertJsonPath('data.document', $player->document);
    }

    public function test_guest_show_via_public_route_returns_error_when_not_found(): void
    {
        $response = $this->getJson('/api/public/players/999');

        $response->assertNotFound()->assertJson(['message' => 'Jogador não encontrado!']);
    }

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

    private function teamInLeague(): array
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);

        return [$league, $team];
    }

    private function authenticateAsCoachOf(?Team $team): User
    {
        $user = User::factory()->create(['team_id' => $team?->id]);
        $user->assignRole('coach');
        Sanctum::actingAs($user);

        return $user;
    }

    private function coachScenario(): array
    {
        [$league, $team] = $this->teamInLeague();
        $otherTeam = Team::factory()->create();
        $league->teams()->attach($otherTeam->id);

        return [$league, $team, $otherTeam];
    }

    public function test_coach_can_create_player_in_own_team(): void
    {
        [, $team] = $this->coachScenario();
        $this->authenticateAsCoachOf($team);

        $response = $this->postJson('/api/players', ['team_id' => $team->id, 'name' => 'Jogador do Coach']);

        $response->assertCreated()->assertJsonPath('data.team_id', $team->id);
        $this->assertDatabaseHas('players', ['name' => 'Jogador do Coach', 'team_id' => $team->id]);
    }

    public function test_coach_cannot_create_player_in_other_team_of_same_league(): void
    {
        [, $team, $otherTeam] = $this->coachScenario();
        $this->authenticateAsCoachOf($team);

        $this->postJson('/api/players', ['team_id' => $otherTeam->id, 'name' => 'Intruso'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Você só pode gerenciar jogadores do seu time.']);
        $this->assertDatabaseMissing('players', ['name' => 'Intruso']);
    }

    public function test_coach_without_team_cannot_create_player(): void
    {
        [, $team] = $this->coachScenario();
        $this->authenticateAsCoachOf(null);

        $this->postJson('/api/players', ['team_id' => $team->id, 'name' => 'Intruso'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Você só pode gerenciar jogadores do seu time.']);
        $this->assertDatabaseMissing('players', ['name' => 'Intruso']);
    }

    public function test_coach_can_update_player_of_own_team(): void
    {
        [, $team] = $this->coachScenario();
        $player = Player::factory()->create(['team_id' => $team->id, 'name' => 'Antigo']);
        $this->authenticateAsCoachOf($team);

        $this->putJson("/api/players/{$player->id}", ['name' => 'Novo', 'team_id' => $team->id])
            ->assertOk()
            ->assertJsonPath('data.name', 'Novo');
    }

    public function test_coach_can_delete_player_of_own_team(): void
    {
        [, $team] = $this->coachScenario();
        $player = Player::factory()->create(['team_id' => $team->id]);
        $this->authenticateAsCoachOf($team);

        $this->deleteJson("/api/players/{$player->id}")->assertStatus(204);

        $this->assertSoftDeleted('players', ['id' => $player->id]);
    }

    public static function playerWriteRequestsProvider(): array
    {
        return [
            'update' => ['PUT', ['name' => 'Hack']],
            'destroy' => ['DELETE', []],
        ];
    }

    #[DataProvider('playerWriteRequestsProvider')]
    public function test_coach_cannot_write_player_of_other_team_of_same_league(string $method, array $payload): void
    {
        [, $team, $otherTeam] = $this->coachScenario();
        $player = Player::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Antigo']);
        $this->authenticateAsCoachOf($team);

        $this->json($method, "/api/players/{$player->id}", $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem acesso a este recurso.');
        $this->assertDatabaseHas('players', ['id' => $player->id, 'name' => 'Antigo', 'deleted_at' => null]);
    }

    #[DataProvider('playerWriteRequestsProvider')]
    public function test_coach_without_team_cannot_write_players(string $method, array $payload): void
    {
        [, $team] = $this->coachScenario();
        $player = Player::factory()->create(['team_id' => $team->id, 'name' => 'Antigo']);
        $this->authenticateAsCoachOf(null);

        $this->json($method, "/api/players/{$player->id}", $payload)->assertForbidden();
        $this->assertDatabaseHas('players', ['id' => $player->id, 'name' => 'Antigo', 'deleted_at' => null]);
    }

    public function test_coach_cannot_transfer_player_to_other_team(): void
    {
        [, $team, $otherTeam] = $this->coachScenario();
        $player = Player::factory()->create(['team_id' => $team->id]);
        $this->authenticateAsCoachOf($team);

        $this->putJson("/api/players/{$player->id}", ['team_id' => $otherTeam->id])
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não pode transferir o jogador para outro time.']);
        $this->putJson("/api/players/{$player->id}", ['team_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_id');

        $this->assertDatabaseHas('players', ['id' => $player->id, 'team_id' => $team->id]);
    }

    public function test_coach_index_lists_players_of_leagues_of_own_team(): void
    {
        [, $team, $otherTeam] = $this->coachScenario();
        Player::factory()->create(['team_id' => $team->id]);
        Player::factory()->create(['team_id' => $otherTeam->id]);
        Player::factory()->create(['team_id' => $this->teamInLeague()[1]->id]);
        $this->authenticateAsCoachOf($team);

        $this->getJson('/api/players')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_coach_with_only_legacy_user_leagues_sees_nothing(): void
    {
        [$league, $team] = $this->coachScenario();
        Player::factory()->create(['team_id' => $team->id]);
        $coach = $this->authenticateAsCoachOf(null);
        $coach->leagues()->attach($league->id);

        $this->getJson('/api/players')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/leagues/{$league->id}")->assertForbidden();
    }

    public function test_player_cannot_update_player(): void
    {
        [$league, $team] = $this->teamInLeague();
        $player = Player::factory()->create(['team_id' => $team->id, 'name' => 'Antigo']);
        $this->authenticateAs('player', [$league->id]);

        $response = $this->putJson("/api/players/{$player->id}", ['name' => 'Novo']);

        $response->assertForbidden()
            ->assertJsonPath('message', 'Você não tem acesso a este recurso.');
        $this->assertDatabaseHas('players', ['id' => $player->id, 'name' => 'Antigo']);
    }

    public function test_player_cannot_create_player(): void
    {
        [$league, $team] = $this->teamInLeague();
        $this->authenticateAs('player', [$league->id]);

        $response = $this->postJson('/api/players', ['team_id' => $team->id, 'name' => 'Intruso']);

        $response->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->assertDatabaseMissing('players', ['name' => 'Intruso']);
    }

    public function test_player_cannot_delete_player(): void
    {
        [$league, $team] = $this->teamInLeague();
        $player = Player::factory()->create(['team_id' => $team->id]);
        $this->authenticateAs('player', [$league->id]);

        $response = $this->deleteJson("/api/players/{$player->id}");

        $response->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->assertDatabaseHas('players', ['id' => $player->id]);
    }

    public function test_player_can_list_players(): void
    {
        [$league, $team] = $this->teamInLeague();
        Player::factory()->create(['team_id' => $team->id]);
        $this->authenticateAs('player', [$league->id]);

        $this->getJson('/api/players')->assertOk()->assertJsonCount(2, 'data');
    }

    #[DataProvider('playerWriteRequestsProvider')]
    public function test_player_with_write_permission_is_still_blocked_by_player_write(string $method, array $payload): void
    {
        Role::findByName('player')->givePermissionTo(['player:update', 'player:delete']);
        [$league] = $this->teamInLeague();
        $user = $this->authenticateAs('player', [$league->id]);
        $player = $user->player;

        $this->json($method, "/api/players/{$player->id}", $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem acesso a este recurso.');
        $this->assertDatabaseHas('players', ['id' => $player->id, 'deleted_at' => null]);
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

        $this->assertSoftDeleted('players', ['id' => $id]);
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

    private function goalOf(League $league, Team $team, Player $player, ?Player $assist = null): GameEvent
    {
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $team->id,
            'away_team_id' => Team::factory()->create()->id,
        ]);

        return GameEvent::create([
            'game_id' => $game->id,
            'team_id' => $team->id,
            'player_id' => $player->id,
            'related_player_id' => $assist?->id,
            'league_id' => $league->id,
            'type' => 'goal',
            'minute' => 10,
        ]);
    }

    public function test_destroy_keeps_game_events_of_removed_player(): void
    {
        [$league, $team] = $this->teamInLeague();
        $scorer = Player::factory()->create(['team_id' => $team->id]);
        $assist = Player::factory()->create(['team_id' => $team->id]);
        $event = $this->goalOf($league, $team, $scorer, $assist);
        $this->authenticate();

        $this->deleteJson("/api/players/{$scorer->id}")->assertNoContent();
        $this->deleteJson("/api/players/{$assist->id}")->assertNoContent();

        $this->assertSoftDeleted('players', ['id' => $scorer->id]);
        $this->assertSoftDeleted('players', ['id' => $assist->id]);
        $this->assertDatabaseHas('game_events', [
            'id' => $event->id,
            'player_id' => $scorer->id,
            'related_player_id' => $assist->id,
        ]);
    }

    public function test_removed_player_is_hidden_from_index_for_master_and_admin(): void
    {
        [$league, $team] = $this->teamInLeague();
        $active = Player::factory()->create(['team_id' => $team->id]);
        Player::factory()->create(['team_id' => $team->id])->delete();

        $this->authenticate();
        $this->getJson('/api/players')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);

        $this->authenticateAsAdmin([$league->id]);
        $this->getJson('/api/players')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
    }

    public function test_removed_player_is_hidden_from_team_squad_in_admin_and_public_routes(): void
    {
        [, $team] = $this->teamInLeague();
        $active = Player::factory()->create(['team_id' => $team->id]);
        Player::factory()->create(['team_id' => $team->id])->delete();

        $this->getJson("/api/public/teams/{$team->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.players')
            ->assertJsonPath('data.players.0.id', $active->id);

        $this->authenticate();
        $this->getJson("/api/teams/{$team->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.players')
            ->assertJsonPath('data.players.0.id', $active->id);
    }

    public function test_public_show_of_removed_player_returns_404(): void
    {
        $player = Player::factory()->create();
        $player->delete();

        $this->getJson("/api/public/players/{$player->id}")
            ->assertNotFound()
            ->assertJson(['message' => 'Jogador não encontrado!']);
    }

    public function test_admin_show_of_removed_player_is_not_found_for_master_and_forbidden_for_admin(): void
    {
        [$league, $team] = $this->teamInLeague();
        $player = Player::factory()->create(['team_id' => $team->id]);
        $player->delete();

        $this->authenticate();
        $this->getJson("/api/players/{$player->id}")
            ->assertStatus(422)
            ->assertJson(['message' => 'Jogador não encontrado!']);

        $this->authenticateAsAdmin([$league->id]);
        $this->getJson("/api/players/{$player->id}")->assertForbidden();
    }

    public function test_store_with_document_of_removed_player_restores_same_record(): void
    {
        [, $oldTeam] = $this->teamInLeague();
        [, $newTeam] = $this->teamInLeague();
        $removed = Player::factory()->create([
            'team_id' => $oldTeam->id,
            'name' => 'Nome Antigo',
            'position' => 'GOL',
            'number' => 1,
            'birth_date' => '1990-01-01',
            'document' => '12312312312',
        ]);
        $removed->delete();
        $this->authenticate();

        $this->postJson('/api/players', [
            'team_id' => $newTeam->id,
            'name' => 'Nome Novo',
            'position' => 'ATA',
            'document' => '12312312312',
        ])
            ->assertCreated()
            ->assertJsonPath('data.id', $removed->id)
            ->assertJsonPath('data.team_id', $newTeam->id)
            ->assertJsonPath('data.name', 'Nome Novo')
            ->assertJsonPath('data.position', 'ATA')
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.birth_date', null);

        $this->assertDatabaseCount('players', 1);
        $this->assertDatabaseHas('players', [
            'id' => $removed->id,
            'team_id' => $newTeam->id,
            'name' => 'Nome Novo',
            'position' => 'ATA',
            'number' => null,
            'birth_date' => null,
            'deleted_at' => null,
        ]);
    }

    public function test_admin_cannot_restore_removed_player_into_team_outside_own_leagues(): void
    {
        [$league, , $outsideTeam] = $this->teamsInAndOutOfLeague();
        $removed = Player::factory()->create(['team_id' => $outsideTeam->id, 'document' => '12312312312']);
        $removed->delete();
        $this->authenticateAsAdmin([$league->id]);

        $this->postJson('/api/players', ['team_id' => $outsideTeam->id, 'name' => 'Volta', 'document' => '12312312312'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);

        $this->assertSoftDeleted('players', ['id' => $removed->id]);
    }

    public function test_admin_restores_removed_player_from_other_league_into_own_team(): void
    {
        [$league, $team, $outsideTeam] = $this->teamsInAndOutOfLeague();
        $removed = Player::factory()->create(['team_id' => $outsideTeam->id, 'document' => '12312312312']);
        $removed->delete();
        $this->authenticateAsAdmin([$league->id]);

        $this->postJson('/api/players', ['team_id' => $team->id, 'name' => 'Volta', 'document' => '12312312312'])
            ->assertCreated()
            ->assertJsonPath('data.id', $removed->id)
            ->assertJsonPath('data.team_id', $team->id);

        $this->assertDatabaseHas('players', ['id' => $removed->id, 'team_id' => $team->id, 'deleted_at' => null]);
    }

    public function test_store_without_document_creates_new_player_even_with_removed_one(): void
    {
        [, $team] = $this->teamInLeague();
        $removed = Player::factory()->create(['team_id' => $team->id]);
        $removed->delete();
        $this->authenticate();

        $id = $this->postJson('/api/players', ['team_id' => $team->id, 'name' => 'Sem CPF'])
            ->assertCreated()
            ->json('data.id');

        $this->assertNotSame($removed->id, $id);
        $this->assertDatabaseCount('players', 2);
        $this->assertSoftDeleted('players', ['id' => $removed->id]);
    }

    public function test_update_with_document_of_removed_player_fails(): void
    {
        $removed = Player::factory()->create(['document' => '12312312312']);
        $removed->delete();
        $player = Player::factory()->create(['document' => '45645645645']);
        $this->authenticate();

        $this->putJson("/api/players/{$player->id}", ['document' => '12312312312'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document' => 'Este CPF já pertence a outro jogador (ativo ou removido).']);
    }

    public function test_force_delete_of_player_with_events_is_rejected_by_foreign_key(): void
    {
        [$league, $team] = $this->teamInLeague();
        $player = Player::factory()->create(['team_id' => $team->id]);
        $event = $this->goalOf($league, $team, $player);

        try {
            $player->forceDelete();
            $this->fail('forceDelete deveria ser barrado pela FK restrict de game_events.player_id.');
        } catch (QueryException) {
        }

        $this->assertDatabaseHas('game_events', ['id' => $event->id, 'player_id' => $player->id]);
        $this->assertDatabaseHas('players', ['id' => $player->id]);
    }

    private function teamWithRoster(int $rosterCount, int $limit = 35, ?string $endDate = null, string $teamName = 'Alfa FC', string $leagueName = 'Série A'): Team
    {
        $team = Team::factory()->create(['name' => $teamName]);
        $team->leagues()->attach(League::factory()->create([
            'name' => $leagueName,
            'end_date' => $endDate,
            'max_players_per_team' => $limit,
        ])->id);
        Player::factory()->count($rosterCount)->create(['team_id' => $team->id]);

        return $team;
    }

    private function newPlayerPayload(Team $team, array $overrides = []): array
    {
        return ['team_id' => $team->id, 'name' => 'Atleta Novo', 'position' => 'ATA', ...$overrides];
    }

    public function test_store_is_blocked_when_team_reached_the_limit(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(30, 30);

        $this->postJson('/api/players', $this->newPlayerPayload($team))
            ->assertStatus(422)
            ->assertJsonPath('message', 'O time Alfa FC já tem 30 atletas e o limite da liga Série A é de 30 por time.');

        $this->assertDatabaseMissing('players', ['name' => 'Atleta Novo']);
    }

    public function test_store_is_allowed_below_the_limit(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(29, 30);

        $this->postJson('/api/players', $this->newPlayerPayload($team))->assertCreated();
    }

    public function test_store_is_blocked_for_team_already_above_the_limit(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(32, 30);

        $this->postJson('/api/players', $this->newPlayerPayload($team))
            ->assertStatus(422)
            ->assertJsonPath('message', 'O time Alfa FC já tem 32 atletas e o limite da liga Série A é de 30 por time.');
    }

    public function test_store_does_not_count_removed_players(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(30, 30);
        $team->players()->first()->delete();

        $this->postJson('/api/players', $this->newPlayerPayload($team))->assertCreated();
    }

    public function test_coach_is_also_blocked_at_the_limit(): void
    {
        $team = $this->teamWithRoster(30, 30);
        $user = User::factory()->create(['team_id' => $team->id]);
        $user->assignRole('coach');
        Sanctum::actingAs($user);

        $this->postJson('/api/players', $this->newPlayerPayload($team))->assertStatus(422);
    }

    public function test_restoring_by_document_is_blocked_at_the_limit(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(30, 30);
        $removed = Player::factory()->create(['team_id' => Team::factory()->create()->id, 'document' => '12312312312']);
        $removed->delete();

        $this->postJson('/api/players', $this->newPlayerPayload($team, ['document' => '12312312312']))
            ->assertStatus(422)
            ->assertJsonPath('message', 'O time Alfa FC já tem 30 atletas e o limite da liga Série A é de 30 por time.');

        $this->assertSoftDeleted('players', ['id' => $removed->id]);
    }

    public function test_restoring_by_document_is_allowed_below_the_limit(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(29, 30);
        $removed = Player::factory()->create(['team_id' => $team->id, 'document' => '12312312312']);
        $removed->delete();

        $this->postJson('/api/players', $this->newPlayerPayload($team, ['document' => '12312312312']))
            ->assertCreated()
            ->assertJsonPath('data.id', $removed->id);
    }

    public function test_transfer_to_full_team_is_blocked(): void
    {
        $this->authenticate();
        $destination = $this->teamWithRoster(30, 30);
        $origin = Team::factory()->create();
        $player = Player::factory()->create(['team_id' => $origin->id]);

        $this->putJson("/api/players/{$player->id}", ['team_id' => $destination->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O time Alfa FC já tem 30 atletas e o limite da liga Série A é de 30 por time.');

        $this->assertDatabaseHas('players', ['id' => $player->id, 'team_id' => $origin->id]);
    }

    public function test_transfer_to_team_with_room_is_allowed(): void
    {
        $this->authenticate();
        $destination = $this->teamWithRoster(29, 30);
        $player = Player::factory()->create(['team_id' => Team::factory()->create()->id]);

        $this->putJson("/api/players/{$player->id}", ['team_id' => $destination->id])
            ->assertOk()
            ->assertJsonPath('data.team_id', $destination->id);
    }

    public function test_player_without_team_entering_a_full_team_is_blocked(): void
    {
        $this->authenticate();
        $destination = $this->teamWithRoster(30, 30);
        $player = Player::factory()->create(['team_id' => null]);

        $this->putJson("/api/players/{$player->id}", ['team_id' => $destination->id])->assertStatus(422);
    }

    public function test_editing_player_of_team_above_the_limit_is_allowed(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(32, 30);
        $player = $team->players()->first();

        $this->putJson("/api/players/{$player->id}", ['position' => 'LAT'])
            ->assertOk()
            ->assertJsonPath('data.position', 'LAT');

        $this->putJson("/api/players/{$player->id}", ['team_id' => (string) $team->id, 'name' => 'Nome Editado'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nome Editado');
    }

    public function test_effective_limit_is_the_smallest_among_linked_leagues(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(25, 35);
        $team->leagues()->attach(League::factory()->create([
            'name' => 'Copa Regional',
            'end_date' => null,
            'max_players_per_team' => 25,
        ])->id);

        $this->postJson('/api/players', $this->newPlayerPayload($team))
            ->assertStatus(422)
            ->assertJsonPath('message', 'O time Alfa FC já tem 25 atletas e o limite da liga Copa Regional é de 25 por time.');
    }

    public function test_ended_league_is_ignored_in_the_effective_limit(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(25, 25, now()->subDay()->toDateString());
        $team->leagues()->attach(League::factory()->create([
            'end_date' => null,
            'max_players_per_team' => 30,
        ])->id);

        $this->postJson('/api/players', $this->newPlayerPayload($team))->assertCreated();
    }

    public function test_team_with_only_ended_leagues_has_no_limit(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(25, 25, now()->subDay()->toDateString());

        $this->postJson('/api/players', $this->newPlayerPayload($team))->assertCreated();
    }

    public function test_team_without_leagues_has_no_limit(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        Player::factory()->count(36)->create(['team_id' => $team->id]);

        $this->postJson('/api/players', $this->newPlayerPayload($team))->assertCreated();
    }

    public function test_league_ending_today_still_counts(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(25, 25, now()->toDateString());

        $this->postJson('/api/players', $this->newPlayerPayload($team))->assertStatus(422);
    }

    public function test_league_with_future_end_date_counts(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(25, 25, now()->addMonth()->toDateString());

        $this->postJson('/api/players', $this->newPlayerPayload($team))->assertStatus(422);
    }

    public function test_league_without_end_date_counts(): void
    {
        $this->authenticate();
        $team = $this->teamWithRoster(25, 25);

        $this->postJson('/api/players', $this->newPlayerPayload($team))->assertStatus(422);
    }
}
