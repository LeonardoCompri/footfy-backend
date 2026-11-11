<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Repositories\TeamRepository;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class TeamControllerTest extends TestCase
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

    public function test_guest_cannot_list_teams(): void
    {
        $response = $this->getJson('/api/teams');

        $response->assertStatus(401);
    }

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

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Sport Club X')
            ->assertJsonPath('data.leagues', []);
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

    public function test_store_fails_validation_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teams', []);

        $response->assertStatus(422);
    }

    public function test_master_can_create_team_with_logo(): void
    {
        Storage::fake('public');
        $this->authenticate();

        $response = $this->postJson('/api/teams', [
            'name' => 'Sport Club X',
            'logo' => UploadedFile::fake()->image('logo.jpg'),
        ]);

        $response->assertCreated();
        $team = Team::first();
        $this->assertNotNull($team->logo);
        Storage::disk('public')->assertExists($team->logo);
        $response->assertJsonPath('data.logo', Storage::disk('public')->url($team->logo));
    }

    public function test_master_can_update_team_replacing_logo(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $oldPath = UploadedFile::fake()->image('old.jpg')->store('teams', 'public');
        $team = Team::factory()->create(['logo' => $oldPath]);

        $response = $this->putJson("/api/teams/{$team->id}", [
            'logo' => UploadedFile::fake()->image('new.jpg'),
        ]);

        $response->assertOk();
        $team->refresh();
        $this->assertNotEquals($oldPath, $team->logo);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($team->logo);
    }

    public function test_create_team_without_logo_keeps_it_null(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teams', ['name' => 'Sport Club X']);

        $response->assertCreated()->assertJsonPath('data.logo', null);
        $this->assertDatabaseHas('teams', ['name' => 'Sport Club X', 'logo' => null]);
    }

    public function test_update_team_without_logo_keeps_existing_logo(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('teams', 'public');
        $team = Team::factory()->create(['logo' => $path]);

        $response = $this->putJson("/api/teams/{$team->id}", ['nickname' => 'NOVO']);

        $response->assertOk();
        $team->refresh();
        $this->assertEquals($path, $team->logo);
        Storage::disk('public')->assertExists($path);
    }

    public function test_update_team_with_empty_logo_removes_it(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('teams', 'public');
        $team = Team::factory()->create(['logo' => $path]);

        $response = $this->post("/api/teams/{$team->id}", [
            '_method' => 'PUT',
            'name' => 'Novo Nome',
            'logo' => '',
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('data.logo', null);
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'Novo Nome', 'logo' => null]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_update_team_via_multipart_without_logo_keeps_it(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('teams', 'public');
        $team = Team::factory()->create(['logo' => $path]);

        $response = $this->post("/api/teams/{$team->id}", [
            '_method' => 'PUT',
            'name' => 'Novo Nome',
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'logo' => $path]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_update_keeps_old_logo_and_removes_new_upload_when_save_fails(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $oldPath = UploadedFile::fake()->image('old.jpg')->store('teams', 'public');
        $team = Team::factory()->create(['name' => 'Antigo', 'logo' => $oldPath]);
        $this->partialMock(TeamRepository::class, function ($mock) {
            $mock->shouldReceive('update')->andThrow(new \RuntimeException('falha simulada'));
        });

        $response = $this->post("/api/teams/{$team->id}", [
            '_method' => 'PUT',
            'name' => 'Novo',
            'logo' => UploadedFile::fake()->image('new.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(500);
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'Antigo', 'logo' => $oldPath]);
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles());
    }

    public function test_store_fails_validation_with_invalid_logo_format(): void
    {
        Storage::fake('public');
        $this->authenticate();

        $response = $this->postJson('/api/teams', [
            'name' => 'Sport Club X',
            'logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('logo');
    }

    public function test_store_fails_validation_with_logo_larger_than_2mb(): void
    {
        Storage::fake('public');
        $this->authenticate();

        $response = $this->postJson('/api/teams', [
            'name' => 'Sport Club X',
            'logo' => UploadedFile::fake()->image('logo.jpg')->size(2049),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('logo');
    }

    public function test_master_can_create_team_with_leagues(): void
    {
        $this->authenticate();
        $league1 = League::factory()->create();
        $league2 = League::factory()->create();

        $response = $this->postJson('/api/teams', [
            'name' => 'Sport Club X',
            'league_ids' => [$league1->id, $league2->id],
        ]);

        $response->assertCreated()
            ->assertJsonCount(2, 'data.leagues')
            ->assertJsonFragment(['id' => $league1->id, 'name' => $league1->name])
            ->assertJsonFragment(['id' => $league2->id, 'name' => $league2->name]);
        $teamId = $response->json('data.id');
        $this->assertDatabaseHas('league_team', ['team_id' => $teamId, 'league_id' => $league1->id]);
        $this->assertDatabaseHas('league_team', ['team_id' => $teamId, 'league_id' => $league2->id]);
    }

    public function test_admin_can_create_team_with_assigned_league(): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->postJson('/api/teams', [
            'name' => 'Sport Club X',
            'league_ids' => [$league->id],
        ]);

        $response->assertCreated()->assertJsonPath('data.leagues', [['id' => $league->id, 'name' => $league->name]]);
        $this->assertDatabaseHas('league_team', ['team_id' => $response->json('data.id'), 'league_id' => $league->id]);
    }

    public function test_admin_can_create_team_without_leagues(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/teams', ['name' => 'Sport Club X']);

        $response->assertCreated()->assertJsonPath('data.leagues', []);
        $this->assertDatabaseHas('teams', ['name' => 'Sport Club X']);
    }

    public function test_admin_cannot_create_team_with_unassigned_league(): void
    {
        Storage::fake('public');
        $assigned = League::factory()->create();
        $otherLeague = League::factory()->create();
        $this->authenticateAsAdmin([$assigned->id]);

        $response = $this->post('/api/teams', [
            'name' => 'Sport Club X',
            'logo' => UploadedFile::fake()->image('logo.jpg'),
            'league_ids' => [$assigned->id, $otherLeague->id],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);
        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('league_team', 0);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_store_fails_validation_with_nonexistent_league_id(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teams', [
            'name' => 'Sport Club X',
            'league_ids' => [9999],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('league_ids.0');
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_store_fails_validation_when_league_ids_is_not_array(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teams', [
            'name' => 'Sport Club X',
            'league_ids' => 'abc',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('league_ids');
    }

    public function test_master_can_create_team_with_logo_and_leagues_via_multipart(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $league1 = League::factory()->create();
        $league2 = League::factory()->create();

        $response = $this->post('/api/teams', [
            'name' => 'Sport Club X',
            'logo' => UploadedFile::fake()->image('logo.png'),
            'league_ids' => [(string) $league1->id, (string) $league2->id],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonCount(2, 'data.leagues');
        $team = Team::first();
        Storage::disk('public')->assertExists($team->logo);
        $this->assertEqualsCanonicalizing([$league1->id, $league2->id], $team->leagues()->pluck('leagues.id')->all());
    }

    public function test_store_rolls_back_and_removes_logo_when_league_sync_fails(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $league = League::factory()->create();
        $this->partialMock(TeamRepository::class, function ($mock) {
            $mock->shouldReceive('syncLeagues')->andThrow(new \RuntimeException('falha simulada'));
        });

        $response = $this->post('/api/teams', [
            'name' => 'Sport Club X',
            'logo' => UploadedFile::fake()->image('logo.jpg'),
            'league_ids' => [$league->id],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(500);
        $this->assertDatabaseCount('teams', 0);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_master_show_returns_all_team_leagues(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $league1 = League::factory()->create();
        $league2 = League::factory()->create();
        $team->leagues()->attach([$league1->id, $league2->id]);

        $response = $this->getJson("/api/teams/{$team->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data.leagues')
            ->assertJsonFragment(['id' => $league1->id, 'name' => $league1->name])
            ->assertJsonFragment(['id' => $league2->id, 'name' => $league2->name]);
    }

    public function test_master_show_ignores_league_id_filter_for_leagues(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $league1 = League::factory()->create();
        $league2 = League::factory()->create();
        $team->leagues()->attach([$league1->id, $league2->id]);

        $response = $this->getJson("/api/teams/{$team->id}?league_id={$league1->id}");

        $response->assertOk()->assertJsonCount(2, 'data.leagues');
    }

    public function test_admin_show_returns_only_accessible_team_leagues(): void
    {
        $team = Team::factory()->create();
        $assigned = League::factory()->create();
        $other = League::factory()->create();
        $team->leagues()->attach([$assigned->id, $other->id]);
        $this->authenticateAsAdmin([$assigned->id]);

        $response = $this->getJson("/api/teams/{$team->id}");

        $response->assertOk()->assertJsonPath('data.leagues', [['id' => $assigned->id, 'name' => $assigned->name]]);
    }

    public function test_admin_show_with_league_id_returns_only_that_league(): void
    {
        $team = Team::factory()->create();
        $league1 = League::factory()->create();
        $league2 = League::factory()->create();
        $team->leagues()->attach([$league1->id, $league2->id]);
        $this->authenticateAsAdmin([$league1->id, $league2->id]);

        $response = $this->getJson("/api/teams/{$team->id}?league_id={$league2->id}");

        $response->assertOk()->assertJsonPath('data.leagues', [['id' => $league2->id, 'name' => $league2->name]]);
    }

    public function test_admin_show_with_league_id_not_linked_to_team_returns_empty_leagues(): void
    {
        $team = Team::factory()->create();
        $teamLeague = League::factory()->create();
        $otherLeague = League::factory()->create();
        $team->leagues()->attach($teamLeague->id);
        $this->authenticateAsAdmin([$teamLeague->id, $otherLeague->id]);

        $response = $this->getJson("/api/teams/{$team->id}?league_id={$otherLeague->id}");

        $response->assertOk()->assertJsonPath('data.leagues', []);
    }

    public function test_admin_show_with_unassigned_league_id_returns_422(): void
    {
        $team = Team::factory()->create();
        $assigned = League::factory()->create();
        $other = League::factory()->create();
        $team->leagues()->attach([$assigned->id, $other->id]);
        $this->authenticateAsAdmin([$assigned->id]);

        $response = $this->getJson("/api/teams/{$team->id}?league_id={$other->id}");

        $response->assertStatus(422)->assertJson(['message' => 'Você não tem acesso a essa liga.']);
    }

    #[DataProvider('invalidLeagueIdQueryProvider')]
    public function test_show_with_invalid_league_id_filter_returns_422(string $query): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $team->leagues()->attach($league->id);
        $this->authenticateAsAdmin([$league->id]);

        $this->getJson("/api/teams/{$team->id}?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('league_id');
    }

    public function test_admin_update_response_keeps_leagues_scoped_to_access(): void
    {
        $team = Team::factory()->create();
        $assigned = League::factory()->create();
        $other = League::factory()->create();
        $team->leagues()->attach([$assigned->id, $other->id]);
        $this->authenticateAsAdmin([$assigned->id]);

        $response = $this->putJson("/api/teams/{$team->id}", ['nickname' => 'NOVO']);

        $response->assertOk()
            ->assertJsonPath('data.nickname', 'NOVO')
            ->assertJsonPath('data.leagues', [['id' => $assigned->id, 'name' => $assigned->name]]);
        $this->assertEquals(2, $team->leagues()->count());
    }

    public function test_show_runs_fixed_number_of_queries_regardless_of_relations_size(): void
    {
        $countQueries = function (Team $team, User $admin): int {
            Sanctum::actingAs($admin->fresh());
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/teams/{$team->id}")->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $leagues = League::factory()->count(5)->create();
        $admin = $this->authenticateAsAdmin($leagues->pluck('id')->all());

        $smallTeam = Team::factory()->create();
        $smallTeam->leagues()->attach($leagues->first()->id);
        \App\Models\Player::factory()->create(['team_id' => $smallTeam->id]);
        \App\Models\TechnicalCommitteeMember::factory()->create(['team_id' => $smallTeam->id]);

        $bigTeam = Team::factory()->create();
        $bigTeam->leagues()->attach($leagues->pluck('id')->all());
        \App\Models\Player::factory()->count(5)->create(['team_id' => $bigTeam->id]);
        \App\Models\TechnicalCommitteeMember::factory()->count(5)->create(['team_id' => $bigTeam->id]);

        $smallCount = $countQueries($smallTeam, $admin);
        $bigCount = $countQueries($bigTeam, $admin);

        $this->assertSame($smallCount, $bigCount);

        $this->getJson("/api/teams/{$bigTeam->id}")
            ->assertOk()
            ->assertJsonCount(5, 'data.leagues')
            ->assertJsonCount(5, 'data.players')
            ->assertJsonCount(5, 'data.technicalCommitteeMembers');
    }

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

    public function test_delete_team_removes_only_its_logo(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('teams', 'public');
        $otherPath = UploadedFile::fake()->image('other.jpg')->store('teams', 'public');
        $team = Team::factory()->create(['logo' => $path]);
        $other = Team::factory()->create(['logo' => $otherPath]);

        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(204);

        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($otherPath);
        $this->assertDatabaseHas('teams', ['id' => $other->id, 'logo' => $otherPath]);
    }

    public function test_delete_team_with_games_keeps_logo(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('teams', 'public');
        $team = Team::factory()->create(['logo' => $path]);
        \App\Models\Game::factory()->create(['home_team_id' => $team->id]);

        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(422);

        Storage::disk('public')->assertExists($path);
    }

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

    public function test_public_show_hides_players_document(): void
    {
        $team = Team::factory()->create();
        $player = \App\Models\Player::factory()->create(['team_id' => $team->id]);

        $this->getJson("/api/public/teams/{$team->id}")
            ->assertOk()
            ->assertJsonPath('data.players.0.id', $player->id)
            ->assertJsonMissingPath('data.players.0.document');
    }

    public function test_authenticated_show_still_returns_players_document(): void
    {
        $this->authenticate();
        $team = Team::factory()->create();
        $player = \App\Models\Player::factory()->create(['team_id' => $team->id]);

        $this->getJson("/api/teams/{$team->id}")
            ->assertOk()
            ->assertJsonPath('data.players.0.document', $player->document);
    }

    public function test_guest_show_via_public_route_returns_error_when_not_found(): void
    {
        $response = $this->getJson('/api/public/teams/999');

        $response->assertStatus(422)->assertJson(['message' => 'Time não encontrado!']);
    }

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

    public static function invalidLeagueIdQueryProvider(): array
    {
        return [
            'array' => ['league_id[]=1'],
            'sufixo não numérico' => ['league_id=1abc'],
            'texto' => ['league_id=abc'],
        ];
    }

    #[DataProvider('invalidLeagueIdQueryProvider')]
    public function test_invalid_league_id_filter_returns_422(string $query): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin([$league->id]);

        $this->getJson("/api/teams?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('league_id');
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $this->authenticate();

        $this->getJson('/api/teams/1abc')->assertNotFound();
    }

    public function test_player_cannot_update_team_of_own_league(): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create(['nickname' => 'OLD']);
        $league->teams()->attach($team->id);
        $this->authenticateAs('player', [$league->id]);

        $response = $this->putJson("/api/teams/{$team->id}", ['nickname' => 'NEW']);

        $response->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'nickname' => 'OLD']);
    }

    public function test_player_can_show_team_of_own_league(): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);
        $this->authenticateAs('player', [$league->id]);

        $this->getJson("/api/teams/{$team->id}")->assertOk();
    }

    public static function teamWriteRequestsProvider(): array
    {
        $routes = [
            'store' => ['post', '/api/teams'],
            'update' => ['put', '/api/teams/{id}'],
            'destroy' => ['delete', '/api/teams/{id}'],
            'leagues.sync' => ['post', '/api/teams/{id}/leagues'],
            'leagues.remove' => ['delete', '/api/teams/{id}/leagues/{lid}'],
        ];

        $cases = [];
        foreach (['coach', 'player'] as $role) {
            foreach ($routes as $name => [$method, $uri]) {
                $cases["{$role} {$name}"] = [$role, $method, $uri];
            }
        }

        return $cases;
    }

    #[DataProvider('teamWriteRequestsProvider')]
    public function test_role_without_team_permission_cannot_write_teams(string $role, string $method, string $uri): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create(['name' => 'Time Original']);
        $league->teams()->attach($team->id);
        $this->authenticateAs($role, [$league->id]);
        $teamCount = Team::count();

        $url = str_replace(['{id}', '{lid}'], [$team->id, $league->id], $uri);
        $response = $this->json($method, $url, ['name' => 'Time Novo', 'league_ids' => [$league->id]]);

        $response->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->assertDatabaseCount('teams', $teamCount);
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'Time Original']);
        $this->assertDatabaseHas('league_team', ['team_id' => $team->id, 'league_id' => $league->id]);
    }

    public function test_admin_can_update_team_in_assigned_league(): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create(['nickname' => 'OLD']);
        $league->teams()->attach($team->id);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->putJson("/api/teams/{$team->id}", ['nickname' => 'NEW']);

        $response->assertOk()->assertJsonPath('data.nickname', 'NEW');
    }

    public function test_admin_can_delete_team_in_assigned_league(): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);
        $this->authenticateAsAdmin([$league->id]);

        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(204);

        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    public function test_admin_can_remove_league_from_team_in_assigned_league(): void
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);
        $this->authenticateAsAdmin([$league->id]);

        $this->deleteJson("/api/teams/{$team->id}/leagues/{$league->id}")->assertOk();

        $this->assertDatabaseMissing('league_team', ['team_id' => $team->id, 'league_id' => $league->id]);
    }

    public function test_admin_outside_league_is_blocked_by_resource_access_not_by_permission(): void
    {
        $team = Team::factory()->create();
        League::factory()->create()->teams()->attach($team->id);
        $this->authenticateAsAdmin([League::factory()->create()->id]);

        $response = $this->putJson("/api/teams/{$team->id}", ['nickname' => 'NEW']);

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_master_without_permissions_is_allowed_by_gate_before(): void
    {
        Role::findByName('master')->syncPermissions([]);
        $this->authenticate();
        $team = Team::factory()->create(['nickname' => 'OLD']);

        $this->putJson("/api/teams/{$team->id}", ['nickname' => 'NEW'])
            ->assertOk()
            ->assertJsonPath('data.nickname', 'NEW');
        $this->postJson('/api/teams', ['name' => 'Novo Time'])->assertCreated();
        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(204);
    }

    private function teamWithPlayers(int $count, string $name = 'Alfa FC'): Team
    {
        $team = Team::factory()->create(['name' => $name]);
        Player::factory()->count($count)->create(['team_id' => $team->id]);

        return $team;
    }

    public function test_linking_team_above_the_league_limit_is_blocked(): void
    {
        $this->authenticate();
        $team = $this->teamWithPlayers(31);
        $league = League::factory()->create(['name' => 'Série A', 'end_date' => null, 'max_players_per_team' => 30]);

        $this->postJson("/api/teams/{$team->id}/leagues", ['league_ids' => [$league->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O time Alfa FC tem 31 atletas, acima do limite de 30 por time da liga Série A.');

        $this->assertDatabaseMissing('league_team', ['team_id' => $team->id, 'league_id' => $league->id]);
    }

    public function test_linking_team_exactly_at_the_league_limit_is_allowed(): void
    {
        $this->authenticate();
        $team = $this->teamWithPlayers(30);
        $league = League::factory()->create(['end_date' => null, 'max_players_per_team' => 30]);

        $this->postJson("/api/teams/{$team->id}/leagues", ['league_ids' => [$league->id]])->assertOk();

        $this->assertDatabaseHas('league_team', ['team_id' => $team->id, 'league_id' => $league->id]);
    }

    public function test_keeping_existing_link_of_team_above_the_limit_is_allowed(): void
    {
        $this->authenticate();
        $team = $this->teamWithPlayers(32);
        $league = League::factory()->create(['end_date' => null, 'max_players_per_team' => 30]);
        $team->leagues()->attach($league->id);

        $this->postJson("/api/teams/{$team->id}/leagues", ['league_ids' => [$league->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.leagues');
    }

    public function test_only_new_links_are_checked_against_the_limit(): void
    {
        $this->authenticate();
        $team = $this->teamWithPlayers(32);
        $existing = League::factory()->create(['end_date' => null, 'max_players_per_team' => 30]);
        $team->leagues()->attach($existing->id);
        $new = League::factory()->create(['end_date' => null, 'max_players_per_team' => 35]);

        $this->postJson("/api/teams/{$team->id}/leagues", ['league_ids' => [$existing->id, $new->id]])
            ->assertOk()
            ->assertJsonCount(2, 'data.leagues');
    }

    public function test_one_new_league_over_the_limit_blocks_the_whole_request(): void
    {
        $this->authenticate();
        $team = $this->teamWithPlayers(32);
        $allowed = League::factory()->create(['end_date' => null, 'max_players_per_team' => 35]);
        $blocked = League::factory()->create(['name' => 'Copa', 'end_date' => null, 'max_players_per_team' => 30]);

        $this->postJson("/api/teams/{$team->id}/leagues", ['league_ids' => [$allowed->id, $blocked->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O time Alfa FC tem 32 atletas, acima do limite de 30 por time da liga Copa.');

        $this->assertDatabaseMissing('league_team', ['team_id' => $team->id]);
    }

    public function test_linking_does_not_count_removed_players(): void
    {
        $this->authenticate();
        $team = $this->teamWithPlayers(31);
        $team->players()->first()->delete();
        $league = League::factory()->create(['end_date' => null, 'max_players_per_team' => 30]);

        $this->postJson("/api/teams/{$team->id}/leagues", ['league_ids' => [$league->id]])->assertOk();
    }

    public function test_linking_to_ended_league_ignores_the_limit(): void
    {
        $this->authenticate();
        $team = $this->teamWithPlayers(31);
        $league = League::factory()->create([
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'max_players_per_team' => 30,
        ]);

        $this->postJson("/api/teams/{$team->id}/leagues", ['league_ids' => [$league->id]])->assertOk();
    }
}
