<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeagueTableControllerTest extends TestCase
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

    private function finishedGame(League $league, Team $home, Team $away, int $homeScore, int $awayScore, array $overrides = []): Game
    {
        return Game::factory()->create(array_merge([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'status' => 'finished',
            'round' => 1,
            'phase' => null,
            'phase_order' => null,
            'tie_key' => null,
            'leg' => null,
        ], $overrides));
    }

    private function addCard(League $league, Game $game, Team $team): void
    {
        $player = Player::factory()->create(['team_id' => $team->id]);

        GameEvent::factory()->yellowCard()->create([
            'league_id' => $league->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'player_id' => $player->id,
        ]);
    }

    public function test_round_robin_standings_reflect_finished_games(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamA, $teamB, $teamC, $teamD] = Team::factory()->count(4)->create();
        $league->teams()->attach([$teamA->id, $teamB->id, $teamC->id, $teamD->id]);

        $this->finishedGame($league, $teamA, $teamB, 2, 1);
        $this->finishedGame($league, $teamC, $teamD, 0, 0);
        $this->finishedGame($league, $teamA, $teamC, 1, 1);

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertOk();
        $standings = collect($response->json('data.standings'))->keyBy('team_id');

        $this->assertSame(4, $standings[$teamA->id]['points']);
        $this->assertSame(2, $standings[$teamA->id]['played']);
        $this->assertSame(1, $standings[$teamA->id]['wins']);
        $this->assertSame(1, $standings[$teamA->id]['draws']);
        $this->assertSame(3, $standings[$teamA->id]['goals_for']);
        $this->assertSame(2, $standings[$teamA->id]['goals_against']);
        $this->assertSame(1, $standings[$teamA->id]['goal_difference']);

        $this->assertSame(0, $standings[$teamB->id]['points']);
        $this->assertSame(2, $standings[$teamC->id]['points']);
        $this->assertSame(1, $standings[$teamD->id]['points']);

        $order = collect($response->json('data.standings'))->pluck('team_id')->all();
        $this->assertSame([$teamA->id, $teamC->id, $teamD->id, $teamB->id], $order);
    }

    public function test_round_robin_tie_is_broken_by_head_to_head_when_two_teams_fully_tied(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamP, $teamQ, $teamR, $teamS] = Team::factory()->count(4)->create();
        $league->teams()->attach([$teamP->id, $teamQ->id, $teamR->id, $teamS->id]);

        $this->finishedGame($league, $teamP, $teamQ, 1, 0);
        $this->finishedGame($league, $teamP, $teamR, 0, 1);
        $this->finishedGame($league, $teamQ, $teamS, 1, 0);

        $response = $this->getJson("/api/leagues/{$league->id}/table");
        $response->assertOk();

        $standings = collect($response->json('data.standings'));
        $p = $standings->firstWhere('team_id', $teamP->id);
        $q = $standings->firstWhere('team_id', $teamQ->id);

        $this->assertSame($p['points'], $q['points']);
        $this->assertSame($p['wins'], $q['wins']);
        $this->assertSame($p['goal_difference'], $q['goal_difference']);
        $this->assertSame($p['goals_for'], $q['goals_for']);

        $positionP = $standings->search(fn ($row) => $row['team_id'] === $teamP->id);
        $positionQ = $standings->search(fn ($row) => $row['team_id'] === $teamQ->id);
        $this->assertLessThan($positionQ, $positionP);
    }

    public function test_round_robin_tie_is_broken_by_cards_when_no_direct_match_was_played(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamX, $teamY, $teamA, $teamB] = Team::factory()->count(4)->create();
        $league->teams()->attach([$teamX->id, $teamY->id, $teamA->id, $teamB->id]);

        $this->finishedGame($league, $teamX, $teamA, 1, 0);
        $gameY = $this->finishedGame($league, $teamY, $teamB, 1, 0);
        $this->addCard($league, $gameY, $teamY);

        $response = $this->getJson("/api/leagues/{$league->id}/table");
        $response->assertOk();

        $standings = collect($response->json('data.standings'));
        $positionX = $standings->search(fn ($row) => $row['team_id'] === $teamX->id);
        $positionY = $standings->search(fn ($row) => $row['team_id'] === $teamY->id);

        $this->assertLessThan($positionY, $positionX);
    }

    public function test_round_robin_standings_are_empty_and_ordered_deterministically_without_finished_games(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertOk();
        $standings = $response->json('data.standings');

        $this->assertCount(2, $standings);
        foreach ($standings as $row) {
            $this->assertSame(0, $row['played']);
            $this->assertSame(0, $row['points']);
        }
        $this->assertSame([$teamA->id, $teamB->id], collect($standings)->pluck('team_id')->all());
    }

    public function test_knockout_phases_aggregate_home_and_away_legs_and_compute_winner(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        [$teamOne, $teamTwo] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamOne->id, $teamTwo->id]);

        $this->finishedGame($league, $teamOne, $teamTwo, 2, 1, [
            'round' => null, 'phase' => 'Semifinal', 'phase_order' => 1, 'tie_key' => 'semi-1', 'leg' => 1,
        ]);
        $this->finishedGame($league, $teamTwo, $teamOne, 2, 0, [
            'round' => null, 'phase' => 'Semifinal', 'phase_order' => 1, 'tie_key' => 'semi-1', 'leg' => 2,
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertOk();
        $phases = $response->json('data.phases');

        $this->assertCount(1, $phases);
        $this->assertSame('Semifinal', $phases[0]['phase']);
        $tie = $phases[0]['ties'][0];

        $this->assertSame('semi-1', $tie['tie_key']);
        $this->assertTrue($tie['decided']);
        $this->assertSame($teamTwo->id, $tie['winner_team_id']);
        $this->assertCount(2, $tie['legs']);

        $aggregates = collect($tie['teams'])->pluck('aggregate_score', 'team_id');
        $this->assertSame(2, $aggregates[$teamOne->id]);
        $this->assertSame(3, $aggregates[$teamTwo->id]);
    }

    public function test_knockout_tie_with_one_leg_still_unplayed_is_open(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        [$teamOne, $teamTwo] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamOne->id, $teamTwo->id]);

        $this->finishedGame($league, $teamOne, $teamTwo, 1, 0, [
            'round' => null, 'phase' => 'Final', 'phase_order' => 1, 'tie_key' => 'final-1', 'leg' => 1,
        ]);
        Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $teamTwo->id,
            'away_team_id' => $teamOne->id,
            'home_score' => null,
            'away_score' => null,
            'status' => 'scheduled',
            'round' => null,
            'phase' => 'Final',
            'phase_order' => 1,
            'tie_key' => 'final-1',
            'leg' => 2,
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertOk();
        $tie = $response->json('data.phases')[0]['ties'][0];

        $this->assertFalse($tie['decided']);
        $this->assertNull($tie['winner_team_id']);
        $this->assertCount(2, $tie['legs']);
    }

    public function test_knockout_tie_with_only_first_leg_registered_and_finished_is_open(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        [$teamOne, $teamTwo] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamOne->id, $teamTwo->id]);

        $this->finishedGame($league, $teamOne, $teamTwo, 2, 0, [
            'round' => null, 'phase' => 'Semifinal', 'phase_order' => 1, 'tie_key' => 'semi-1', 'leg' => 1,
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertOk();
        $tie = $response->json('data.phases')[0]['ties'][0];

        $this->assertFalse($tie['decided']);
        $this->assertNull($tie['winner_team_id']);
        $this->assertCount(1, $tie['legs']);

        $aggregates = collect($tie['teams'])->pluck('aggregate_score', 'team_id');
        $this->assertSame(2, $aggregates[$teamOne->id]);
        $this->assertSame(0, $aggregates[$teamTwo->id]);
    }

    public function test_knockout_tie_with_only_second_leg_registered_and_finished_is_open(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        [$teamOne, $teamTwo] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamOne->id, $teamTwo->id]);

        $this->finishedGame($league, $teamTwo, $teamOne, 1, 0, [
            'round' => null, 'phase' => 'Semifinal', 'phase_order' => 1, 'tie_key' => 'semi-1', 'leg' => 2,
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertOk();
        $tie = $response->json('data.phases')[0]['ties'][0];

        $this->assertFalse($tie['decided']);
        $this->assertNull($tie['winner_team_id']);
    }

    public function test_knockout_single_game_tie_is_decided_once_finished(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        [$teamOne, $teamTwo] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamOne->id, $teamTwo->id]);

        $this->finishedGame($league, $teamOne, $teamTwo, 0, 1, [
            'round' => null, 'phase' => 'Final', 'phase_order' => 1, 'tie_key' => 'final-1', 'leg' => null,
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertOk();
        $tie = $response->json('data.phases')[0]['ties'][0];

        $this->assertTrue($tie['decided']);
        $this->assertSame($teamTwo->id, $tie['winner_team_id']);
    }

    public function test_knockout_legacy_game_with_leg_and_without_tie_key_is_never_decided(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        [$teamOne, $teamTwo] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamOne->id, $teamTwo->id]);

        $this->finishedGame($league, $teamOne, $teamTwo, 3, 0, [
            'round' => null, 'phase' => 'Final', 'phase_order' => 1, 'tie_key' => null, 'leg' => 1,
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertOk();
        $tie = $response->json('data.phases')[0]['ties'][0];

        $this->assertNull($tie['tie_key']);
        $this->assertFalse($tie['decided']);
        $this->assertNull($tie['winner_team_id']);
    }

    public function test_knockout_tie_with_both_legs_finished_and_tied_aggregate_is_decided_without_winner(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        [$teamOne, $teamTwo] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamOne->id, $teamTwo->id]);

        $this->finishedGame($league, $teamOne, $teamTwo, 1, 0, [
            'round' => null, 'phase' => 'Final', 'phase_order' => 1, 'tie_key' => 'final-1', 'leg' => 1,
        ]);
        $this->finishedGame($league, $teamTwo, $teamOne, 1, 0, [
            'round' => null, 'phase' => 'Final', 'phase_order' => 1, 'tie_key' => 'final-1', 'leg' => 2,
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertOk();
        $tie = $response->json('data.phases')[0]['ties'][0];

        $this->assertTrue($tie['decided']);
        $this->assertNull($tie['winner_team_id']);
    }

    public function test_hybrid_league_ignores_phase_games_in_standings_but_returns_phases_too(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'hybrid']);
        [$teamA, $teamB, $teamC, $teamD] = Team::factory()->count(4)->create();
        $league->teams()->attach([$teamA->id, $teamB->id, $teamC->id, $teamD->id]);

        $this->finishedGame($league, $teamA, $teamB, 3, 0);

        $this->finishedGame($league, $teamC, $teamD, 5, 0, [
            'round' => null, 'phase' => 'Final', 'phase_order' => 1, 'tie_key' => 'final-1', 'leg' => 1,
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertOk();
        $standings = collect($response->json('data.standings'))->keyBy('team_id');

        $this->assertSame(3, $standings[$teamA->id]['points']);
        $this->assertSame(0, $standings[$teamB->id]['points']);
        $this->assertSame(0, $standings[$teamC->id]['points']);
        $this->assertSame(0, $standings[$teamD->id]['played']);

        $phases = $response->json('data.phases');
        $this->assertCount(1, $phases);
        $tie = $phases[0]['ties'][0];
        $this->assertSame('final-1', $tie['tie_key']);

        $this->assertFalse($tie['decided']);
        $this->assertNull($tie['winner_team_id']);
        $aggregates = collect($tie['teams'])->pluck('aggregate_score', 'team_id');
        $this->assertSame(5, $aggregates[$teamC->id]);
        $this->assertSame(0, $aggregates[$teamD->id]);
    }

    public function test_admin_without_league_access_gets_forbidden(): void
    {
        $league = League::factory()->create(['format' => 'round_robin']);
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_with_league_access_can_view_table(): void
    {
        $league = League::factory()->create(['format' => 'round_robin']);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertOk();
    }

    public function test_guest_cannot_access_admin_table_route(): void
    {
        $league = League::factory()->create(['format' => 'round_robin']);

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertStatus(401);
    }

    public function test_guest_can_view_table_via_public_route(): void
    {
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);
        $this->finishedGame($league, $teamA, $teamB, 2, 0);

        $response = $this->getJson("/api/public/leagues/{$league->id}/table");

        $response->assertOk()->assertJsonPath('data.standings.0.team_id', $teamA->id);
    }

    private function leagueWithLogoAndWithoutLogo(): array
    {
        Storage::fake('public');

        $league = League::factory()->create(['format' => 'round_robin']);
        $withLogo = Team::factory()->create(['logo' => 'teams/escudo.png']);
        $withoutLogo = Team::factory()->create(['logo' => null]);
        $league->teams()->attach([$withLogo->id, $withoutLogo->id]);
        $this->finishedGame($league, $withLogo, $withoutLogo, 1, 0);

        return [$league, $withLogo];
    }

    public function test_admin_table_returns_team_logo_as_public_url(): void
    {
        $this->authenticate();
        [$league] = $this->leagueWithLogoAndWithoutLogo();

        $response = $this->getJson("/api/leagues/{$league->id}/table");

        $response->assertOk()
            ->assertJsonPath('data.standings.0.team.logo', Storage::disk('public')->url('teams/escudo.png'))
            ->assertJsonPath('data.standings.1.team.logo', null);
    }

    public function test_public_table_returns_team_logo_as_public_url(): void
    {
        [$league] = $this->leagueWithLogoAndWithoutLogo();

        $response = $this->getJson("/api/public/leagues/{$league->id}/table");

        $response->assertOk()
            ->assertJsonPath('data.standings.0.team.logo', Storage::disk('public')->url('teams/escudo.png'))
            ->assertJsonPath('data.standings.1.team.logo', null);
    }

    public function test_table_team_logo_matches_team_resource_format(): void
    {
        $this->authenticate();
        [$league, $withLogo] = $this->leagueWithLogoAndWithoutLogo();

        $tableLogo = $this->getJson("/api/leagues/{$league->id}/table")->json('data.standings.0.team.logo');
        $resourceLogo = $this->getJson("/api/teams/{$withLogo->id}")->json('data.logo');

        $this->assertNotNull($tableLogo);
        $this->assertSame($resourceLogo, $tableLogo);
    }

    private function buildDistinctRoundRobinLeague(int $teamsCount): League
    {
        $league = League::factory()->create(['format' => 'round_robin']);
        $teams = Team::factory()->count($teamsCount)->create();
        $league->teams()->attach($teams->pluck('id')->all());

        foreach ($teams->chunk(2)->values() as $index => $pair) {
            if ($pair->count() < 2) {
                continue;
            }

            [$home, $away] = $pair->values()->all();

            $this->finishedGame($league, $home, $away, $index + 2, 1);
        }

        return $league;
    }

    public function test_standings_query_count_does_not_grow_with_team_count(): void
    {
        $this->authenticate();

        $smallLeague = $this->buildDistinctRoundRobinLeague(4);
        $largeLeague = $this->buildDistinctRoundRobinLeague(20);

        $this->getJson("/api/leagues/{$smallLeague->id}/table")->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson("/api/leagues/{$smallLeague->id}/table")->assertOk();
        $smallQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->getJson("/api/leagues/{$largeLeague->id}/table")->assertOk();
        $largeQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($smallQueryCount, $largeQueryCount);
    }
}
