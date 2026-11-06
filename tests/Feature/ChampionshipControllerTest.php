<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\Player;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\TechnicalCommitteeMember;
use App\Models\User;
use App\Repositories\LeagueRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChampionshipControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Role::create(['name' => 'master']);
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'coach']);
    }

    private function authenticate(): User
    {
        $user = User::factory()->create();
        $user->assignRole('master');
        Sanctum::actingAs($user);

        return $user;
    }

    private function authenticateAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_list_championships(): void
    {
        $response = $this->getJson('/api/championships');

        $response->assertStatus(401);
    }

    public function test_master_index_returns_all_championships(): void
    {
        $this->authenticate();
        Championship::factory()->count(2)->create();

        $response = $this->getJson('/api/championships');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_list_championships(): void
    {
        $this->authenticateAsAdmin();
        Championship::factory()->count(2)->create();

        $response = $this->getJson('/api/championships');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_show_championship(): void
    {
        $this->authenticateAsAdmin();
        $championship = Championship::factory()->create();

        $response = $this->getJson("/api/championships/{$championship->id}");

        $response->assertOk()->assertJsonPath('data.id', $championship->id);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/championships/999');

        $response->assertStatus(422)->assertJson(['message' => 'Campeonato não encontrado!']);
    }

    public function test_master_can_create_championship(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/championships', ['name' => 'Campeonato Brasileiro']);

        $response->assertCreated()->assertJsonPath('data.name', 'Campeonato Brasileiro');
        $this->assertDatabaseHas('championships', ['name' => 'Campeonato Brasileiro']);
    }

    public function test_master_can_update_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create(['name' => 'Antigo']);

        $response = $this->putJson("/api/championships/{$championship->id}", ['name' => 'Novo']);

        $response->assertOk()->assertJsonPath('data.name', 'Novo');
        $this->assertDatabaseHas('championships', ['id' => $championship->id, 'name' => 'Novo']);
    }

    public function test_master_can_delete_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();

        $response = $this->deleteJson("/api/championships/{$championship->id}", ['password' => 'password']);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('championships', ['id' => $championship->id]);
    }

    public function test_delete_championship_removes_only_its_image(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('championships', 'public');
        $otherPath = UploadedFile::fake()->image('other.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['image' => $path]);
        $other = Championship::factory()->create(['image' => $otherPath]);

        $this->deleteJson("/api/championships/{$championship->id}", ['password' => 'password'])->assertStatus(204);

        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($otherPath);
        $this->assertDatabaseHas('championships', ['id' => $other->id, 'image' => $otherPath]);
    }

    public function test_destroy_without_password_returns_422_and_keeps_championship(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['image' => $path]);
        $league = League::factory()->create(['championship_id' => $championship->id]);

        $response = $this->deleteJson("/api/championships/{$championship->id}");

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertDatabaseHas('championships', ['id' => $championship->id, 'image' => $path]);
        $this->assertDatabaseHas('leagues', ['id' => $league->id]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_destroy_with_wrong_password_returns_422_and_keeps_championship(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['image' => $path]);
        $league = League::factory()->create(['championship_id' => $championship->id]);

        $response = $this->deleteJson("/api/championships/{$championship->id}", ['password' => 'errada']);

        $response->assertStatus(422)->assertJsonPath('errors.password.0', 'Senha incorreta.');
        $this->assertDatabaseHas('championships', ['id' => $championship->id, 'image' => $path]);
        $this->assertDatabaseHas('leagues', ['id' => $league->id]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_destroy_with_correct_password_cascades_leagues_and_preserves_teams_and_users(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['image' => $path]);
        $league = League::factory()->create(['championship_id' => $championship->id]);
        $otherChampionship = Championship::factory()->create();
        $other = League::factory()->create(['championship_id' => $otherChampionship->id]);
        $orphan = League::factory()->create(['championship_id' => null]);
        $home = Team::factory()->create();
        $away = Team::factory()->create();
        $home->leagues()->attach([$league->id, $other->id]);
        $away->leagues()->attach($league->id);
        $player = Player::factory()->create(['team_id' => $home->id]);
        $member = TechnicalCommitteeMember::factory()->create(['team_id' => $home->id]);
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'stadium_id' => $stadium->id,
        ]);
        $event = GameEvent::factory()->create([
            'game_id' => $game->id,
            'league_id' => $league->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->leagues()->attach([$league->id, $other->id]);

        $response = $this->deleteJson("/api/championships/{$championship->id}", ['password' => 'password']);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('championships', ['id' => $championship->id]);
        $this->assertDatabaseMissing('leagues', ['id' => $league->id]);
        $this->assertDatabaseMissing('games', ['id' => $game->id]);
        $this->assertDatabaseMissing('game_events', ['id' => $event->id]);
        $this->assertDatabaseMissing('stadiums', ['id' => $stadium->id]);
        $this->assertDatabaseMissing('league_team', ['league_id' => $league->id]);
        $this->assertDatabaseMissing('user_leagues', ['league_id' => $league->id]);
        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseHas('championships', ['id' => $otherChampionship->id]);
        $this->assertDatabaseHas('leagues', ['id' => $other->id]);
        $this->assertDatabaseHas('leagues', ['id' => $orphan->id, 'championship_id' => null]);
        $this->assertDatabaseHas('league_team', ['league_id' => $other->id, 'team_id' => $home->id]);
        $this->assertDatabaseHas('user_leagues', ['league_id' => $other->id, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('teams', ['id' => $home->id]);
        $this->assertDatabaseHas('teams', ['id' => $away->id]);
        $this->assertDatabaseHas('players', ['id' => $player->id, 'team_id' => $home->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('technical_committee_members', ['id' => $member->id, 'team_id' => $home->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_coach_cannot_delete_championship(): void
    {
        $championship = Championship::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('coach');
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/championships/{$championship->id}", ['password' => 'password']);

        $response->assertForbidden();
        $this->assertDatabaseHas('championships', ['id' => $championship->id]);
    }

    public function test_destroy_is_throttled_after_five_attempts(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->deleteJson("/api/championships/{$championship->id}", ['password' => 'errada'])->assertStatus(422);
        }

        $this->deleteJson("/api/championships/{$championship->id}", ['password' => 'password'])->assertStatus(429);
        $this->assertDatabaseHas('championships', ['id' => $championship->id]);
    }

    public function test_master_can_create_championship_with_image(): void
    {
        Storage::fake('public');
        $this->authenticate();

        $response = $this->postJson('/api/championships', [
            'name' => 'Campeonato Brasileiro',
            'image' => UploadedFile::fake()->image('logo.jpg'),
        ]);

        $response->assertCreated();
        $championship = Championship::first();
        $this->assertNotNull($championship->image);
        Storage::disk('public')->assertExists($championship->image);
        $response->assertJsonPath('data.image', Storage::disk('public')->url($championship->image));
    }

    public function test_master_can_update_championship_replacing_image(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $oldPath = UploadedFile::fake()->image('old.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['image' => $oldPath]);

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'image' => UploadedFile::fake()->image('new.jpg'),
        ]);

        $response->assertOk();
        $championship->refresh();
        $this->assertNotEquals($oldPath, $championship->image);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($championship->image);
    }

    public function test_create_championship_without_image_keeps_it_null(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/championships', ['name' => 'Campeonato Brasileiro']);

        $response->assertCreated()->assertJsonPath('data.image', null);
        $this->assertDatabaseHas('championships', ['name' => 'Campeonato Brasileiro', 'image' => null]);
    }

    public function test_update_championship_without_image_keeps_existing_image(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['image' => $path]);

        $response = $this->putJson("/api/championships/{$championship->id}", ['name' => 'Novo Nome']);

        $response->assertOk();
        $championship->refresh();
        $this->assertEquals($path, $championship->image);
        Storage::disk('public')->assertExists($path);
    }

    public function test_update_championship_with_empty_image_removes_it(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['image' => $path]);

        $response = $this->post("/api/championships/{$championship->id}", [
            '_method' => 'PUT',
            'name' => 'Novo Nome',
            'image' => '',
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('data.image', null);
        $this->assertDatabaseHas('championships', ['id' => $championship->id, 'name' => 'Novo Nome', 'image' => null]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_update_championship_via_multipart_without_image_keeps_it(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['image' => $path]);

        $response = $this->post("/api/championships/{$championship->id}", [
            '_method' => 'PUT',
            'name' => 'Novo Nome',
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertDatabaseHas('championships', ['id' => $championship->id, 'image' => $path]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_store_fails_validation_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/championships', []);

        $response->assertStatus(422);
    }

    public function test_admin_cannot_create_championship(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/championships', ['name' => 'Novo']);

        $response->assertForbidden();
    }

    public function test_admin_cannot_update_championship(): void
    {
        $championship = Championship::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->putJson("/api/championships/{$championship->id}", ['name' => 'X']);

        $response->assertForbidden();
    }

    public function test_admin_cannot_delete_championship(): void
    {
        $championship = Championship::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->deleteJson("/api/championships/{$championship->id}", ['password' => 'password']);

        $response->assertForbidden();
        $this->assertDatabaseHas('championships', ['id' => $championship->id]);
    }

    public function test_guest_can_list_championships_via_public_route(): void
    {
        Championship::factory()->count(2)->create();

        $response = $this->getJson('/api/public/championships');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_guest_can_show_championship_via_public_route_with_leagues(): void
    {
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id]);

        $response = $this->getJson("/api/public/championships/{$championship->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $championship->id)
            ->assertJsonPath('data.leagues.0.id', $league->id);
    }

    public function test_guest_show_via_public_route_returns_error_when_not_found(): void
    {
        $response = $this->getJson('/api/public/championships/999');

        $response->assertStatus(422)->assertJson(['message' => 'Campeonato não encontrado!']);
    }

    public function test_public_index_response_does_not_include_leagues(): void
    {
        Championship::factory()->create();

        $response = $this->getJson('/api/public/championships');

        $response->assertOk();
        $this->assertArrayNotHasKey('leagues', $response->json('data.0'));
    }

    public function test_regular_show_response_includes_leagues(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id]);

        $response = $this->getJson("/api/championships/{$championship->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.leagues')
            ->assertJsonPath('data.leagues.0.id', $league->id);
    }

    public function test_index_response_does_not_include_leagues(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        League::factory()->create(['championship_id' => $championship->id]);

        $response = $this->getJson('/api/championships');

        $response->assertOk();
        $this->assertArrayNotHasKey('leagues', $response->json('data.0'));
    }

    public function test_show_of_championship_without_leagues_returns_empty_leagues(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();

        $this->getJson("/api/championships/{$championship->id}")
            ->assertOk()
            ->assertJsonPath('data.leagues', []);
        $this->getJson("/api/public/championships/{$championship->id}")
            ->assertOk()
            ->assertJsonPath('data.leagues', []);
        $this->getJson('/api/public/championships')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_master_can_create_championship_with_leagues(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/championships', [
            'name' => 'Campeonato Brasileiro',
            'leagues' => [
                ['name' => 'Série A 2026', 'format' => 'hybrid', 'season' => '2026', 'organizer' => 'CBF', 'start_date' => '2026-04-01', 'end_date' => '2026-12-01'],
                ['name' => 'Série B 2026', 'format' => null, 'season' => null, 'organizer' => null, 'start_date' => null, 'end_date' => null],
            ],
        ]);

        $championship = Championship::first();
        $response->assertCreated()
            ->assertJsonPath('data.id', $championship->id)
            ->assertJsonCount(2, 'data.leagues')
            ->assertJsonPath('data.leagues.0.name', 'Série A 2026')
            ->assertJsonPath('data.leagues.0.format', 'hybrid')
            ->assertJsonPath('data.leagues.0.start_date', '2026-04-01')
            ->assertJsonPath('data.leagues.1.format', 'round_robin');
        $this->assertDatabaseHas('leagues', ['name' => 'Série A 2026', 'championship_id' => $championship->id, 'season' => '2026']);
        $this->assertDatabaseHas('leagues', ['name' => 'Série B 2026', 'championship_id' => $championship->id, 'format' => 'round_robin']);
    }

    public function test_master_can_create_championship_without_leagues(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/championships', ['name' => 'Copa X']);

        $response->assertCreated()->assertJsonPath('data.leagues', []);
        $this->assertDatabaseCount('leagues', 0);
    }

    public function test_store_ignores_championship_id_and_id_sent_inside_leagues(): void
    {
        $this->authenticate();
        $other = Championship::factory()->create();
        $existing = League::factory()->create(['championship_id' => $other->id, 'name' => 'Intocada']);

        $response = $this->postJson('/api/championships', [
            'name' => 'Copa X',
            'leagues' => [['id' => $existing->id, 'name' => 'Nova', 'championship_id' => $other->id]],
        ]);

        $response->assertCreated();
        $championship = Championship::where('name', 'Copa X')->first();
        $this->assertDatabaseHas('leagues', ['name' => 'Nova', 'championship_id' => $championship->id]);
        $this->assertDatabaseHas('leagues', ['id' => $existing->id, 'name' => 'Intocada', 'championship_id' => $other->id]);
    }

    public function test_store_fails_when_league_name_is_empty(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/championships', [
            'name' => 'Copa X',
            'leagues' => [['name' => '']],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['leagues.0.name']);
        $this->assertSame('O campo nome da liga é obrigatório.', $response->json('errors')['leagues.0.name'][0]);
        $this->assertDatabaseCount('championships', 0);
    }

    public function test_store_fails_when_league_end_date_is_before_start_date(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/championships', [
            'name' => 'Copa X',
            'leagues' => [
                ['name' => 'Válida'],
                ['name' => 'Série A', 'start_date' => '2026-05-01', 'end_date' => '2026-04-01'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['leagues.1.end_date']);
        $this->assertStringContainsString('data de término', $response->json('errors')['leagues.1.end_date'][0]);
        $this->assertStringContainsString('data de início', $response->json('errors')['leagues.1.end_date'][0]);
        $this->assertDatabaseCount('championships', 0);
        $this->assertDatabaseCount('leagues', 0);
    }

    public function test_store_fails_with_invalid_league_format(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/championships', [
            'name' => 'Copa X',
            'leagues' => [['name' => 'Série A', 'format' => 'invalido']],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['leagues.0.format']);
    }

    public function test_store_rolls_back_and_removes_image_when_league_creation_fails(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $this->partialMock(LeagueRepository::class, function ($mock) {
            $mock->shouldReceive('store')->andThrow(new \RuntimeException('falha simulada'));
        });

        $response = $this->post('/api/championships', [
            'name' => 'Copa X',
            'image' => UploadedFile::fake()->image('logo.jpg'),
            'leagues' => [['name' => 'Série A']],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(500);
        $this->assertDatabaseCount('championships', 0);
        $this->assertDatabaseCount('leagues', 0);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_update_creates_updates_and_keeps_missing_leagues(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create(['name' => 'Antigo']);
        $updated = League::factory()->create(['championship_id' => $championship->id, 'name' => 'Série A', 'format' => 'knockout']);
        $untouched = League::factory()->create(['championship_id' => $championship->id, 'name' => 'Série C']);

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'name' => 'Novo',
            'leagues' => [
                ['id' => $updated->id, 'name' => 'Série A 2026', 'season' => '2026', 'format' => null],
                ['name' => 'Série B 2026'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Novo')
            ->assertJsonCount(3, 'data.leagues');
        $this->assertDatabaseHas('leagues', ['id' => $updated->id, 'name' => 'Série A 2026', 'season' => '2026', 'format' => 'knockout', 'championship_id' => $championship->id]);
        $this->assertDatabaseHas('leagues', ['name' => 'Série B 2026', 'championship_id' => $championship->id, 'format' => 'round_robin']);
        $this->assertDatabaseHas('leagues', ['id' => $untouched->id, 'name' => 'Série C', 'championship_id' => $championship->id]);
        $this->assertDatabaseCount('leagues', 3);
    }

    public function test_update_without_leagues_keeps_all_leagues(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        League::factory()->count(2)->create(['championship_id' => $championship->id]);

        $response = $this->putJson("/api/championships/{$championship->id}", ['name' => 'Novo']);

        $response->assertOk()->assertJsonCount(2, 'data.leagues');
        $this->assertDatabaseCount('leagues', 2);
    }

    public function test_update_fails_with_league_id_from_another_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        $foreign = League::factory()->create(['championship_id' => Championship::factory()->create()->id, 'name' => 'Alheia']);

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [['id' => $foreign->id, 'name' => 'Roubada']],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['leagues.0.id']);
        $this->assertDatabaseHas('leagues', ['id' => $foreign->id, 'name' => 'Alheia']);
    }

    public function test_update_fails_with_league_without_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        $orphan = League::factory()->create(['championship_id' => null]);

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [['id' => $orphan->id, 'name' => 'X']],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['leagues.0.id']);
    }

    public function test_update_fails_with_duplicated_league_id(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id]);

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [['id' => $league->id, 'name' => 'A'], ['id' => $league->id, 'name' => 'B']],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['leagues.0.id']);
    }

    public function test_admin_cannot_create_championship_with_leagues(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/championships', ['name' => 'Novo', 'leagues' => [['name' => 'Série A']]]);

        $response->assertForbidden();
        $this->assertDatabaseCount('leagues', 0);
    }

    public function test_admin_cannot_update_championship_leagues(): void
    {
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id, 'name' => 'Série A']);
        $this->authenticateAsAdmin();

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [['id' => $league->id, 'name' => 'Mudou']],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'name' => 'Série A']);
    }

    public function test_master_can_create_championship_with_image_and_leagues_via_multipart(): void
    {
        Storage::fake('public');
        $this->authenticate();

        $response = $this->post('/api/championships', [
            'name' => 'Copa X',
            'image' => UploadedFile::fake()->image('logo.png'),
            'leagues' => [
                ['name' => 'Série A', 'format' => 'knockout', 'start_date' => '2026-01-10', 'end_date' => '2026-03-10'],
                ['name' => 'Série B', 'format' => '', 'season' => ''],
            ],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonCount(2, 'data.leagues');
        $championship = Championship::first();
        Storage::disk('public')->assertExists($championship->image);
        $this->assertDatabaseHas('leagues', ['name' => 'Série A', 'format' => 'knockout', 'start_date' => '2026-01-10', 'championship_id' => $championship->id]);
        $this->assertDatabaseHas('leagues', ['name' => 'Série B', 'format' => 'round_robin', 'season' => null, 'championship_id' => $championship->id]);
    }

    public function test_master_can_update_championship_with_image_and_leagues_via_multipart_method_spoofing(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $oldPath = UploadedFile::fake()->image('old.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['image' => $oldPath]);
        $league = League::factory()->create(['championship_id' => $championship->id, 'name' => 'Série A']);

        $response = $this->post("/api/championships/{$championship->id}", [
            '_method' => 'PUT',
            'name' => 'Copa Y',
            'image' => UploadedFile::fake()->image('new.jpg'),
            'leagues' => [
                ['id' => (string) $league->id, 'name' => 'Série A 2026'],
                ['name' => 'Série B'],
            ],
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('data.name', 'Copa Y')->assertJsonCount(2, 'data.leagues');
        $championship->refresh();
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($championship->image);
        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'name' => 'Série A 2026']);
        $this->assertDatabaseHas('leagues', ['name' => 'Série B', 'championship_id' => $championship->id]);
    }

    public function test_update_rolls_back_and_keeps_old_image_when_league_save_fails(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $oldPath = UploadedFile::fake()->image('old.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['name' => 'Antigo', 'image' => $oldPath]);
        $this->partialMock(LeagueRepository::class, function ($mock) {
            $mock->shouldReceive('store')->andThrow(new \RuntimeException('falha simulada'));
        });

        $response = $this->post("/api/championships/{$championship->id}", [
            '_method' => 'PUT',
            'name' => 'Novo',
            'image' => UploadedFile::fake()->image('new.jpg'),
            'leagues' => [['name' => 'Série A']],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(500);
        $this->assertDatabaseHas('championships', ['id' => $championship->id, 'name' => 'Antigo', 'image' => $oldPath]);
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('leagues', 0);
    }
}
