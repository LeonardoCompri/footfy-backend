<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlayerControllerTest extends TestCase
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
            'team_id'  => $team->id,
            'name'     => 'Jogador Teste',
            'position' => 'ATA',
            'number'   => 9,
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
            'name'    => 'Jogador Teste',
            'team_id' => 999,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_document_is_not_11_digits(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/players', [
            'name'     => 'Jogador Teste',
            'document' => '123',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_document_already_exists(): void
    {
        $this->authenticate();
        Player::factory()->create(['document' => '11122233344']);

        $response = $this->postJson('/api/players', [
            'name'     => 'Outro Jogador',
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
            'name'     => $player->name,
            'document' => '55566677788',
        ]);

        $response->assertOk();
    }

    // ── Admin: escopo por campeonato ──────────────────────────────────────────

    public function test_admin_index_only_sees_players_of_teams_in_assigned_championships(): void
    {
        $championship = Championship::factory()->create();
        $teamInScope  = Team::factory()->create();
        $teamOutScope = Team::factory()->create();

        $championship->teams()->attach($teamInScope->id);

        $playerInScope  = Player::factory()->create(['team_id' => $teamInScope->id]);
        $playerOutScope = Player::factory()->create(['team_id' => $teamOutScope->id]);

        $this->authenticateAsAdmin([$championship->id]);

        $response = $this->getJson('/api/players');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $playerInScope->id);
    }

    public function test_admin_with_no_championships_sees_empty_player_list(): void
    {
        Player::factory()->count(2)->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/players');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_show_player_in_assigned_championship(): void
    {
        $championship = Championship::factory()->create();
        $team         = Team::factory()->create();
        $championship->teams()->attach($team->id);
        $player = Player::factory()->create(['team_id' => $team->id]);

        $this->authenticateAsAdmin([$championship->id]);

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
        $team   = Team::factory()->create();
        $player = Player::factory()->create(['team_id' => $team->id]);

        $this->deleteJson("/api/teams/{$team->id}")->assertStatus(204);

        $this->assertDatabaseHas('players', ['id' => $player->id, 'team_id' => null]);
    }
}
