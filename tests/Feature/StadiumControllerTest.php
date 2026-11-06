<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\League;
use App\Models\Stadium;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class StadiumControllerTest extends TestCase
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

    public function test_guest_cannot_list_stadiums(): void
    {
        $response = $this->getJson('/api/stadiums');

        $response->assertStatus(401);
    }

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

    public function test_admin_with_league_access_can_delete_stadium(): void
    {
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->deleteJson("/api/stadiums/{$stadium->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('stadiums', ['id' => $stadium->id]);
    }

    public function test_admin_without_league_access_cannot_delete_stadium(): void
    {
        $stadium = Stadium::factory()->create();
        $this->authenticateAsAdmin([League::factory()->create()->id]);

        $response = $this->deleteJson("/api/stadiums/{$stadium->id}");

        $response->assertForbidden()
            ->assertJsonPath('message', 'Você não tem acesso a este recurso.');
        $this->assertDatabaseHas('stadiums', ['id' => $stadium->id]);
    }

    public function test_user_without_stadium_delete_permission_cannot_delete_stadium(): void
    {
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $coach = User::factory()->create();
        $coach->assignRole('coach');
        $this->linkUserToLeagues($coach, [$league->id]);
        Sanctum::actingAs($coach);

        $response = $this->deleteJson("/api/stadiums/{$stadium->id}");

        $response->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->assertDatabaseHas('stadiums', ['id' => $stadium->id]);
    }

    public function test_cannot_delete_stadium_with_linked_games(): void
    {
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        Game::factory()->create(['league_id' => $league->id, 'stadium_id' => $stadium->id]);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->deleteJson("/api/stadiums/{$stadium->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Não é possível remover o estádio pois existem partidas vinculadas a ele.');
        $this->assertDatabaseHas('stadiums', ['id' => $stadium->id]);
    }

    public function test_master_cannot_delete_stadium_with_linked_games(): void
    {
        $this->authenticate();
        $stadium = Stadium::factory()->create();
        Game::factory()->create(['league_id' => $stadium->league_id, 'stadium_id' => $stadium->id]);

        $response = $this->deleteJson("/api/stadiums/{$stadium->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Não é possível remover o estádio pois existem partidas vinculadas a ele.');
        $this->assertDatabaseHas('stadiums', ['id' => $stadium->id]);
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

        $this->getJson("/api/stadiums?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('league_id');
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $this->authenticate();

        $this->getJson('/api/stadiums/1abc')->assertNotFound();
    }

    public function test_coach_cannot_create_stadium(): void
    {
        $league = League::factory()->create();
        $this->authenticateAs('coach', [$league->id]);

        $response = $this->postJson('/api/stadiums', [
            'league_id' => $league->id,
            'name' => 'Arena do Coach',
            'city' => 'Recife',
            'capacity' => 1000,
        ]);

        $response->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->assertDatabaseMissing('stadiums', ['name' => 'Arena do Coach']);
    }

    public static function rolesWithoutStadiumWriteProvider(): array
    {
        return ['coach' => ['coach'], 'player' => ['player']];
    }

    #[DataProvider('rolesWithoutStadiumWriteProvider')]
    public function test_role_without_stadium_update_cannot_update_stadium(string $role): void
    {
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id, 'capacity' => 1000]);
        $this->authenticateAs($role, [$league->id]);

        $response = $this->putJson("/api/stadiums/{$stadium->id}", ['capacity' => 5000]);

        $response->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->assertDatabaseHas('stadiums', ['id' => $stadium->id, 'capacity' => 1000]);
    }

    public function test_player_cannot_create_stadium(): void
    {
        $league = League::factory()->create();
        $this->authenticateAs('player', [$league->id]);

        $this->postJson('/api/stadiums', [
            'league_id' => $league->id,
            'name' => 'Arena do Player',
            'city' => 'Recife',
            'capacity' => 1000,
        ])->assertForbidden()->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->assertDatabaseMissing('stadiums', ['name' => 'Arena do Player']);
    }

    public function test_coach_can_still_show_stadium_of_own_league(): void
    {
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $this->authenticateAs('coach', [$league->id]);

        $this->getJson("/api/stadiums/{$stadium->id}")->assertOk();
    }

    public function test_admin_can_create_stadium_in_assigned_league(): void
    {
        $league = League::factory()->create();
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->postJson('/api/stadiums', [
            'league_id' => $league->id,
            'name' => 'Arena do Admin',
            'city' => 'Recife',
            'capacity' => 1000,
        ]);

        $response->assertCreated()->assertJsonPath('data.league_id', $league->id);
    }

    public function test_admin_cannot_create_stadium_in_unassigned_league(): void
    {
        $this->authenticateAsAdmin([League::factory()->create()->id]);
        $otherLeague = League::factory()->create();

        $this->postJson('/api/stadiums', [
            'league_id' => $otherLeague->id,
            'name' => 'Arena Intrusa',
            'city' => 'Recife',
            'capacity' => 1000,
        ])->assertStatus(422)->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);

        $this->assertDatabaseMissing('stadiums', ['name' => 'Arena Intrusa']);
    }

    public function test_admin_cannot_move_stadium_to_unassigned_league(): void
    {
        $league = League::factory()->create();
        $otherLeague = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $this->authenticateAsAdmin([$league->id]);

        $this->putJson("/api/stadiums/{$stadium->id}", ['league_id' => $otherLeague->id])
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);

        $this->assertDatabaseHas('stadiums', ['id' => $stadium->id, 'league_id' => $league->id]);
    }

    public function test_admin_can_update_stadium_keeping_its_league_id(): void
    {
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $this->authenticateAsAdmin([$league->id]);

        $this->putJson("/api/stadiums/{$stadium->id}", ['league_id' => $league->id, 'capacity' => 3000])
            ->assertOk()
            ->assertJsonPath('data.capacity', 3000);
    }

    public function test_master_can_create_and_move_stadium_in_any_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $otherLeague = League::factory()->create();

        $id = $this->postJson('/api/stadiums', [
            'league_id' => $league->id,
            'name' => 'Arena do Master',
            'city' => 'Recife',
            'capacity' => 1000,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/stadiums/{$id}", ['league_id' => $otherLeague->id])
            ->assertOk()
            ->assertJsonPath('data.league_id', $otherLeague->id);
    }
}
