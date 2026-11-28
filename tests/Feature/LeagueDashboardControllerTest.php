<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\Player;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeagueDashboardControllerTest extends TestCase
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

    public function test_dashboard_returns_header_counters_scoped_by_league(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin', 'season' => '2026']);
        $otherLeague = League::factory()->create(['format' => 'round_robin']);

        [$teamA, $teamB, $teamC] = Team::factory()->count(3)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);
        $otherLeague->teams()->attach([$teamC->id]);

        Stadium::factory()->create(['league_id' => $league->id]);
        Stadium::factory()->create(['league_id' => $otherLeague->id]);

        Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => 'finished',
            'home_score' => 1,
            'away_score' => 0,
        ]);
        Game::factory()->create(['league_id' => $otherLeague->id, 'home_team_id' => $teamC->id, 'away_team_id' => $teamA->id]);

        $response = $this->getJson("/api/leagues/{$league->id}/dashboard");

        $response->assertOk();
        $this->assertSame(2, $response->json('data.counters.teams'));
        $this->assertSame(1, $response->json('data.counters.games'));
        $this->assertSame(1, $response->json('data.counters.stadiums'));
        $this->assertSame('2026', $response->json('data.counters.season'));
    }

    public function test_dashboard_lists_last_results_and_upcoming_games(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);

        $oldResult = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => 'finished',
            'home_score' => 1,
            'away_score' => 0,
            'match_date' => now()->subDays(10),
        ]);
        $recentResult = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $teamB->id,
            'away_team_id' => $teamA->id,
            'status' => 'finished',
            'home_score' => 2,
            'away_score' => 2,
            'match_date' => now()->subDay(),
        ]);
        $nextGame = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => 'scheduled',
            'home_score' => null,
            'away_score' => null,
            'match_date' => now()->addDays(3),
        ]);
        Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $teamB->id,
            'away_team_id' => $teamA->id,
            'status' => 'scheduled',
            'home_score' => null,
            'away_score' => null,
            'match_date' => now()->addDays(10),
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/dashboard");

        $response->assertOk();

        $lastResults = $response->json('data.last_results');
        $this->assertCount(2, $lastResults);
        $this->assertSame($recentResult->id, $lastResults[0]['id']);
        $this->assertSame($oldResult->id, $lastResults[1]['id']);
        $this->assertSame($teamB->name, $lastResults[0]['home_team']['name']);

        $upcomingGames = $response->json('data.upcoming_games');
        $this->assertCount(2, $upcomingGames);
        $this->assertSame($nextGame->id, $upcomingGames[0]['id']);
    }

    public function test_dashboard_returns_team_logos_as_public_url_or_null(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        $withLogo = Team::factory()->create(['logo' => 'teams/escudo.png']);
        $withoutLogo = Team::factory()->create(['logo' => null]);
        $league->teams()->attach([$withLogo->id, $withoutLogo->id]);

        Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $withLogo->id,
            'away_team_id' => $withoutLogo->id,
            'status' => 'finished',
            'home_score' => 1,
            'away_score' => 0,
            'match_date' => now()->subDay(),
        ]);
        Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $withoutLogo->id,
            'away_team_id' => $withLogo->id,
            'status' => 'scheduled',
            'home_score' => null,
            'away_score' => null,
            'match_date' => now()->addDay(),
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/dashboard");

        $expectedUrl = Storage::disk('public')->url('teams/escudo.png');
        $response->assertOk()
            ->assertJsonPath('data.last_results.0.home_team.logo', $expectedUrl)
            ->assertJsonPath('data.last_results.0.away_team.logo', null)
            ->assertJsonPath('data.upcoming_games.0.home_team.logo', null)
            ->assertJsonPath('data.upcoming_games.0.away_team.logo', $expectedUrl);

        $resourceLogo = $this->getJson("/api/teams/{$withLogo->id}")->json('data.logo');
        $this->assertSame($resourceLogo, $expectedUrl);
    }

    public function test_dashboard_includes_goals_by_bucket_and_results_split_consistent_with_statistics(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);
        Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => 'finished',
            'home_score' => 2,
            'away_score' => 1,
            'round' => 1,
        ]);

        $dashboard = $this->getJson("/api/leagues/{$league->id}/dashboard")->assertOk();
        $statistics = $this->getJson("/api/leagues/{$league->id}/statistics")->assertOk();

        $this->assertSame($statistics->json('data.goals_by_bucket'), $dashboard->json('data.goals_by_bucket'));
        $this->assertSame($statistics->json('data.results_split'), $dashboard->json('data.results_split'));
        $this->assertSame($statistics->json('data.totals'), $dashboard->json('data.totals'));
        $this->assertSame(['goals' => 3, 'finished_games' => 1, 'goals_average' => 3.0], $dashboard->json('data.totals'));
        $this->assertSame(['home_wins' => 1, 'draws' => 0, 'away_wins' => 0], $dashboard->json('data.results_split'));
    }

    private function card(League $league, Game $game, Team $team, Player $player, string $type): GameEvent
    {
        return GameEvent::factory()->create([
            'league_id' => $league->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'player_id' => $player->id,
            'type' => $type,
        ]);
    }

    private function buildLeagueWithCardsAndAnotherLeague(): League
    {
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => 'finished',
            'home_score' => 0,
            'away_score' => 0,
        ]);

        for ($i = 0; $i < 12; $i++) {
            $player = Player::factory()->create(['team_id' => $teamA->id]);
            $this->card($league, $game, $teamA, $player, 'yellow_card');
        }
        $player = Player::factory()->create(['team_id' => $teamB->id]);
        $this->card($league, $game, $teamB, $player, 'yellow_card');
        $this->card($league, $game, $teamB, $player, 'red_card');
        $this->card($league, $game, $teamB, $player, 'red_card');

        $otherLeague = League::factory()->create(['format' => 'round_robin']);
        [$teamC, $teamD] = Team::factory()->count(2)->create();
        $otherLeague->teams()->attach([$teamC->id, $teamD->id]);
        $otherGame = Game::factory()->create([
            'league_id' => $otherLeague->id,
            'home_team_id' => $teamC->id,
            'away_team_id' => $teamD->id,
        ]);
        $otherPlayer = Player::factory()->create(['team_id' => $teamC->id]);
        $this->card($otherLeague, $otherGame, $teamC, $otherPlayer, 'yellow_card');
        $this->card($otherLeague, $otherGame, $teamC, $otherPlayer, 'red_card');

        return $league;
    }

    public function test_dashboard_includes_cards_split_scoped_by_league_without_limit(): void
    {
        $league = $this->buildLeagueWithCardsAndAnotherLeague();
        $this->authenticateAsAdmin([$league->id]);

        $dashboard = $this->getJson("/api/leagues/{$league->id}/dashboard")->assertOk();
        $statistics = $this->getJson("/api/leagues/{$league->id}/statistics")->assertOk();

        $this->assertSame(['yellow' => 13, 'red' => 2], $dashboard->json('data.cards_split'));
        $this->assertSame($statistics->json('data.cards_split'), $dashboard->json('data.cards_split'));
    }

    public function test_dashboard_cards_split_runs_exactly_one_aggregate_query(): void
    {
        $this->authenticate();
        $league = $this->buildLeagueWithCardsAndAnotherLeague();

        $this->getJson("/api/leagues/{$league->id}/dashboard")->assertOk();
        Cache::flush();

        $cardsSplitQueries = [];
        DB::listen(function ($query) use (&$cardsSplitQueries) {
            if (str_contains($query->sql, 'as yellow,')) {
                $cardsSplitQueries[] = $query->sql;
            }
        });

        $this->getJson("/api/leagues/{$league->id}/dashboard")->assertOk();

        $this->assertCount(1, $cardsSplitQueries);
    }

    public function test_dashboard_for_empty_league_returns_zeroed_data_without_error(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);

        $response = $this->getJson("/api/leagues/{$league->id}/dashboard");

        $response->assertOk();
        $this->assertSame(0, $response->json('data.counters.teams'));
        $this->assertSame(0, $response->json('data.counters.games'));
        $this->assertSame(0, $response->json('data.counters.stadiums'));
        $this->assertSame([], $response->json('data.last_results'));
        $this->assertSame([], $response->json('data.upcoming_games'));
        $this->assertSame(['yellow' => 0, 'red' => 0], $response->json('data.cards_split'));
        $this->assertSame(
            ['goals' => 0, 'finished_games' => 0, 'goals_average' => null],
            $response->json('data.totals')
        );
    }

    public function test_dashboard_counts_each_league_separately_when_a_team_is_in_two_leagues(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        $otherLeague = League::factory()->create(['format' => 'round_robin']);
        [$shared, $onlyHere, $onlyThere] = Team::factory()->count(3)->create();
        $league->teams()->attach([$shared->id, $onlyHere->id]);
        $otherLeague->teams()->attach([$shared->id, $onlyThere->id]);
        Stadium::factory()->count(2)->create(['league_id' => $league->id]);
        Stadium::factory()->create(['league_id' => $otherLeague->id]);
        $this->game($league, $shared, $onlyHere, 'finished', '2026-03-01 16:00:00');
        $this->game($otherLeague, $shared, $onlyThere, 'scheduled', '2026-03-02 16:00:00');
        $this->game($otherLeague, $onlyThere, $shared, 'scheduled', '2026-03-03 16:00:00');

        $this->getJson("/api/leagues/{$league->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.counters.teams', 2)
            ->assertJsonPath('data.counters.games', 1)
            ->assertJsonPath('data.counters.stadiums', 2);

        $this->getJson("/api/leagues/{$otherLeague->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.counters.teams', 2)
            ->assertJsonPath('data.counters.games', 2)
            ->assertJsonPath('data.counters.stadiums', 1);
    }

    public function test_dashboard_orders_last_results_by_date_desc_with_stable_tie_break(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'hybrid']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);
        $sameDate = '2026-03-10 16:00:00';

        $roundTwo = $this->game($league, $teamA, $teamB, 'finished', $sameDate, ['round' => 2]);
        $roundOneFirst = $this->game($league, $teamB, $teamA, 'finished', $sameDate, ['round' => 1]);
        $finalWithoutOrder = $this->game($league, $teamA, $teamB, 'finished', $sameDate, ['phase' => 'Final']);
        $semiFinal = $this->game($league, $teamB, $teamA, 'finished', $sameDate, ['phase' => 'Semi', 'phase_order' => 1]);
        $withoutDate = $this->game($league, $teamA, $teamB, 'finished', null, ['round' => 3]);
        $latest = $this->game($league, $teamB, $teamA, 'finished', '2026-03-12 16:00:00', ['round' => 3]);
        $roundOneSecond = $this->game($league, $teamA, $teamB, 'finished', $sameDate, ['round' => 1]);
        $this->game($league, $teamA, $teamB, 'scheduled', '2026-03-20 16:00:00', ['round' => 4]);
        $this->game($league, $teamA, $teamB, 'in_progress', '2026-03-30 16:00:00', ['round' => 4]);

        $response = $this->getJson("/api/leagues/{$league->id}/dashboard")->assertOk();

        $this->assertSame(
            [$latest->id, $roundOneFirst->id, $roundOneSecond->id, $roundTwo->id, $semiFinal->id],
            array_column($response->json('data.last_results'), 'id'),
        );
        $this->assertNotContains($finalWithoutOrder->id, array_column($response->json('data.last_results'), 'id'));
        $this->assertNotContains($withoutDate->id, array_column($response->json('data.last_results'), 'id'));
    }

    public function test_dashboard_orders_upcoming_games_with_undated_games_first(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'hybrid']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);

        $lateRoundFour = $this->game($league, $teamA, $teamB, 'scheduled', '2026-04-10 16:00:00', ['round' => 4]);
        $undatedRoundSix = $this->game($league, $teamB, $teamA, 'scheduled', null, ['round' => 6]);
        $lateRoundFive = $this->game($league, $teamA, $teamB, 'scheduled', '2026-04-10 16:00:00', ['round' => 5]);
        $undatedRoundFour = $this->game($league, $teamB, $teamA, 'scheduled', null, ['round' => 4]);
        $undatedPhase = $this->game($league, $teamA, $teamB, 'scheduled', null, ['phase' => 'Extra']);
        $early = $this->game($league, $teamB, $teamA, 'scheduled', '2026-04-01 16:00:00', ['round' => 7]);
        $this->game($league, $teamA, $teamB, 'finished', null, ['round' => 1]);
        $this->game($league, $teamA, $teamB, 'cancelled', null, ['round' => 1]);

        $response = $this->getJson("/api/leagues/{$league->id}/dashboard")->assertOk();

        $this->assertSame(
            [$undatedRoundFour->id, $undatedRoundSix->id, $undatedPhase->id, $early->id, $lateRoundFour->id],
            array_column($response->json('data.upcoming_games'), 'id'),
        );
        $this->assertNotContains($lateRoundFive->id, array_column($response->json('data.upcoming_games'), 'id'));
    }

    public function test_dashboard_does_not_run_ranking_queries_nor_load_every_game(): void
    {
        $this->authenticate();
        $league = $this->buildLeagueWithCardsAndAnotherLeague();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower(str_replace(['"', '`'], '', $query->sql));
        });

        $this->getJson("/api/leagues/{$league->id}/dashboard")->assertOk();

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('group by game_events.player_id', $sql);
            $this->assertStringNotContainsString('group by game_events.related_player_id', $sql);
            $this->assertStringNotContainsString('join players', $sql);
            $this->assertStringNotContainsString('select * from stadiums', $sql);
            $this->assertStringNotContainsString('select * from teams where teams.id in (select', $sql);
            if (str_starts_with($sql, 'select * from games')) {
                $this->assertStringContainsString('limit 5', $sql);
            }
        }
    }

    public function test_dashboard_query_count_does_not_grow_with_the_number_of_games(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        $teams = Team::factory()->count(8)->create();
        $league->teams()->attach($teams->pluck('id'));
        Stadium::factory()->create(['league_id' => $league->id]);
        $this->game($league, $teams[0], $teams[1], 'finished', '2026-03-01 16:00:00', ['round' => 1]);
        $this->game($league, $teams[2], $teams[3], 'scheduled', '2026-04-01 16:00:00', ['round' => 2]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->getJson("/api/leagues/{$league->id}/dashboard")->assertOk();

        $queries = 0;
        $this->getJson("/api/leagues/{$league->id}/dashboard")->assertOk();
        $fewGames = $queries;

        for ($i = 0; $i < 7; $i++) {
            $this->game($league, $teams[$i], $teams[$i + 1], 'finished', sprintf('2026-03-%02d 18:00:00', $i + 2), ['round' => 1]);
            $this->game($league, $teams[$i + 1], $teams[$i], 'scheduled', sprintf('2026-04-%02d 18:00:00', $i + 2), ['round' => 2]);
        }

        $queries = 0;
        $this->getJson("/api/leagues/{$league->id}/dashboard")->assertOk();

        $this->assertSame($fewGames, $queries);
    }

    private function game(League $league, Team $home, Team $away, string $status, ?string $matchDate, array $attributes = []): Game
    {
        $hasScore = in_array($status, ['finished', 'in_progress'], true);

        return Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'stadium_id' => null,
            'status' => $status,
            'match_date' => $matchDate,
            'home_score' => $hasScore ? 1 : null,
            'away_score' => $hasScore ? 0 : null,
            'round' => null,
            'phase' => null,
            'phase_order' => null,
            ...$attributes,
        ]);
    }

    public function test_admin_without_league_access_gets_forbidden(): void
    {
        $league = League::factory()->create(['format' => 'round_robin']);
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/leagues/{$league->id}/dashboard");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_with_league_access_can_view_dashboard(): void
    {
        $league = League::factory()->create(['format' => 'round_robin']);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/leagues/{$league->id}/dashboard");

        $response->assertOk();
    }

    public function test_guest_cannot_access_dashboard_route(): void
    {
        $league = League::factory()->create(['format' => 'round_robin']);

        $response = $this->getJson("/api/leagues/{$league->id}/dashboard");

        $response->assertStatus(401);
    }
}
