<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\League;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(['home_wins' => 1, 'draws' => 0, 'away_wins' => 0], $dashboard->json('data.results_split'));
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
