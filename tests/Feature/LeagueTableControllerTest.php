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

    private function addCard(League $league, Game $game, Team $team, string $color = 'yellow'): void
    {
        $player = Player::factory()->create(['team_id' => $team->id]);

        GameEvent::factory()->{$color.'Card'}()->create([
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
        $teamB = Team::factory()->create(['name' => 'Bravo']);
        $teamA = Team::factory()->create(['name' => 'Alfa']);
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
        $this->assertSame([true, true], collect($standings)->pluck('tied')->all());
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

    private const ROW_FIELDS = [
        'team_id', 'team', 'played', 'wins', 'draws', 'losses',
        'goals_for', 'goals_against', 'goal_difference', 'points', 'tied',
    ];

    private function namedTeams(array $names): array
    {
        return array_map(fn (string $name) => Team::factory()->create(['name' => $name]), $names);
    }

    private function groupLeague(array $groups, array $attributes = []): League
    {
        $league = League::factory()->create([
            'format' => 'hybrid',
            'fixture_mode' => 'draw',
            'groups_count' => count($groups),
            'group_legs' => 1,
            ...$attributes,
        ]);

        foreach ($groups as $index => $teams) {
            foreach ($teams as $team) {
                $league->teams()->attach($team->id, ['group_number' => $index + 1]);
            }
        }

        return $league;
    }

    private function teamOrder(array $standings): array
    {
        return array_column($standings, 'team_id');
    }

    public function test_table_exposes_groups_tiebreakers_and_tied_on_admin_and_public_routes(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        [$alfa, $bravo, $charlie] = $this->namedTeams(['Alfa', 'Bravo', 'Charlie']);
        $league->teams()->attach([$alfa->id, $bravo->id, $charlie->id]);
        $this->finishedGame($league, $charlie, $alfa, 2, 0);

        foreach (["/api/leagues/{$league->id}/table", "/api/public/leagues/{$league->id}/table"] as $url) {
            $response = $this->getJson($url)
                ->assertOk()
                ->assertJsonPath('data.tiebreakers', League::DEFAULT_TIEBREAKERS)
                ->assertJsonCount(1, 'data.groups')
                ->assertJsonPath('data.groups.0.group_number', 1)
                ->assertJsonMissingPath('data.phases')
                ->assertJsonStructure(['data' => [
                    'standings' => ['*' => self::ROW_FIELDS],
                    'groups' => ['*' => ['group_number', 'standings' => ['*' => self::ROW_FIELDS]]],
                    'tiebreakers',
                ]]);

            $this->assertSame($response->json('data.standings'), $response->json('data.groups.0.standings'));
            $this->assertSame([$charlie->id, $bravo->id, $alfa->id], $this->teamOrder($response->json('data.standings')));
            $this->assertSame([false, false, false], array_column($response->json('data.standings'), 'tied'));
        }
    }

    public function test_table_applies_the_configured_tiebreakers(): void
    {
        $this->authenticate();
        [$alfa, $bravo, $charlie, $delta] = $this->namedTeams(['Alfa', 'Bravo', 'Charlie', 'Delta']);
        $default = League::factory()->create(['format' => 'round_robin']);
        $custom = League::factory()->create([
            'format' => 'round_robin',
            'tiebreakers' => ['points', 'goals_against', 'wins'],
        ]);

        foreach ([$default, $custom] as $league) {
            $league->teams()->attach([$alfa->id, $bravo->id, $charlie->id, $delta->id]);
            $this->finishedGame($league, $alfa, $charlie, 4, 3);
            $this->finishedGame($league, $delta, $alfa, 1, 0, ['round' => 2]);
            $this->finishedGame($league, $bravo, $charlie, 0, 0, ['round' => 3]);
            $this->finishedGame($league, $bravo, $delta, 0, 0, ['round' => 4]);
            $this->finishedGame($league, $charlie, $bravo, 0, 0, ['round' => 5]);
        }

        $defaultTable = $this->getJson("/api/leagues/{$default->id}/table")->assertOk();
        $customTable = $this->getJson("/api/leagues/{$custom->id}/table")
            ->assertOk()
            ->assertJsonPath('data.tiebreakers', ['points', 'goals_against', 'wins']);

        $this->assertSame([$delta->id, $alfa->id, $bravo->id, $charlie->id], $this->teamOrder($defaultTable->json('data.standings')));
        $this->assertSame([$delta->id, $bravo->id, $alfa->id, $charlie->id], $this->teamOrder($customTable->json('data.standings')));
    }

    public function test_table_breaks_ties_by_red_and_yellow_cards_when_configured(): void
    {
        $this->authenticate();
        [$alfa, $bravo, $charlie, $delta] = $this->namedTeams(['Alfa', 'Bravo', 'Charlie', 'Delta']);
        $league = League::factory()->create(['format' => 'round_robin', 'tiebreakers' => ['points', 'fewer_red_cards']]);
        $league->teams()->attach([$alfa->id, $bravo->id, $charlie->id, $delta->id]);
        $alfaGame = $this->finishedGame($league, $alfa, $charlie, 1, 0);
        $bravoGame = $this->finishedGame($league, $bravo, $delta, 1, 0);
        $this->addCard($league, $alfaGame, $alfa, 'red');
        $this->addCard($league, $bravoGame, $bravo);
        $this->addCard($league, $bravoGame, $bravo);

        $standings = $this->getJson("/api/leagues/{$league->id}/table")->assertOk()->json('data.standings');
        $this->assertSame([$bravo->id, $alfa->id], array_slice($this->teamOrder($standings), 0, 2));

        $league->update(['tiebreakers' => ['points', 'fewer_yellow_cards']]);
        $standings = $this->getJson("/api/leagues/{$league->id}/table")->assertOk()->json('data.standings');
        $this->assertSame([$alfa->id, $bravo->id], array_slice($this->teamOrder($standings), 0, 2));
    }

    public function test_head_to_head_now_breaks_three_team_ties(): void
    {
        $this->authenticate();
        [$alfa, $bravo, $charlie, $delta] = $this->namedTeams(['Alfa', 'Bravo', 'Charlie', 'Delta']);
        $league = League::factory()->create(['format' => 'round_robin']);
        $league->teams()->attach([$alfa->id, $bravo->id, $charlie->id, $delta->id]);

        $this->finishedGame($league, $charlie, $alfa, 1, 0);
        $this->finishedGame($league, $charlie, $bravo, 1, 0, ['round' => 2]);
        $this->finishedGame($league, $alfa, $bravo, 1, 0, ['round' => 3]);
        $this->finishedGame($league, $alfa, $delta, 1, 0, ['round' => 4]);
        $this->finishedGame($league, $bravo, $delta, 1, 0, ['round' => 5]);
        $this->finishedGame($league, $delta, $charlie, 2, 0, ['round' => 6]);
        $this->finishedGame($league, $bravo, $alfa, 1, 0, ['round' => 7]);

        $standings = $this->getJson("/api/leagues/{$league->id}/table")->assertOk()->json('data.standings');

        $this->assertSame([6, 6, 6, 3], array_column($standings, 'points'));
        $this->assertSame([$charlie->id, $alfa->id, $bravo->id, $delta->id], $this->teamOrder($standings));
        $this->assertSame([false, true, true, false], array_column($standings, 'tied'));
    }

    public function test_legacy_league_without_multiple_ties_keeps_the_previous_order(): void
    {
        $this->authenticate();
        $teams = $this->namedTeams(['Zulu', 'Yankee', 'Xray', 'Whiskey', 'Victor', 'Uniform']);
        [$zulu, $yankee, $xray, $whiskey, $victor, $uniform] = $teams;
        $league = League::factory()->create(['format' => 'round_robin', 'tiebreakers' => null]);
        $league->teams()->attach(array_map(fn (Team $team) => $team->id, $teams));

        $this->finishedGame($league, $zulu, $yankee, 3, 0);
        $this->finishedGame($league, $xray, $whiskey, 2, 2);
        $this->finishedGame($league, $victor, $uniform, 1, 0);
        $this->finishedGame($league, $yankee, $xray, 1, 0, ['round' => 2]);
        $this->finishedGame($league, $whiskey, $victor, 0, 1, ['round' => 2]);
        $this->finishedGame($league, $uniform, $zulu, 0, 0, ['round' => 2]);
        $this->finishedGame($league, $xray, $uniform, 2, 1, ['round' => 3]);
        $cardGame = $this->finishedGame($league, $whiskey, $yankee, 1, 0, ['round' => 3]);
        $this->finishedGame($league, $zulu, $victor, 0, 0, ['round' => 3, 'status' => 'scheduled', 'home_score' => null, 'away_score' => null]);
        $this->addCard($league, $cardGame, $whiskey);

        $response = $this->getJson("/api/leagues/{$league->id}/table")->assertOk();
        $standings = $response->json('data.standings');

        $this->assertSame(
            [$victor->id, $zulu->id, $xray->id, $whiskey->id, $yankee->id, $uniform->id],
            $this->teamOrder($standings),
        );
        $this->assertSame([6, 4, 4, 4, 3, 1], array_column($standings, 'points'));
        $this->assertSame(
            ['team_id' => $zulu->id, 'played' => 2, 'wins' => 1, 'draws' => 1, 'losses' => 0, 'goals_for' => 3, 'goals_against' => 0, 'goal_difference' => 3, 'points' => 4, 'tied' => false],
            collect($standings[1])->except('team')->all(),
        );
        $this->assertSame(['id', 'name', 'nickname', 'logo'], array_keys($standings[1]['team']));
        $this->assertSame([false, false, false, false, false, false], array_column($standings, 'tied'));
        $response->assertJsonPath('data.tiebreakers', League::DEFAULT_TIEBREAKERS);
    }

    public function test_league_with_two_groups_ranks_each_group_with_its_own_games(): void
    {
        $this->authenticate();
        [$alfa, $bravo, $charlie, $delta] = $this->namedTeams(['Alfa', 'Bravo', 'Charlie', 'Delta']);
        $league = $this->groupLeague([[$alfa, $bravo], [$charlie, $delta]]);

        $this->finishedGame($league, $bravo, $alfa, 2, 1, ['group_number' => 1]);
        $this->finishedGame($league, $charlie, $delta, 0, 0, ['group_number' => 2]);
        $this->finishedGame($league, $alfa, $charlie, 4, 0, ['round' => 2, 'group_number' => null]);
        $this->finishedGame($league, $delta, $alfa, 3, 0, [
            'round' => null, 'phase' => 'Semifinal', 'phase_order' => 1, 'tie_key' => 'sf-1',
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/table")->assertOk()->assertJsonCount(2, 'data.groups');

        [$groupA, $groupB] = $response->json('data.groups');
        $this->assertSame(1, $groupA['group_number']);
        $this->assertSame(2, $groupB['group_number']);
        $this->assertSame([$bravo->id, $alfa->id], $this->teamOrder($groupA['standings']));
        $this->assertSame([1, 1], array_column($groupA['standings'], 'played'));
        $this->assertSame([$charlie->id, $delta->id], $this->teamOrder($groupB['standings']));
        $this->assertSame([true, true], array_column($groupB['standings'], 'tied'));

        $general = collect($response->json('data.standings'))->keyBy('team_id');
        $this->assertSame(2, $general[$alfa->id]['played']);
        $this->assertSame(3, $general[$alfa->id]['points']);
        $this->assertSame(0, $general[$delta->id]['goals_for']);
        $this->assertCount(1, $response->json('data.phases'));
    }

    public function test_single_group_league_before_the_draw_uses_manual_round_games(): void
    {
        $this->authenticate();
        [$alfa, $bravo, $charlie] = $this->namedTeams(['Alfa', 'Bravo', 'Charlie']);
        $league = League::factory()->create(['format' => 'round_robin', 'fixture_mode' => 'draw', 'groups_count' => 1]);
        $league->teams()->attach([$alfa->id, $bravo->id, $charlie->id]);

        $this->finishedGame($league, $bravo, $alfa, 1, 0);
        $this->finishedGame($league, $charlie, $alfa, 2, 0, ['round' => 2]);
        $this->finishedGame($league, $bravo, $charlie, 1, 1, ['round' => 3]);

        $response = $this->getJson("/api/public/leagues/{$league->id}/table")->assertOk()->assertJsonCount(1, 'data.groups');

        $group = $response->json('data.groups.0.standings');
        $this->assertSame([$charlie->id, $bravo->id, $alfa->id], $this->teamOrder($group));
        $this->assertSame([4, 4, 0], array_column($group, 'points'));
        $this->assertSame($response->json('data.standings'), $group);
    }

    public function test_group_without_teams_still_appears(): void
    {
        $this->authenticate();
        [$alfa, $bravo] = $this->namedTeams(['Alfa', 'Bravo']);
        $league = $this->groupLeague([[$alfa, $bravo], []]);

        $this->getJson("/api/leagues/{$league->id}/table")
            ->assertOk()
            ->assertJsonPath('data.groups.1', ['group_number' => 2, 'standings' => []])
            ->assertJsonCount(2, 'data.groups.0.standings');
    }

    public function test_knockout_league_table_keeps_only_phases(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);

        $this->getJson("/api/leagues/{$league->id}/table")
            ->assertOk()
            ->assertJsonPath('data', ['phases' => []]);
    }
}
