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
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class GameControllerTest extends TestCase
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

    private function gamePayload(array $overrides = []): array
    {
        $league = isset($overrides['league_id']) ? League::findOrFail($overrides['league_id']) : League::factory()->create();
        $home = Team::factory()->create();
        $away = Team::factory()->create();
        $league->teams()->attach([$home->id, $away->id]);

        return array_merge([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'scheduled',
        ], $overrides);
    }

    public function test_guest_cannot_list_games(): void
    {
        $response = $this->getJson('/api/games');

        $response->assertStatus(401);
    }

    public function test_master_index_without_league_id_returns_422(): void
    {
        $this->authenticate();
        Game::factory()->count(3)->create();

        $this->getJson('/api/games')
            ->assertStatus(422)
            ->assertJsonValidationErrors('league_id');
    }

    public function test_master_can_create_game(): void
    {
        $this->authenticate();
        $payload = $this->gamePayload(['round' => 1, 'status' => 'scheduled']);

        $response = $this->postJson('/api/games', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.league_id', $payload['league_id'])
            ->assertJsonPath('data.home_team_id', $payload['home_team_id'])
            ->assertJsonPath('data.status', 'scheduled');

        $this->assertDatabaseHas('games', [
            'league_id' => $payload['league_id'],
            'home_team_id' => $payload['home_team_id'],
            'away_team_id' => $payload['away_team_id'],
        ]);
    }

    public function test_master_can_show_game(): void
    {
        $this->authenticate();
        $game = Game::factory()->create();

        $response = $this->getJson("/api/games/{$game->id}");

        $response->assertOk()->assertJsonPath('data.id', $game->id);
    }

    public function test_master_can_update_game(): void
    {
        $this->authenticate();
        $game = Game::factory()->create(['status' => 'scheduled']);

        $response = $this->putJson("/api/games/{$game->id}", [
            'status' => 'finished',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'finished');

        $this->assertDatabaseHas('games', ['id' => $game->id, 'status' => 'finished']);
    }

    public function test_master_can_delete_game(): void
    {
        $this->authenticate();
        $game = Game::factory()->create();

        $response = $this->deleteJson("/api/games/{$game->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('games', ['id' => $game->id]);
    }

    public function test_store_fails_without_required_fields(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/games', []);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_home_and_away_team_are_the_same(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $team = Team::factory()->create();
        $league->teams()->attach($team->id);

        $response = $this->postJson('/api/games', [
            'league_id' => $league->id,
            'home_team_id' => $team->id,
            'away_team_id' => $team->id,
            'status' => 'scheduled',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('away_team_id');
    }

    public function test_store_fails_with_invalid_status(): void
    {
        $this->authenticate();
        $payload = $this->gamePayload(['status' => 'invalid_status']);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_league_does_not_exist(): void
    {
        $this->authenticate();
        $home = Team::factory()->create();
        $away = Team::factory()->create();

        $response = $this->postJson('/api/games', [
            'league_id' => 999,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'scheduled',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_accepts_optional_stadium(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $payload = $this->gamePayload(['league_id' => $league->id, 'stadium_id' => $stadium->id]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertCreated()->assertJsonPath('data.stadium_id', $stadium->id);
    }

    public function test_store_fails_when_stadium_belongs_to_a_different_league(): void
    {
        $this->authenticate();
        $stadium = Stadium::factory()->create();
        $payload = $this->gamePayload(['stadium_id' => $stadium->id]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422);
    }

    public function test_update_fails_when_stadium_belongs_to_a_different_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $game = Game::factory()->create(['league_id' => $league->id]);
        $stadiumFromOtherLeague = Stadium::factory()->create();

        $response = $this->putJson("/api/games/{$game->id}", ['stadium_id' => $stadiumFromOtherLeague->id]);

        $response->assertStatus(422);
    }

    public function test_update_accepts_stadium_from_the_same_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $game = Game::factory()->create(['league_id' => $league->id]);
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);

        $response = $this->putJson("/api/games/{$game->id}", ['stadium_id' => $stadium->id]);

        $response->assertOk()->assertJsonPath('data.stadium_id', $stadium->id);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/games/999');

        $response->assertStatus(422)->assertJson(['message' => 'Partida não encontrada!']);
    }

    public function test_admin_index_without_league_id_returns_422(): void
    {
        $assignedLeague = League::factory()->create();
        Game::factory()->create(['league_id' => $assignedLeague->id]);
        $this->authenticateAsAdmin([$assignedLeague->id]);

        $this->getJson('/api/games')
            ->assertStatus(422)
            ->assertJsonValidationErrors('league_id');
    }

    public function test_admin_with_no_leagues_cannot_list_games_of_any_league(): void
    {
        $league = League::factory()->create();
        Game::factory()->count(2)->create(['league_id' => $league->id]);
        $this->authenticateAsAdmin();

        $this->getJson("/api/games?league_id={$league->id}")
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a essa liga.']);
    }

    public function test_master_can_filter_index_by_league_id(): void
    {
        $this->authenticate();
        $leagueA = League::factory()->create();
        $leagueB = League::factory()->create();
        $gameA = Game::factory()->create(['league_id' => $leagueA->id]);
        Game::factory()->create(['league_id' => $leagueB->id]);

        $response = $this->getJson("/api/games?league_id={$leagueA->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $gameA->id);
    }

    public function test_admin_can_filter_index_by_assigned_league_id(): void
    {
        $leagueA = League::factory()->create();
        $leagueB = League::factory()->create();
        $gameA = Game::factory()->create(['league_id' => $leagueA->id]);
        Game::factory()->create(['league_id' => $leagueB->id]);

        $this->authenticateAsAdmin([$leagueA->id, $leagueB->id]);

        $response = $this->getJson("/api/games?league_id={$leagueA->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $gameA->id);
    }

    public function test_admin_cannot_filter_index_by_unassigned_league_id(): void
    {
        $assignedLeague = League::factory()->create();
        $otherLeague = League::factory()->create();
        $this->authenticateAsAdmin([$assignedLeague->id]);

        $response = $this->getJson("/api/games?league_id={$otherLeague->id}");

        $response->assertStatus(422)->assertJson(['message' => 'Você não tem acesso a essa liga.']);
    }

    public function test_show_returns_nested_home_team_away_team_stadium_and_events(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $home = Team::factory()->create();
        $away = Team::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'stadium_id' => $stadium->id,
        ]);
        $player = \App\Models\Player::factory()->create(['team_id' => $home->id]);
        \App\Models\GameEvent::factory()->create([
            'game_id' => $game->id,
            'league_id' => $league->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
        ]);

        $response = $this->getJson("/api/games/{$game->id}");

        $response->assertOk()
            ->assertJsonPath('data.home_team_id', $home->id)
            ->assertJsonPath('data.homeTeam.id', $home->id)
            ->assertJsonPath('data.awayTeam.id', $away->id)
            ->assertJsonPath('data.stadium.id', $stadium->id)
            ->assertJsonCount(1, 'data.events');
    }

    private function createEventsAtMinutes(Game $game, array $minutes): array
    {
        $player = Player::factory()->create(['team_id' => $game->home_team_id]);

        return array_map(fn (?int $minute) => GameEvent::factory()->create([
            'game_id' => $game->id,
            'league_id' => $game->league_id,
            'team_id' => $game->home_team_id,
            'player_id' => $player->id,
            'minute' => $minute,
        ]), $minutes);
    }

    public function test_show_returns_events_ordered_by_minute_desc_with_null_last(): void
    {
        $this->authenticate();
        $game = Game::factory()->create();
        $this->createEventsAtMinutes($game, [15, null, 18, 10]);

        $response = $this->getJson("/api/games/{$game->id}")->assertOk();

        $this->assertSame([18, 15, 10, null], array_column($response->json('data.events'), 'minute'));
    }

    public function test_show_breaks_minute_ties_by_latest_event_first(): void
    {
        $this->authenticate();
        $game = Game::factory()->create();
        [$first, $second, $third] = $this->createEventsAtMinutes($game, [20, 20, 5]);

        $response = $this->getJson("/api/games/{$game->id}")->assertOk();

        $this->assertSame([$second->id, $first->id, $third->id], array_column($response->json('data.events'), 'id'));
    }

    public function test_update_response_returns_events_ordered_by_minute_desc_with_null_last(): void
    {
        $this->authenticate();
        $game = Game::factory()->create();
        $this->createEventsAtMinutes($game, [15, null, 18, 10]);

        $response = $this->putJson("/api/games/{$game->id}", ['match_date' => '2026-12-01 16:00:00'])->assertOk();

        $this->assertSame([18, 15, 10, null], array_column($response->json('data.events'), 'minute'));
    }

    public function test_public_show_returns_events_ordered_by_minute_desc_with_null_last(): void
    {
        $game = Game::factory()->create();
        $this->createEventsAtMinutes($game, [15, null, 18, 10]);

        $response = $this->getJson("/api/public/games/{$game->id}")->assertOk();

        $this->assertSame([18, 15, 10, null], array_column($response->json('data.events'), 'minute'));
    }

    public function test_show_returns_rosters_of_both_teams_without_removed_players(): void
    {
        $this->authenticate();
        $game = Game::factory()->create();
        $homePlayer = Player::factory()->create(['team_id' => $game->home_team_id, 'name' => 'Mandante', 'position' => 'ATA', 'number' => 9]);
        $awayPlayer = Player::factory()->create(['team_id' => $game->away_team_id]);
        Player::factory()->create(['team_id' => $game->home_team_id])->delete();
        Player::factory()->create();

        $response = $this->getJson("/api/games/{$game->id}")->assertOk();

        $response->assertJsonCount(1, 'data.homeTeam.players')
            ->assertJsonCount(1, 'data.awayTeam.players')
            ->assertJsonPath('data.awayTeam.players.0.id', $awayPlayer->id)
            ->assertJsonPath('data.homeTeam.players.0.id', $homePlayer->id)
            ->assertJsonPath('data.homeTeam.players.0.team_id', $game->home_team_id)
            ->assertJsonPath('data.homeTeam.players.0.name', 'Mandante')
            ->assertJsonPath('data.homeTeam.players.0.position', 'ATA')
            ->assertJsonPath('data.homeTeam.players.0.number', 9)
            ->assertJsonPath('data.homeTeam.players.0.document', $homePlayer->document);
        $this->assertSame(
            ['id', 'team_id', 'name', 'position', 'number', 'birth_date', 'document', 'created_at', 'updated_at'],
            array_keys($response->json('data.homeTeam.players.0'))
        );
        $this->assertSame(
            $this->getJson("/api/teams/{$game->home_team_id}")->json('data.players'),
            $response->json('data.homeTeam.players')
        );
    }

    public function test_update_response_includes_rosters_of_the_new_teams(): void
    {
        $this->authenticate();
        $game = Game::factory()->create(['status' => 'scheduled']);
        $newAway = Team::factory()->create();
        $game->league->teams()->attach([$game->home_team_id, $newAway->id]);
        $player = Player::factory()->create(['team_id' => $newAway->id]);

        $this->putJson("/api/games/{$game->id}", ['away_team_id' => $newAway->id])
            ->assertOk()
            ->assertJsonPath('data.awayTeam.id', $newAway->id)
            ->assertJsonPath('data.awayTeam.players.0.id', $player->id);
    }

    public function test_show_loads_rosters_with_fixed_number_of_queries(): void
    {
        $user = $this->authenticate();
        $countPlayerQueries = function (Game $game) use ($user): int {
            Sanctum::actingAs($user->fresh());
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/games/{$game->id}")->assertOk();
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            return count(array_filter($log, fn ($q) => str_contains($q['query'], 'from "players"')));
        };

        $small = Game::factory()->create();
        Player::factory()->create(['team_id' => $small->home_team_id]);
        $large = Game::factory()->create();
        Player::factory()->count(15)->create(['team_id' => $large->home_team_id]);
        Player::factory()->count(15)->create(['team_id' => $large->away_team_id]);

        $this->assertSame(2, $countPlayerQueries($small));
        $this->assertSame(2, $countPlayerQueries($large));
    }

    public function test_public_show_does_not_include_rosters(): void
    {
        $game = Game::factory()->create();
        Player::factory()->create(['team_id' => $game->home_team_id]);

        $response = $this->getJson("/api/public/games/{$game->id}")->assertOk();

        $response->assertJsonPath('data.homeTeam.id', $game->home_team_id);
        $this->assertArrayNotHasKey('players', $response->json('data.homeTeam'));
        $this->assertArrayNotHasKey('players', $response->json('data.awayTeam'));
    }

    public function test_show_returns_league_with_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create(['name' => 'Campeonato Brasileiro']);
        $league = League::factory()->create(['name' => 'Série A 2026', 'championship_id' => $championship->id]);
        $game = Game::factory()->create(['league_id' => $league->id]);

        $this->getJson("/api/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.league', [
                'id' => $league->id,
                'name' => 'Série A 2026',
                'championship' => ['id' => $championship->id, 'name' => 'Campeonato Brasileiro'],
            ]);
    }

    public function test_show_returns_league_without_championship_as_null(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['name' => 'Liga antiga', 'championship_id' => null]);
        $game = Game::factory()->create(['league_id' => $league->id]);

        $this->getJson("/api/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.league', ['id' => $league->id, 'name' => 'Liga antiga', 'championship' => null]);
    }

    public function test_show_returns_only_head_coaches_of_each_team(): void
    {
        $this->authenticate();
        $game = Game::factory()->create();
        TechnicalCommitteeMember::factory()->create(['team_id' => $game->home_team_id, 'role' => 'Auxiliar Técnico']);
        $headCoach = TechnicalCommitteeMember::factory()->create(['team_id' => $game->home_team_id, 'role' => 'Treinador', 'name' => 'Carlos Lima']);
        $secondCoach = TechnicalCommitteeMember::factory()->create(['team_id' => $game->home_team_id, 'role' => 'Treinador']);
        TechnicalCommitteeMember::factory()->create(['team_id' => $game->away_team_id, 'role' => 'Preparador Físico']);
        TechnicalCommitteeMember::factory()->create(['role' => 'Treinador', 'team_id' => Team::factory()->create()->id]);

        $response = $this->getJson("/api/games/{$game->id}")->assertOk();

        $this->assertSame([$headCoach->id, $secondCoach->id], array_column($response->json('data.homeTeam.technicalCommitteeMembers'), 'id'));
        $response->assertJsonPath('data.homeTeam.technicalCommitteeMembers.0.name', 'Carlos Lima')
            ->assertJsonPath('data.homeTeam.technicalCommitteeMembers.0.role', 'Treinador')
            ->assertJsonPath('data.homeTeam.technicalCommitteeMembers.0.team_id', $game->home_team_id)
            ->assertJsonPath('data.awayTeam.technicalCommitteeMembers', []);
    }

    public function test_update_response_includes_league_and_head_coaches_of_the_new_team(): void
    {
        $this->authenticate();
        $game = Game::factory()->create(['status' => 'scheduled']);
        $newAway = Team::factory()->create();
        $game->league->teams()->attach([$game->home_team_id, $newAway->id]);
        $coach = TechnicalCommitteeMember::factory()->create(['team_id' => $newAway->id, 'role' => 'Treinador']);

        $this->putJson("/api/games/{$game->id}", ['away_team_id' => $newAway->id])
            ->assertOk()
            ->assertJsonPath('data.league.id', $game->league_id)
            ->assertJsonPath('data.awayTeam.technicalCommitteeMembers.0.id', $coach->id);
    }

    public function test_show_loads_league_and_head_coaches_with_fixed_number_of_queries(): void
    {
        $user = $this->authenticate();
        $countQueries = function (Game $game) use ($user): int {
            Sanctum::actingAs($user->fresh());
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/games/{$game->id}")->assertOk();
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            return count(array_filter($log, fn ($q) => str_contains($q['query'], 'from "technical_committee_members"') || str_contains($q['query'], 'from "championships"')));
        };

        $small = Game::factory()->create(['league_id' => League::factory()->create(['championship_id' => Championship::factory()->create()->id])->id]);
        TechnicalCommitteeMember::factory()->create(['team_id' => $small->home_team_id, 'role' => 'Treinador']);
        $large = Game::factory()->create(['league_id' => League::factory()->create(['championship_id' => Championship::factory()->create()->id])->id]);
        TechnicalCommitteeMember::factory()->count(5)->create(['team_id' => $large->home_team_id, 'role' => 'Treinador']);
        TechnicalCommitteeMember::factory()->count(5)->create(['team_id' => $large->away_team_id, 'role' => 'Treinador']);

        $this->assertSame(3, $countQueries($small));
        $this->assertSame(3, $countQueries($large));
    }

    public function test_public_show_and_index_do_not_include_league_nor_coaches(): void
    {
        $this->authenticate();
        $game = Game::factory()->create();
        TechnicalCommitteeMember::factory()->create(['team_id' => $game->home_team_id, 'role' => 'Treinador']);

        $public = $this->getJson("/api/public/games/{$game->id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('league', $public);
        $this->assertArrayNotHasKey('technicalCommitteeMembers', $public['homeTeam']);

        $listed = $this->getJson("/api/games?league_id={$game->league_id}")->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('league', $listed);
        $this->assertArrayNotHasKey('technicalCommitteeMembers', $listed['homeTeam']);
    }

    public function test_index_includes_teams_and_stadium_but_not_events(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $game = Game::factory()->create(['league_id' => $league->id, 'stadium_id' => $stadium->id]);

        $response = $this->getJson("/api/games?league_id={$league->id}");

        $response->assertOk()
            ->assertJsonPath('data.0.homeTeam.id', $game->home_team_id)
            ->assertJsonPath('data.0.awayTeam.id', $game->away_team_id)
            ->assertJsonPath('data.0.stadium.id', $stadium->id);
        $this->assertArrayNotHasKey('events', $response->json('data.0'));
    }

    public function test_admin_can_show_game_in_assigned_league(): void
    {
        $league = League::factory()->create();
        $home = Team::factory()->create();
        $away = Team::factory()->create();
        $game = Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id]);

        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/games/{$game->id}");

        $response->assertOk()->assertJsonPath('data.id', $game->id);
    }

    public function test_admin_cannot_show_game_outside_assignments(): void
    {
        $game = Game::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/games/{$game->id}");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_cannot_delete_game_outside_assignments(): void
    {
        $game = Game::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->deleteJson("/api/games/{$game->id}");

        $response->assertForbidden();
    }

    public function test_destroying_league_cascades_to_game(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $game = Game::factory()->create(['league_id' => $league->id]);

        $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'password'])->assertStatus(204);

        $this->assertDatabaseMissing('games', ['id' => $game->id]);
    }

    public function test_deleting_stadium_with_games_via_api_is_blocked_and_keeps_game_stadium_id(): void
    {
        $this->authenticate();
        $stadium = Stadium::factory()->create();
        $game = Game::factory()->create(['stadium_id' => $stadium->id]);

        $this->deleteJson("/api/stadiums/{$stadium->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Não é possível remover o estádio pois existem partidas vinculadas a ele.');

        $this->assertDatabaseHas('games', ['id' => $game->id, 'stadium_id' => $stadium->id]);
    }

    public function test_stadium_fk_on_games_is_null_on_delete(): void
    {
        $stadium = Stadium::factory()->create();
        $game = Game::factory()->create(['stadium_id' => $stadium->id]);

        $stadium->delete();

        $this->assertDatabaseHas('games', ['id' => $game->id, 'stadium_id' => null]);
    }

    public function test_store_accepts_round_for_round_robin_league(): void
    {
        $this->authenticate();
        $payload = $this->gamePayload(['round' => 3]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.round', 3)
            ->assertJsonPath('data.phase', null);
    }

    public function test_store_accepts_phase_tie_key_and_leg_for_knockout_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        $payload = $this->gamePayload([
            'league_id' => $league->id,
            'phase' => 'Semifinal',
            'phase_order' => 3,
            'tie_key' => 'semi-1',
            'leg' => 1,
        ]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.round', null)
            ->assertJsonPath('data.phase', 'Semifinal')
            ->assertJsonPath('data.tie_key', 'semi-1')
            ->assertJsonPath('data.leg', 1);

        $this->assertDatabaseHas('games', [
            'league_id' => $league->id,
            'phase' => 'Semifinal',
            'tie_key' => 'semi-1',
            'leg' => 1,
            'round' => null,
        ]);
    }

    public function test_store_fails_when_leg_is_sent_without_tie_key(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        $payload = $this->gamePayload([
            'league_id' => $league->id,
            'phase' => 'Semifinal',
            'phase_order' => 1,
            'leg' => 1,
        ]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tie_key'])
            ->assertJsonPath('errors.tie_key.0', 'Informe a chave do confronto para partidas de ida e volta.');
    }

    public function test_update_fails_when_leg_is_sent_for_game_without_tie_key(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'round' => null,
            'phase' => 'Final',
            'phase_order' => 1,
            'tie_key' => null,
            'leg' => null,
        ]);

        $response = $this->putJson("/api/games/{$game->id}", ['leg' => 1]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tie_key'])
            ->assertJsonPath('errors.tie_key.0', 'Informe a chave do confronto para partidas de ida e volta.');
        $this->assertDatabaseHas('games', ['id' => $game->id, 'leg' => null]);
    }

    public function test_update_accepts_only_leg_when_game_already_has_tie_key(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'round' => null,
            'phase' => 'Final',
            'phase_order' => 1,
            'tie_key' => 'final-1',
            'leg' => 1,
        ]);

        $response = $this->putJson("/api/games/{$game->id}", ['leg' => 2]);

        $response->assertOk()
            ->assertJsonPath('data.leg', 2)
            ->assertJsonPath('data.tie_key', 'final-1');
    }

    public function test_store_fails_when_round_and_phase_are_sent_together(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'hybrid']);
        $payload = $this->gamePayload(['league_id' => $league->id, 'round' => 1, 'phase' => 'Final']);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_knockout_league_game_informs_round_instead_of_phase(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        $payload = $this->gamePayload(['league_id' => $league->id, 'round' => 1]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_knockout_league_game_informs_neither_round_nor_phase(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        $payload = $this->gamePayload(['league_id' => $league->id]);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422);
    }

    public function test_store_fails_when_round_robin_league_game_informs_phase(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        $payload = $this->gamePayload(['league_id' => $league->id, 'phase' => 'Final']);

        $response = $this->postJson('/api/games', $payload);

        $response->assertStatus(422);
    }

    public function test_update_league_from_round_robin_to_hybrid_does_not_break_existing_games_with_round(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        $game = Game::factory()->create(['league_id' => $league->id, 'round' => 5]);

        $this->putJson("/api/leagues/{$league->id}", ['format' => 'hybrid'])->assertOk();

        $response = $this->putJson("/api/games/{$game->id}", ['status' => 'finished']);

        $response->assertOk()->assertJsonPath('data.round', 5);
    }

    public function test_guest_can_show_game_via_public_route_with_related_data(): void
    {
        $league = League::factory()->create();
        $home = Team::factory()->create();
        $away = Team::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'stadium_id' => $stadium->id,
        ]);
        $player = \App\Models\Player::factory()->create(['team_id' => $home->id]);
        \App\Models\GameEvent::factory()->create([
            'game_id' => $game->id,
            'league_id' => $league->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
        ]);

        $response = $this->getJson("/api/public/games/{$game->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $game->id)
            ->assertJsonPath('data.homeTeam.id', $home->id)
            ->assertJsonPath('data.awayTeam.id', $away->id)
            ->assertJsonPath('data.stadium.id', $stadium->id)
            ->assertJsonCount(1, 'data.events');
    }

    public function test_guest_show_via_public_route_returns_error_when_not_found(): void
    {
        $response = $this->getJson('/api/public/games/999');

        $response->assertStatus(422)->assertJson(['message' => 'Partida não encontrada!']);
    }

    public function test_index_without_pagination_params_returns_first_page_with_default_meta(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        Game::factory()->count(25)->create(['league_id' => $league->id]);

        $this->getJson("/api/games?league_id={$league->id}")
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_index_paginates_45_games_in_pages_of_20(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        Game::factory()->count(45)->create(['league_id' => $league->id]);

        $ids = [];
        foreach ([1 => 20, 2 => 20, 3 => 5] as $page => $expected) {
            $response = $this->getJson("/api/games?league_id={$league->id}&page={$page}");

            $response->assertOk()
                ->assertJsonCount($expected, 'data')
                ->assertJsonPath('meta.current_page', $page)
                ->assertJsonPath('meta.per_page', 20)
                ->assertJsonPath('meta.total', 45)
                ->assertJsonPath('meta.last_page', 3);

            $ids = array_merge($ids, array_column($response->json('data'), 'id'));
        }

        $this->assertCount(45, $ids);
        $this->assertCount(45, array_unique($ids));
    }

    public function test_index_per_page_alone_defaults_to_first_page(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        Game::factory()->count(12)->create(['league_id' => $league->id]);

        $this->getJson("/api/games?league_id={$league->id}&per_page=5")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_index_page_beyond_last_returns_empty_data_with_consistent_meta(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        Game::factory()->count(45)->create(['league_id' => $league->id]);

        $this->getJson("/api/games?league_id={$league->id}&page=99")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 99)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 45)
            ->assertJsonPath('meta.last_page', 3);
    }

    private function seedFilterScenario(): array
    {
        $league = League::factory()->create();
        $team = Team::factory()->create();

        $homeR1 = Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $team->id, 'round' => 1, 'phase' => 'Grupos', 'status' => 'finished']);
        $awayR1 = Game::factory()->create(['league_id' => $league->id, 'away_team_id' => $team->id, 'round' => 1, 'phase' => 'Grupos', 'status' => 'scheduled']);
        $otherR1 = Game::factory()->create(['league_id' => $league->id, 'round' => 1, 'phase' => 'Grupos', 'status' => 'finished']);
        $awayR2 = Game::factory()->create(['league_id' => $league->id, 'away_team_id' => $team->id, 'round' => 2, 'phase' => 'Final', 'status' => 'finished']);
        $otherR2 = Game::factory()->create(['league_id' => $league->id, 'round' => 2, 'phase' => 'Final', 'status' => 'cancelled']);
        Game::factory()->create(['home_team_id' => $team->id, 'round' => 1, 'phase' => 'Grupos', 'status' => 'scheduled']);

        return compact('league', 'team', 'homeR1', 'awayR1', 'otherR1', 'awayR2', 'otherR2');
    }

    private function assertGameIds(array $expected, $response): void
    {
        $ids = array_column($response->json('data'), 'id');
        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public static function paginationModeProvider(): array
    {
        return [
            'paginação padrão' => ['', 20],
            'paginação explícita' => ['&page=1&per_page=10', 10],
        ];
    }

    #[DataProvider('paginationModeProvider')]
    public function test_index_filters_isolated_and_combined(string $suffix, int $perPage): void
    {
        $this->authenticate();
        $s = $this->seedFilterScenario();
        $prefix = "league_id={$s['league']->id}";

        $cases = [
            '' => [$s['homeR1'], $s['awayR1'], $s['otherR1'], $s['awayR2'], $s['otherR2']],
            '&round=1' => [$s['homeR1'], $s['awayR1'], $s['otherR1']],
            '&phase=Final' => [$s['awayR2'], $s['otherR2']],
            '&status=finished' => [$s['homeR1'], $s['otherR1'], $s['awayR2']],
            "&team_id={$s['team']->id}" => [$s['homeR1'], $s['awayR1'], $s['awayR2']],
            "&team_id={$s['team']->id}&round=1" => [$s['homeR1'], $s['awayR1']],
            "&team_id={$s['team']->id}&status=finished" => [$s['homeR1'], $s['awayR2']],
            '&round=2&phase=Final&status=cancelled' => [$s['otherR2']],
            "&team_id={$s['team']->id}&phase=Grupos&status=scheduled" => [$s['awayR1']],
        ];

        foreach ($cases as $query => $games) {
            $response = $this->getJson("/api/games?{$prefix}{$query}{$suffix}");

            $response->assertOk()
                ->assertJsonPath('meta.total', count($games))
                ->assertJsonPath('meta.per_page', $perPage);
            $this->assertGameIds(array_map(fn ($g) => $g->id, $games), $response);
        }
    }

    public function test_index_team_id_filter_does_not_leak_other_leagues(): void
    {
        $league = League::factory()->create();
        $otherLeague = League::factory()->create();
        $team = Team::factory()->create();
        $mine = Game::factory()->create(['league_id' => $league->id, 'away_team_id' => $team->id]);
        Game::factory()->create(['league_id' => $otherLeague->id, 'home_team_id' => $team->id]);
        $this->authenticateAsAdmin([$league->id, $otherLeague->id]);

        $response = $this->getJson("/api/games?league_id={$league->id}&team_id={$team->id}&per_page=10");

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertGameIds([$mine->id], $response);
    }

    public function test_index_filters_by_phase_zero(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $phaseZero = Game::factory()->create(['league_id' => $league->id, 'phase' => '0']);
        Game::factory()->create(['league_id' => $league->id, 'phase' => 'Final']);

        $response = $this->getJson("/api/games?league_id={$league->id}&phase=0");

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertGameIds([$phaseZero->id], $response);
    }

    public static function invalidIndexQueryProvider(): array
    {
        return [
            'per_page zero' => ['per_page=0', 'per_page'],
            'per_page acima do máximo' => ['per_page=500', 'per_page'],
            'page texto' => ['page=abc', 'page'],
            'page zero' => ['page=0', 'page'],
            'team_id texto' => ['team_id=abc', 'team_id'],
            'team_id sufixo não numérico' => ['team_id=1abc', 'team_id'],
            'status inválido' => ['status=xpto', 'status'],
            'round zero' => ['round=0', 'round'],
        ];
    }

    #[DataProvider('invalidIndexQueryProvider')]
    public function test_index_invalid_filter_or_pagination_returns_422(string $query, string $field): void
    {
        $this->authenticate();

        $this->getJson("/api/games?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public function test_admin_cannot_filter_paginated_index_by_unassigned_league_id(): void
    {
        $assignedLeague = League::factory()->create();
        $otherLeague = League::factory()->create();
        $this->authenticateAsAdmin([$assignedLeague->id]);

        $this->getJson("/api/games?league_id={$otherLeague->id}&page=1")
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a essa liga.']);
    }

    public function test_index_orders_by_round_phase_order_match_date_and_id_across_pages(): void
    {
        $league = League::factory()->create();
        $games = Game::factory()->count(25)
            ->sequence(fn ($s) => [
                'round' => $s->index % 4 + 1,
                'match_date' => now()->startOfDay()->addDays(intdiv(25 - $s->index, 2))->format('Y-m-d H:i:s'),
            ])
            ->create(['league_id' => $league->id]);
        Game::factory()->count(3)->create();
        $this->authenticateAsAdmin([$league->id]);

        $items = [];
        foreach ([1 => 10, 2 => 10, 3 => 5] as $page => $expected) {
            $response = $this->getJson("/api/games?league_id={$league->id}&page={$page}&per_page=10");

            $response->assertOk()
                ->assertJsonCount($expected, 'data')
                ->assertJsonPath('meta.total', 25)
                ->assertJsonPath('meta.last_page', 3);

            $items = array_merge($items, $response->json('data'));
        }

        $expectedIds = $games
            ->sortBy([['round', 'asc'], ['match_date', 'asc'], ['id', 'asc']])
            ->pluck('id')
            ->values()
            ->all();

        $this->assertSame($expectedIds, array_column($items, 'id'));
    }

    private function listGameIdsAcrossPages(int $leagueId, int $perPage, int $lastPage): array
    {
        $ids = [];
        for ($page = 1; $page <= $lastPage; $page++) {
            $response = $this->getJson("/api/games?league_id={$leagueId}&page={$page}&per_page={$perPage}");
            $response->assertOk()->assertJsonPath('meta.last_page', $lastPage);
            $ids = array_merge($ids, array_column($response->json('data'), 'id'));
        }

        return $ids;
    }

    public function test_index_orders_hybrid_league_rounds_before_knockout_phases_across_pages(): void
    {
        $league = League::factory()->create(['format' => 'hybrid']);
        $at = fn (int $days) => now()->startOfDay()->addDays($days)->format('Y-m-d H:i:s');
        $final = Game::factory()->create(['league_id' => $league->id, 'round' => null, 'phase' => 'Final', 'phase_order' => 2, 'match_date' => $at(1)]);
        $round2 = Game::factory()->create(['league_id' => $league->id, 'round' => 2, 'match_date' => $at(20)]);
        $semiB = Game::factory()->create(['league_id' => $league->id, 'round' => null, 'phase' => 'Semifinal', 'phase_order' => 1, 'match_date' => $at(3)]);
        $round1B = Game::factory()->create(['league_id' => $league->id, 'round' => 1, 'match_date' => $at(11)]);
        $semiA = Game::factory()->create(['league_id' => $league->id, 'round' => null, 'phase' => 'Semifinal', 'phase_order' => 1, 'match_date' => $at(2)]);
        $round1A = Game::factory()->create(['league_id' => $league->id, 'round' => 1, 'match_date' => $at(10)]);
        $this->authenticateAsAdmin([$league->id]);

        $ids = $this->listGameIdsAcrossPages($league->id, 2, 3);

        $this->assertSame(
            [$round1A->id, $round1B->id, $round2->id, $semiA->id, $semiB->id, $final->id],
            $ids
        );
    }

    public function test_index_orders_knockout_league_by_phase_order_with_null_phase_order_last(): void
    {
        $league = League::factory()->create(['format' => 'knockout']);
        $at = fn (int $days) => now()->startOfDay()->addDays($days)->format('Y-m-d H:i:s');
        $noOrder = Game::factory()->create(['league_id' => $league->id, 'round' => null, 'phase' => 'Amistoso', 'phase_order' => null, 'match_date' => $at(1)]);
        $final = Game::factory()->create(['league_id' => $league->id, 'round' => null, 'phase' => 'Final', 'phase_order' => 2, 'match_date' => $at(2)]);
        $semiB = Game::factory()->create(['league_id' => $league->id, 'round' => null, 'phase' => 'Semifinal', 'phase_order' => 1, 'match_date' => $at(5)]);
        $semiA = Game::factory()->create(['league_id' => $league->id, 'round' => null, 'phase' => 'Semifinal', 'phase_order' => 1, 'match_date' => $at(4)]);
        $this->authenticateAsAdmin([$league->id]);

        $ids = $this->listGameIdsAcrossPages($league->id, 3, 2);

        $this->assertSame([$semiA->id, $semiB->id, $final->id, $noOrder->id], $ids);
    }

    public function test_paginated_index_runs_constant_number_of_queries(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        Game::factory()->count(30)->create(['league_id' => $league->id, 'round' => 1, 'stadium_id' => $stadium->id]);

        DB::enableQueryLog();
        $this->getJson("/api/games?league_id={$league->id}&round=1&per_page=20")->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, array_filter($log, fn ($q) => str_contains($q['query'], 'from "games"')));
        $this->assertCount(2, array_filter($log, fn ($q) => str_contains($q['query'], 'from "teams"')));
        $this->assertCount(1, array_filter($log, fn ($q) => str_contains($q['query'], 'from "stadiums"')));
    }

    public function test_paginated_index_beyond_last_page_does_not_load_relations(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        Game::factory()->count(5)->create(['league_id' => $league->id]);

        DB::enableQueryLog();
        $this->getJson("/api/games?league_id={$league->id}&page=3&per_page=5")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 5);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, array_filter($log, fn ($q) => str_contains($q['query'], 'from "games"')));
        $this->assertCount(0, array_filter($log, fn ($q) => str_contains($q['query'], 'from "teams"')));
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

        $this->getJson("/api/games?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('league_id');
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $this->authenticate();

        $this->getJson('/api/games/1abc')->assertNotFound();
    }

    public static function rolesWithoutGamePermissionsProvider(): array
    {
        return ['coach' => ['coach'], 'player' => ['player']];
    }

    #[DataProvider('rolesWithoutGamePermissionsProvider')]
    public function test_role_without_game_permissions_cannot_write_games(string $role): void
    {
        $payload = $this->gamePayload(['round' => 1]);
        $game = Game::factory()->create(['league_id' => $payload['league_id'], 'status' => 'scheduled']);
        $this->authenticateAs($role, [$payload['league_id']]);

        $this->postJson('/api/games', $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->putJson("/api/games/{$game->id}", ['status' => 'finished'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->deleteJson("/api/games/{$game->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');

        $this->assertDatabaseCount('games', 1);
        $this->assertDatabaseHas('games', ['id' => $game->id, 'status' => 'scheduled']);
    }

    public function test_admin_can_create_update_and_delete_game_in_assigned_league(): void
    {
        $payload = $this->gamePayload(['round' => 1]);
        $this->authenticateAsAdmin([$payload['league_id']]);

        $id = $this->postJson('/api/games', $payload)->assertCreated()->json('data.id');
        $this->putJson("/api/games/{$id}", ['status' => 'finished'])
            ->assertOk()
            ->assertJsonPath('data.status', 'finished');
        $this->deleteJson("/api/games/{$id}")->assertStatus(204);

        $this->assertDatabaseMissing('games', ['id' => $id]);
    }

    public function test_admin_outside_league_is_blocked_by_resource_access_not_by_permission(): void
    {
        $game = Game::factory()->create();
        $this->authenticateAsAdmin([League::factory()->create()->id]);

        $this->putJson("/api/games/{$game->id}", ['status' => 'finished'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    private function gameWithTeamsInLeague(League $league, array $attributes = []): Game
    {
        $home = Team::factory()->create();
        $away = Team::factory()->create();
        $league->teams()->attach([$home->id, $away->id]);

        return Game::factory()->create(array_merge([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'round' => 1,
        ], $attributes));
    }

    public function test_admin_cannot_create_game_in_unassigned_league(): void
    {
        $payload = $this->gamePayload(['round' => 1]);
        $this->authenticateAsAdmin([League::factory()->create()->id]);

        $this->postJson('/api/games', $payload)
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);

        $this->assertDatabaseCount('games', 0);
    }

    public function test_admin_cannot_move_game_to_unassigned_league(): void
    {
        $league = League::factory()->create();
        $otherLeague = League::factory()->create();
        $game = $this->gameWithTeamsInLeague($league);
        $otherLeague->teams()->attach([$game->home_team_id, $game->away_team_id]);
        $this->authenticateAsAdmin([$league->id]);

        $this->putJson("/api/games/{$game->id}", ['league_id' => $otherLeague->id])
            ->assertStatus(422)
            ->assertJson(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);

        $this->assertDatabaseHas('games', ['id' => $game->id, 'league_id' => $league->id]);
    }

    public function test_store_fails_when_teams_do_not_belong_to_league_even_for_master(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $home = Team::factory()->create();
        $away = Team::factory()->create();
        $league->teams()->attach($home->id);

        $this->postJson('/api/games', [
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'round' => 1,
            'status' => 'scheduled',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['away_team_id' => 'O time informado não pertence à liga desta partida.'])
            ->assertJsonMissingValidationErrors('home_team_id');

        $this->assertDatabaseCount('games', 0);
    }

    public function test_update_only_league_id_fails_when_new_league_does_not_have_the_teams(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $otherLeague = League::factory()->create();
        $game = $this->gameWithTeamsInLeague($league);

        $this->putJson("/api/games/{$game->id}", ['league_id' => $otherLeague->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['home_team_id', 'away_team_id']);

        $this->assertDatabaseHas('games', ['id' => $game->id, 'league_id' => $league->id]);
    }

    public function test_update_only_home_team_id_fails_when_team_is_outside_the_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $game = $this->gameWithTeamsInLeague($league);
        $outsider = Team::factory()->create();

        $this->putJson("/api/games/{$game->id}", ['home_team_id' => $outsider->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['home_team_id' => 'O time informado não pertence à liga desta partida.'])
            ->assertJsonMissingValidationErrors('away_team_id');

        $this->assertDatabaseHas('games', ['id' => $game->id, 'home_team_id' => $game->home_team_id]);
    }

    public function test_update_only_away_team_id_equal_to_stored_home_team_fails(): void
    {
        $this->authenticate();
        $game = $this->gameWithTeamsInLeague(League::factory()->create());

        $this->putJson("/api/games/{$game->id}", ['away_team_id' => $game->home_team_id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['away_team_id' => 'O time mandante e o visitante devem ser diferentes.']);

        $this->assertDatabaseHas('games', ['id' => $game->id, 'away_team_id' => $game->away_team_id]);
    }

    public function test_update_only_status_does_not_check_teams(): void
    {
        $this->authenticateAsAdmin([($league = League::factory()->create())->id]);
        $game = Game::factory()->create(['league_id' => $league->id, 'status' => 'scheduled']);

        $this->putJson("/api/games/{$game->id}", ['status' => 'finished'])
            ->assertOk()
            ->assertJsonPath('data.status', 'finished');
    }

    public function test_master_can_create_and_move_game_when_teams_belong_to_the_league(): void
    {
        $this->authenticate();
        $payload = $this->gamePayload(['round' => 1]);
        $otherLeague = League::factory()->create();
        $otherLeague->teams()->attach([$payload['home_team_id'], $payload['away_team_id']]);

        $id = $this->postJson('/api/games', $payload)->assertCreated()->json('data.id');

        $this->putJson("/api/games/{$id}", ['league_id' => $otherLeague->id])
            ->assertOk()
            ->assertJsonPath('data.league_id', $otherLeague->id);
    }

    public function test_update_swapping_to_another_team_of_the_same_league_is_accepted(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $game = $this->gameWithTeamsInLeague($league);
        $newAway = Team::factory()->create();
        $league->teams()->attach($newAway->id);

        $this->putJson("/api/games/{$game->id}", ['away_team_id' => $newAway->id])
            ->assertOk()
            ->assertJsonPath('data.away_team_id', $newAway->id);
    }

    public static function arrayLeagueIdWriteProvider(): array
    {
        return ['store' => ['post'], 'update' => ['put']];
    }

    #[DataProvider('arrayLeagueIdWriteProvider')]
    public function test_league_id_sent_as_array_returns_422_instead_of_500(string $method): void
    {
        $this->authenticate();
        $game = $this->gameWithTeamsInLeague(League::factory()->create());
        $url = $method === 'post' ? '/api/games' : "/api/games/{$game->id}";

        $this->json($method, $url, [
            'league_id' => [$game->league_id],
            'home_team_id' => $game->home_team_id,
            'away_team_id' => $game->away_team_id,
            'status' => 'scheduled',
        ])->assertStatus(422)->assertJsonValidationErrors('league_id');
    }

    private function gameWithEventsMovableTo(League $newLeague): array
    {
        $game = $this->gameWithTeamsInLeague(League::factory()->create(), [
            'status' => 'finished',
            'home_score' => 2,
            'away_score' => 0,
        ]);
        $newLeague->teams()->attach([$game->home_team_id, $game->away_team_id]);
        $scorer = Player::factory()->create(['team_id' => $game->home_team_id]);
        GameEvent::factory()->count(2)->create([
            'game_id' => $game->id,
            'league_id' => $game->league_id,
            'team_id' => $game->home_team_id,
            'player_id' => $scorer->id,
            'related_player_id' => null,
            'type' => 'goal',
        ]);

        return [$game, $scorer];
    }

    public function test_update_changing_league_moves_game_events_to_the_new_league(): void
    {
        $this->authenticate();
        $newLeague = League::factory()->create();
        [$game] = $this->gameWithEventsMovableTo($newLeague);

        $response = $this->putJson("/api/games/{$game->id}", ['league_id' => $newLeague->id])
            ->assertOk()
            ->assertJsonPath('data.league_id', $newLeague->id)
            ->assertJsonCount(2, 'data.events');

        foreach ($response->json('data.events') as $event) {
            $this->assertSame($newLeague->id, $event['league_id']);
        }
        $this->assertSame(2, GameEvent::where('game_id', $game->id)->where('league_id', $newLeague->id)->count());
        $this->assertSame(0, GameEvent::where('league_id', '!=', $newLeague->id)->count());
    }

    public function test_update_without_changing_league_keeps_game_events_untouched(): void
    {
        $this->authenticate();
        [$game] = $this->gameWithEventsMovableTo(League::factory()->create());
        $originalLeagueId = $game->league_id;

        $this->putJson("/api/games/{$game->id}", ['league_id' => $originalLeagueId, 'match_date' => '2026-12-01 16:00:00'])
            ->assertOk()
            ->assertJsonPath('data.events.0.league_id', $originalLeagueId);

        $this->assertSame(2, GameEvent::where('game_id', $game->id)->where('league_id', $originalLeagueId)->count());
    }

    public function test_after_changing_league_top_scorers_count_the_goal_only_in_the_new_league(): void
    {
        $this->authenticate();
        $newLeague = League::factory()->create();
        [$game, $scorer] = $this->gameWithEventsMovableTo($newLeague);
        $oldLeagueId = $game->league_id;

        $this->putJson("/api/games/{$game->id}", ['league_id' => $newLeague->id])->assertOk();

        $newScorers = collect($this->getJson("/api/leagues/{$newLeague->id}/statistics")->assertOk()->json('data.top_scorers'));
        $this->assertSame(2, $newScorers->firstWhere('player_id', $scorer->id)['goals'] ?? null);

        $this->getJson("/api/leagues/{$oldLeagueId}/statistics")
            ->assertOk()
            ->assertJsonPath('data.top_scorers', []);
    }

    private function gameWithRemovedScorerAndAssist(): array
    {
        $game = $this->gameWithTeamsInLeague(League::factory()->create());
        $scorer = Player::factory()->create(['team_id' => $game->home_team_id]);
        $assist = Player::factory()->create(['team_id' => $game->home_team_id]);
        GameEvent::create([
            'game_id' => $game->id,
            'league_id' => $game->league_id,
            'team_id' => $game->home_team_id,
            'player_id' => $scorer->id,
            'related_player_id' => $assist->id,
            'type' => 'goal',
            'minute' => 12,
        ]);
        $scorer->delete();
        $assist->delete();

        return [$game, $scorer, $assist];
    }

    public function test_show_includes_removed_player_and_assist_in_events(): void
    {
        $this->authenticate();
        [$game, $scorer, $assist] = $this->gameWithRemovedScorerAndAssist();

        $this->getJson("/api/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.events.0.player', ['id' => $scorer->id, 'name' => $scorer->name, 'removed' => true])
            ->assertJsonPath('data.events.0.related_player', ['id' => $assist->id, 'name' => $assist->name, 'removed' => true]);
    }

    public function test_public_show_includes_removed_player_and_assist_in_events(): void
    {
        [$game, $scorer, $assist] = $this->gameWithRemovedScorerAndAssist();

        $this->getJson("/api/public/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.events.0.player', ['id' => $scorer->id, 'name' => $scorer->name, 'removed' => true])
            ->assertJsonPath('data.events.0.related_player', ['id' => $assist->id, 'name' => $assist->name, 'removed' => true]);
    }

    public function test_update_changing_league_returns_events_with_player(): void
    {
        $this->authenticate();
        $newLeague = League::factory()->create();
        [$game, $scorer] = $this->gameWithEventsMovableTo($newLeague);

        $this->putJson("/api/games/{$game->id}", ['league_id' => $newLeague->id])
            ->assertOk()
            ->assertJsonPath('data.events.0.player', ['id' => $scorer->id, 'name' => $scorer->name, 'removed' => false])
            ->assertJsonPath('data.events.1.player.id', $scorer->id)
            ->assertJsonPath('data.events.0.related_player', null);
    }

    public function test_update_away_team_returns_new_away_team(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $game = $this->gameWithTeamsInLeague($league);
        $newAway = Team::factory()->create();
        $league->teams()->attach($newAway->id);

        $this->putJson("/api/games/{$game->id}", ['away_team_id' => $newAway->id])
            ->assertOk()
            ->assertJsonPath('data.away_team_id', $newAway->id)
            ->assertJsonPath('data.awayTeam.id', $newAway->id)
            ->assertJsonPath('data.homeTeam.id', $game->home_team_id);
    }

    public function test_update_home_team_returns_new_home_team(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $game = $this->gameWithTeamsInLeague($league);
        $newHome = Team::factory()->create();
        $league->teams()->attach($newHome->id);

        $this->putJson("/api/games/{$game->id}", ['home_team_id' => $newHome->id])
            ->assertOk()
            ->assertJsonPath('data.home_team_id', $newHome->id)
            ->assertJsonPath('data.homeTeam.id', $newHome->id)
            ->assertJsonPath('data.awayTeam.id', $game->away_team_id);
    }

    public function test_update_stadium_returns_new_stadium(): void
    {
        $this->authenticate();
        $league = League::factory()->create();
        $oldStadium = Stadium::factory()->create(['league_id' => $league->id]);
        $newStadium = Stadium::factory()->create(['league_id' => $league->id]);
        $game = $this->gameWithTeamsInLeague($league, ['stadium_id' => $oldStadium->id]);

        $this->putJson("/api/games/{$game->id}", ['stadium_id' => $newStadium->id])
            ->assertOk()
            ->assertJsonPath('data.stadium_id', $newStadium->id)
            ->assertJsonPath('data.stadium.id', $newStadium->id);
    }

    public function test_update_without_changing_league_returns_events_with_player(): void
    {
        $this->authenticate();
        [$game, $scorer] = $this->gameWithEventsMovableTo(League::factory()->create());

        $this->putJson("/api/games/{$game->id}", ['match_date' => '2026-12-01 16:00:00'])
            ->assertOk()
            ->assertJsonCount(2, 'data.events')
            ->assertJsonPath('data.events.0.player', ['id' => $scorer->id, 'name' => $scorer->name, 'removed' => false])
            ->assertJsonPath('data.events.0.related_player', null);
    }
}
