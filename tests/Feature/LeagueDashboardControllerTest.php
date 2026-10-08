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
use Illuminate\Support\Facades\DB;
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

    // ── cards_split ───────────────────────────────────────────────────────────

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

    /**
     * Liga alvo com 12 jogadores cartoados (acima do top 10 de `top_cards`): 13 amarelos e
     * 2 vermelhos. Outra liga com cartões próprios, que não podem entrar no total da primeira.
     */
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

        // Aquece o cache de roles/permissions antes de medir.
        $this->getJson("/api/leagues/{$league->id}/dashboard")->assertOk();

        $cardsSplitQueries = [];
        DB::listen(function ($query) use (&$cardsSplitQueries) {
            // Alias `as yellow,` é exclusivo do cardsSplit (topCards usa `as yellow_cards`).
            if (str_contains($query->sql, 'as yellow,')) {
                $cardsSplitQueries[] = $query->sql;
            }
        });

        $this->getJson("/api/leagues/{$league->id}/dashboard")->assertOk();

        $this->assertCount(1, $cardsSplitQueries);
    }

    // ── Caso de borda: liga sem nenhum dado cadastrado ──────────────────────

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

    // ── Autorização ───────────────────────────────────────────────────────────

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
