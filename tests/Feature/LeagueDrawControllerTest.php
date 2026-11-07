<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\LeagueDrawPlan;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\Draw\RoundRobinScheduler;
use App\Services\LeagueDrawService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class LeagueDrawControllerTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

    private const HYBRID_DRAW = [
        'format' => 'hybrid',
        'fixture_mode' => 'draw',
        'groups_count' => 2,
        'group_legs' => 1,
        'qualifiers_per_group' => 2,
        'knockout_size' => 4,
        'knockout_legs' => 1,
        'final_legs' => 1,
        'knockout_tiebreakers' => ['penalties'],
    ];

    private const ROUND_ROBIN = [
        'format' => 'round_robin',
        'qualifiers_per_group' => null,
        'knockout_size' => null,
        'knockout_legs' => null,
        'final_legs' => null,
        'knockout_tiebreakers' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
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

    private function useSeed(int $seed, int $budgetMs = 200): void
    {
        $this->app->instance(
            RoundRobinScheduler::class,
            new RoundRobinScheduler(new Randomizer(new Mt19937($seed)), $budgetMs, 200000),
        );
    }

    private function league(array $groupSizes, array $attributes = []): League
    {
        $league = League::factory()->create([
            ...self::HYBRID_DRAW,
            'groups_count' => count($groupSizes),
            'qualifiers_per_group' => 1,
            'knockout_size' => count($groupSizes) >= 2 ? count($groupSizes) : null,
            ...$attributes,
        ]);

        foreach (array_values($groupSizes) as $index => $size) {
            foreach (Team::factory()->count($size)->create() as $team) {
                $league->teams()->attach($team->id, ['group_number' => $index + 1]);
            }
        }

        return $league;
    }

    private function drawRound(League $league, int $round)
    {
        return $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => $round]);
    }

    private function drawAll(League $league): void
    {
        $service = app(LeagueDrawService::class);
        $state = $service->build($league);

        while ($state['next_round'] !== null) {
            $state = $service->drawNextRound((string) $league->id, $state['next_round']);
        }
    }

    private function groupTeamIds(League $league, int $groupNumber): array
    {
        return $league->teams()->wherePivot('group_number', $groupNumber)->pluck('teams.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    private function assertTurnIsValid(League $league, int $groupNumber, array $teamIds, int $firstRound, int $lastRound): void
    {
        $games = Game::where('league_id', $league->id)
            ->where('group_number', $groupNumber)
            ->whereBetween('round', [$firstRound, $lastRound])
            ->get();
        $size = count($teamIds);

        $pairs = $games->map(fn (Game $game) => collect([$game->home_team_id, $game->away_team_id])->sort()->implode('-'));
        $this->assertSame($pairs->count(), $pairs->unique()->count(), 'Par repetido no turno.');
        $this->assertCount(intdiv($size * ($size - 1), 2), $games);

        $home = array_fill_keys($teamIds, 0);
        $played = array_fill_keys($teamIds, 0);
        $byes = array_fill_keys($teamIds, 0);

        for ($round = $firstRound; $round <= $lastRound; $round++) {
            $roundGames = $games->where('round', $round);
            $playing = [...$roundGames->pluck('home_team_id')->all(), ...$roundGames->pluck('away_team_id')->all()];
            $this->assertSame(count($playing), count(array_unique($playing)), 'Time jogando duas vezes na rodada.');

            foreach (array_diff($teamIds, $playing) as $teamId) {
                $byes[$teamId]++;
            }
        }

        foreach ($games as $game) {
            $home[$game->home_team_id]++;
            $played[$game->home_team_id]++;
            $played[$game->away_team_id]++;
        }

        foreach ($teamIds as $teamId) {
            $this->assertSame($size - 1, $played[$teamId]);
            $this->assertSame($size % 2, $byes[$teamId]);
            $this->assertLessThanOrEqual($size % 2 === 1 ? 0 : 1, abs(2 * $home[$teamId] - $played[$teamId]));
        }
    }

    public function test_guest_cannot_access_draw(): void
    {
        $league = $this->league([4]);

        $this->getJson("/api/leagues/{$league->id}/draw")->assertStatus(401);
        $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => 1])->assertStatus(401);
        $this->deleteJson("/api/leagues/{$league->id}/draw/last-round", ['round' => 1])->assertStatus(401);
        $this->deleteJson("/api/leagues/{$league->id}/draw")->assertStatus(401);
    }

    public function test_state_before_draw(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([5, 5], ['group_legs' => 2]);

        $this->getJson("/api/leagues/{$league->id}/draw")
            ->assertOk()
            ->assertExactJson(['data' => [
                'league_id' => $league->id,
                'format' => 'hybrid',
                'fixture_mode' => 'draw',
                'groups_count' => 2,
                'group_legs' => 2,
                'group_size' => 5,
                'rounds_per_turn' => 5,
                'total_rounds' => 10,
                'drawn_rounds' => 0,
                'next_round' => 1,
                'can_draw' => true,
                'draw_blocked_reason' => null,
                'can_undo' => false,
                'undo_blocked_reason' => 'Nenhuma rodada foi sorteada.',
                'can_reset' => false,
                'reset_blocked_reason' => 'Nenhuma rodada foi sorteada.',
                'manual_round_games' => ['total' => 0, 'with_results' => 0],
                'groups' => [
                    ['group_number' => 1, 'byes' => []],
                    ['group_number' => 2, 'byes' => []],
                ],
            ]]);
    }

    public function test_next_round_creates_one_round_for_every_group(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([5, 5]);

        $response = $this->drawRound($league, 1);

        $response->assertOk()
            ->assertJsonPath('data.drawn_rounds', 1)
            ->assertJsonPath('data.next_round', 2)
            ->assertJsonPath('data.can_undo', true)
            ->assertJsonPath('data.can_reset', true)
            ->assertJsonCount(1, 'data.groups.0.byes')
            ->assertJsonPath('data.groups.0.byes.0.round', 1)
            ->assertJsonStructure(['data' => ['groups' => [['byes' => [['round', 'team' => ['id', 'name', 'nickname', 'logo']]]]]]]);

        $this->assertSame([
            'league_id', 'format', 'fixture_mode', 'groups_count', 'group_legs', 'group_size', 'rounds_per_turn',
            'total_rounds', 'drawn_rounds', 'next_round', 'can_draw', 'draw_blocked_reason', 'can_undo',
            'undo_blocked_reason', 'can_reset', 'reset_blocked_reason', 'manual_round_games', 'groups',
        ], array_keys($response->json('data')));
        $this->assertSame(['group_number', 'byes'], array_keys($response->json('data.groups.0')));

        foreach ([1, 2] as $groupNumber) {
            $games = Game::where('league_id', $league->id)->where('group_number', $groupNumber)->get();
            $this->assertCount(2, $games);
            $this->assertContains($response->json('data.groups.'.($groupNumber - 1).'.byes.0.team.id'), $this->groupTeamIds($league, $groupNumber));

            foreach ($games as $game) {
                $this->assertSame(1, $game->round);
                $this->assertSame('scheduled', $game->status);
                $this->assertNull($game->match_date);
                $this->assertNull($game->stadium_id);
                $this->assertNull($game->phase);
                $this->assertNull($game->home_score);
                $this->assertContains($game->home_team_id, $this->groupTeamIds($league, $groupNumber));
                $this->assertContains($game->away_team_id, $this->groupTeamIds($league, $groupNumber));
            }
        }

        $this->assertSame(2, LeagueDrawPlan::where('league_id', $league->id)->count());
        $this->assertCount(4, LeagueDrawPlan::where('league_id', $league->id)->first()->rounds());
    }

    public function test_round_number_is_required_and_must_be_the_next_one(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4]);

        $this->postJson("/api/leagues/{$league->id}/draw/next-round")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['round']);

        $this->drawRound($league, 2)
            ->assertStatus(422)
            ->assertJsonPath('message', 'A próxima rodada a ser sorteada é a 1.');

        $this->assertSame(0, Game::count());
    }

    public function test_double_click_does_not_draw_the_same_round_twice(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([6]);

        $this->drawRound($league, 1)->assertOk();
        $this->drawRound($league, 1)
            ->assertStatus(422)
            ->assertJsonPath('message', 'A rodada 1 já foi sorteada.');

        $this->assertSame(3, Game::where('league_id', $league->id)->count());
        $this->assertSame(1, (int) Game::max('round'));
    }

    public function test_full_draw_with_return_leg_mirrors_the_first_turn(): void
    {
        $this->authenticateAs('master');
        $this->useSeed(42);
        $league = $this->league([5, 5], ['group_legs' => 2]);

        for ($round = 1; $round <= 10; $round++) {
            $this->drawRound($league, $round)->assertOk()->assertJsonPath('data.drawn_rounds', $round);
        }

        $this->drawRound($league, 11)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Todas as rodadas já foram sorteadas.');

        $this->getJson("/api/leagues/{$league->id}/draw")
            ->assertOk()
            ->assertJsonPath('data.next_round', null)
            ->assertJsonPath('data.can_draw', false)
            ->assertJsonPath('data.draw_blocked_reason', 'Todas as rodadas já foram sorteadas.')
            ->assertJsonCount(10, 'data.groups.0.byes');

        foreach ([1, 2] as $groupNumber) {
            $teamIds = $this->groupTeamIds($league, $groupNumber);
            $this->assertTurnIsValid($league, $groupNumber, $teamIds, 1, 5);
            $this->assertTurnIsValid($league, $groupNumber, $teamIds, 6, 10);

            for ($round = 1; $round <= 5; $round++) {
                $first = Game::where('league_id', $league->id)->where('group_number', $groupNumber)->where('round', $round)
                    ->get()->map(fn (Game $game) => [$game->away_team_id, $game->home_team_id])->sort()->values()->all();
                $second = Game::where('league_id', $league->id)->where('group_number', $groupNumber)->where('round', $round + 5)
                    ->get()->map(fn (Game $game) => [$game->home_team_id, $game->away_team_id])->sort()->values()->all();

                $this->assertSame($first, $second);
            }
        }
    }

    public static function drawConfigurations(): array
    {
        $cases = [];

        foreach ([2, 3, 4, 5, 6, 7, 8, 9, 11, 12, 15, 16, 19, 20] as $size) {
            foreach ([1, 2] as $legs) {
                $cases["{$size} times, {$legs} turno(s)"] = [[$size], $legs, 200, $size * 10 + $legs];
            }
        }

        $cases['4 grupos de 5, 2 turnos'] = [[5, 5, 5, 5], 2, 200, 7];
        $cases['3 grupos de 4, 1 turno'] = [[4, 4, 4], 1, 200, 8];
        $cases['orçamento zero, 9 times, 2 turnos'] = [[9], 2, 0, 9];
        $cases['orçamento zero, 2 grupos de 8'] = [[8, 8], 1, 0, 10];

        return $cases;
    }

    #[DataProvider('drawConfigurations')]
    public function test_draw_properties_hold_for_every_configuration(array $groupSizes, int $legs, int $budgetMs, int $seed): void
    {
        $this->useSeed($seed, $budgetMs);
        $league = $this->league($groupSizes, [...(count($groupSizes) === 1 ? self::ROUND_ROBIN : []), 'group_legs' => $legs]);

        $this->drawAll($league);

        $roundsPerTurn = RoundRobinScheduler::roundsPerTurn($groupSizes[0]);
        $this->assertSame($roundsPerTurn * $legs, (int) Game::where('league_id', $league->id)->max('round'));

        foreach (array_keys($groupSizes) as $index) {
            $teamIds = $this->groupTeamIds($league, $index + 1);

            for ($turn = 1; $turn <= $legs; $turn++) {
                $this->assertTurnIsValid($league, $index + 1, $teamIds, ($turn - 1) * $roundsPerTurn + 1, $turn * $roundsPerTurn);
            }
        }
    }

    public function test_twenty_team_group_draws_every_round_quickly(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([20], [...self::ROUND_ROBIN, 'group_legs' => 2]);
        $slowest = 0.0;
        $start = microtime(true);

        for ($round = 1; $round <= 38; $round++) {
            $roundStart = microtime(true);
            $this->drawRound($league, $round)->assertOk();
            $slowest = max($slowest, microtime(true) - $roundStart);
        }

        $this->assertLessThan(1.0, $slowest);
        $this->assertLessThan(20.0, microtime(true) - $start);
        $this->assertSame(380, Game::where('league_id', $league->id)->count());
        $this->assertTurnIsValid($league, 1, $this->groupTeamIds($league, 1), 1, 19);
    }

    public static function blockedLeagues(): array
    {
        return [
            'modo manual' => [['fixture_mode' => 'manual'], [4, 4], 'O sorteio de rodadas só está disponível em ligas com sorteio.'],
            'formato mata-mata' => [['format' => 'knockout'], [4, 4], 'O sorteio de rodadas só está disponível em ligas de pontos corridos ou com fase de grupos.'],
            'sem times' => [[], [], 'A liga não tem times para sortear.'],
            'grupos de 1 time' => [[], [1, 1], 'Cada grupo precisa ter pelo menos 2 times.'],
            'grupos desiguais' => [[], [3, 5], 'Todos os grupos precisam ter o mesmo número de times antes do sorteio.'],
        ];
    }

    #[DataProvider('blockedLeagues')]
    public function test_draw_preconditions(array $attributes, array $groupSizes, string $message): void
    {
        $this->authenticateAs('master');
        $league = $this->league($groupSizes ?: [0, 0], $attributes);

        $this->getJson("/api/leagues/{$league->id}/draw")
            ->assertOk()
            ->assertJsonPath('data.can_draw', false)
            ->assertJsonPath('data.draw_blocked_reason', $message);

        $this->drawRound($league, 1)->assertStatus(422)->assertJsonPath('message', $message);
        $this->assertSame(0, Game::count());
    }

    public function test_draw_requires_every_team_in_a_group(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4, 4]);
        $league->teams()->attach(Team::factory()->create()->id);

        $this->drawRound($league, 1)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Todos os times da liga precisam estar em um grupo antes do sorteio.');
    }

    private function singleGroupLeague(int $teams, int $legs): League
    {
        $league = League::factory()->create([
            ...self::HYBRID_DRAW,
            ...self::ROUND_ROBIN,
            'groups_count' => 1,
            'group_legs' => $legs,
        ]);
        $league->teams()->attach(Team::factory()->count($teams)->create()->pluck('id'));

        return $league;
    }

    public static function singleGroupSizes(): array
    {
        return [
            '4 times, 1 turno' => [4, 1, 3],
            '4 times, 2 turnos' => [4, 2, 6],
            '5 times, 1 turno' => [5, 1, 5],
            '5 times, 2 turnos' => [5, 2, 10],
        ];
    }

    #[DataProvider('singleGroupSizes')]
    public function test_single_group_league_resolves_teams_without_group(int $teams, int $legs, int $totalRounds): void
    {
        $this->authenticateAs('master');
        $league = $this->singleGroupLeague($teams, $legs);

        $this->getJson("/api/leagues/{$league->id}/draw")
            ->assertOk()
            ->assertJsonPath('data.can_draw', true)
            ->assertJsonPath('data.draw_blocked_reason', null)
            ->assertJsonPath('data.group_size', $teams)
            ->assertJsonPath('data.rounds_per_turn', $teams + $teams % 2 - 1)
            ->assertJsonPath('data.total_rounds', $totalRounds)
            ->assertJsonPath('data.next_round', 1);
    }

    public function test_single_group_draw_saves_group_one_for_every_team(): void
    {
        $this->authenticateAs('master');
        $league = $this->singleGroupLeague(5, 1);
        $first = $league->teams()->orderBy('teams.id')->first();
        $league->teams()->updateExistingPivot($first->id, ['group_number' => 1]);

        $this->drawRound($league, 1)->assertOk()->assertJsonPath('data.drawn_rounds', 1);

        $this->assertSame(
            [1, 1, 1, 1, 1],
            $league->teams()->orderBy('teams.id')->get()->map(fn ($team) => $team->pivot->group_number)->all(),
        );
        $this->assertSame(2, Game::where('league_id', $league->id)->where('group_number', 1)->count());
    }

    public function test_failed_single_group_draw_keeps_groups_unsaved(): void
    {
        $this->authenticateAs('master');
        $league = $this->singleGroupLeague(4, 1);
        [$home, $away] = $league->teams()->orderBy('teams.id')->pluck('teams.id')->all();
        Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $home, 'away_team_id' => $away, 'round' => 1]);

        $this->drawRound($league, 1)->assertStatus(422);

        $this->assertSame(4, $league->teams()->wherePivotNull('group_number')->count());
    }

    public function test_team_of_single_group_league_cannot_leave_after_the_draw(): void
    {
        $this->authenticateAs('master');
        $league = $this->singleGroupLeague(4, 1);
        $team = $league->teams()->first();

        $this->deleteJson("/api/teams/{$team->id}/leagues/{$league->id}")->assertOk();
        $league->teams()->attach($team->id);

        $this->drawRound($league, 1)->assertOk();

        $this->deleteJson("/api/teams/{$team->id}/leagues/{$league->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Não é possível remover o time da liga pois ele está em um grupo com rodadas sorteadas.');
    }

    public function test_teams_without_group_still_block_leagues_with_many_groups(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([2, 2]);
        $league->teams()->attach(Team::factory()->count(2)->create()->pluck('id'));

        $this->getJson("/api/leagues/{$league->id}/draw")
            ->assertOk()
            ->assertJsonPath('data.can_draw', false)
            ->assertJsonPath('data.draw_blocked_reason', 'Todos os times da liga precisam estar em um grupo antes do sorteio.')
            ->assertJsonPath('data.group_size', null)
            ->assertJsonPath('data.total_rounds', null);
    }

    private function manualGames(League $league): array
    {
        [$first, $second, $third, $fourth] = $this->groupTeamIds($league, 1);
        $game = fn (array $attributes) => Game::factory()->create(['league_id' => $league->id, ...$attributes]);

        $scheduled = $game(['home_team_id' => $first, 'away_team_id' => $second, 'round' => 1]);
        $finished = $game(['home_team_id' => $third, 'away_team_id' => $fourth, 'round' => 1, 'status' => 'finished', 'home_score' => 2, 'away_score' => 1]);
        $withEvent = $game(['home_team_id' => $first, 'away_team_id' => $third, 'round' => 2]);
        $player = Player::factory()->create(['team_id' => $first]);
        $event = GameEvent::factory()->create(['game_id' => $withEvent->id, 'league_id' => $league->id, 'team_id' => $first, 'player_id' => $player->id]);
        $knockout = $game(['home_team_id' => $second, 'away_team_id' => $fourth, 'round' => null, 'phase' => 'Final']);

        return compact('scheduled', 'finished', 'withEvent', 'event', 'knockout');
    }

    public function test_state_reports_manual_round_games(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4], self::ROUND_ROBIN);
        $this->manualGames($league);

        $this->getJson("/api/leagues/{$league->id}/draw")
            ->assertOk()
            ->assertJsonPath('data.manual_round_games', ['total' => 3, 'with_results' => 2])
            ->assertJsonPath('data.can_draw', true)
            ->assertJsonPath('data.draw_blocked_reason', null);
    }

    public function test_draw_without_confirmation_is_blocked_by_manual_round_games(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4], self::ROUND_ROBIN);
        $this->manualGames($league);
        $message = 'A liga já tem 3 partidas de rodada cadastradas manualmente; confirme a substituição para sortear.';

        $this->drawRound($league, 1)->assertStatus(422)->assertJsonPath('message', $message);
        $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => 1, 'replace_manual_rounds' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', $message);

        $this->assertSame(4, Game::where('league_id', $league->id)->count());
        $this->assertSame(0, Game::where('league_id', $league->id)->whereNotNull('group_number')->count());
    }

    public function test_draw_message_uses_singular_for_one_manual_game(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4]);
        [$home, $away] = $this->groupTeamIds($league, 1);
        Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $home, 'away_team_id' => $away, 'round' => 1]);

        $this->drawRound($league, 1)
            ->assertStatus(422)
            ->assertJsonPath('message', 'A liga já tem 1 partida de rodada cadastrada manualmente; confirme a substituição para sortear.');
    }

    public function test_confirmed_draw_replaces_manual_round_games(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4], self::ROUND_ROBIN);
        $games = $this->manualGames($league);
        $other = Game::factory()->create(['round' => 1]);

        $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => 1, 'replace_manual_rounds' => true])
            ->assertOk()
            ->assertJsonPath('data.drawn_rounds', 1)
            ->assertJsonPath('data.next_round', 2)
            ->assertJsonPath('data.manual_round_games', ['total' => 0, 'with_results' => 0]);

        foreach (['scheduled', 'finished', 'withEvent'] as $key) {
            $this->assertDatabaseMissing('games', ['id' => $games[$key]->id]);
        }
        $this->assertDatabaseMissing('game_events', ['id' => $games['event']->id]);
        $this->assertSame(0, GameEvent::where('league_id', $league->id)->count());
        $this->assertDatabaseHas('games', ['id' => $games['knockout']->id, 'phase' => 'Final', 'round' => null]);
        $this->assertDatabaseHas('games', ['id' => $other->id]);
        $drawn = Game::where('league_id', $league->id)->whereNotNull('group_number')->get();
        $this->assertSame([1], $drawn->pluck('round')->unique()->values()->all());
        $this->assertSame($this->groupTeamIds($league, 1), $drawn->flatMap(fn (Game $game) => [$game->home_team_id, $game->away_team_id])->map(fn ($id) => (int) $id)->sort()->values()->all());
        $this->assertSame(0, Game::where('league_id', $league->id)->whereNull('group_number')->whereNotNull('round')->count());

        $standings = collect($this->getJson("/api/leagues/{$league->id}/table")->assertOk()->json('data.standings'));
        $this->assertSame([0], $standings->pluck('played')->unique()->values()->all());
        $this->assertSame(0, $this->getJson("/api/leagues/{$league->id}/statistics")->json('data.totals.goals'));
    }

    public function test_replacement_flag_is_ignored_after_the_first_round(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4]);
        $this->drawRound($league, 1)->assertOk();

        $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => 2, 'replace_manual_rounds' => true])
            ->assertOk()
            ->assertJsonPath('data.drawn_rounds', 2);

        [$home, $away] = $this->groupTeamIds($league, 1);
        $manual = Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $home, 'away_team_id' => $away, 'round' => 3]);
        $message = 'A liga já tem 1 partida de rodada cadastrada manualmente; remova-a para continuar o sorteio.';

        $this->getJson("/api/leagues/{$league->id}/draw")
            ->assertJsonPath('data.can_draw', false)
            ->assertJsonPath('data.draw_blocked_reason', $message)
            ->assertJsonPath('data.manual_round_games', ['total' => 1, 'with_results' => 0]);
        $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => 3, 'replace_manual_rounds' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', $message);

        $this->assertDatabaseHas('games', ['id' => $manual->id]);
        $this->assertSame(4, Game::where('league_id', $league->id)->whereNotNull('group_number')->count());
    }

    public function test_failed_confirmed_draw_keeps_manual_round_games(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4], self::ROUND_ROBIN);
        $this->manualGames($league);

        $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => 2, 'replace_manual_rounds' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A próxima rodada a ser sorteada é a 1.');

        $league->forceFill(['fixture_mode' => 'manual'])->save();
        $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => 1, 'replace_manual_rounds' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O sorteio de rodadas só está disponível em ligas com sorteio.');

        $this->assertSame(4, Game::where('league_id', $league->id)->count());
        $this->assertSame(1, GameEvent::where('league_id', $league->id)->count());
    }

    public function test_replacement_flag_must_be_boolean(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4]);

        $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => 1, 'replace_manual_rounds' => 'talvez'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['replace_manual_rounds']);
    }

    public function test_replacement_follows_draw_authorization(): void
    {
        $own = $this->league([4], self::ROUND_ROBIN);
        $other = $this->league([4], self::ROUND_ROBIN);
        $this->manualGames($own);
        $this->manualGames($other);
        $payload = ['round' => 1, 'replace_manual_rounds' => true];

        $this->authenticateAs('admin', [$own->id]);
        $this->getJson("/api/leagues/{$other->id}/draw")->assertStatus(403);
        $this->postJson("/api/leagues/{$other->id}/draw/next-round", $payload)->assertStatus(403);
        $this->postJson("/api/leagues/{$own->id}/draw/next-round", $payload)->assertOk()->assertJsonPath('data.drawn_rounds', 1);
        $this->assertSame(0, Game::where('league_id', $own->id)->whereNull('group_number')->whereNotNull('round')->count());

        $this->authenticateAs('coach', [$other->id]);
        $this->getJson("/api/leagues/{$other->id}/draw")->assertOk()->assertJsonPath('data.manual_round_games.total', 3);
        $this->postJson("/api/leagues/{$other->id}/draw/next-round", $payload)->assertStatus(403);
        $this->assertSame(4, Game::where('league_id', $other->id)->count());
    }

    public function test_undo_last_round_returns_pairs_to_the_plan(): void
    {
        $this->authenticateAs('master');
        $this->useSeed(5);
        $league = $this->league([6]);

        $this->drawRound($league, 1)->assertOk();
        $this->drawRound($league, 2)->assertOk();
        $roundTwoPairs = Game::where('round', 2)->get()->map(fn (Game $game) => collect([$game->home_team_id, $game->away_team_id])->sort()->values()->all())->sort()->values()->all();

        $this->deleteJson("/api/leagues/{$league->id}/draw/last-round", ['round' => 2])
            ->assertOk()
            ->assertJsonPath('data.drawn_rounds', 1)
            ->assertJsonPath('data.next_round', 2);

        $this->assertSame(0, Game::where('round', 2)->count());
        $plan = LeagueDrawPlan::where('league_id', $league->id)->first()->rounds();
        $this->assertCount(4, $plan);
        $this->assertSame($roundTwoPairs, collect($plan[0])->map(fn ($pair) => collect($pair)->sort()->values()->all())->sort()->values()->all());

        for ($round = 2; $round <= 5; $round++) {
            $this->drawRound($league, $round)->assertOk();
        }
        $this->assertTurnIsValid($league, 1, $this->groupTeamIds($league, 1), 1, 5);
    }

    public function test_undo_can_go_back_to_the_start_and_through_the_return_leg(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([3], [...self::ROUND_ROBIN, 'group_legs' => 2]);
        $this->drawAll($league);

        for ($round = 6; $round >= 1; $round--) {
            $this->deleteJson("/api/leagues/{$league->id}/draw/last-round", ['round' => $round])
                ->assertOk()
                ->assertJsonPath('data.drawn_rounds', $round - 1);
        }

        $this->assertSame(0, Game::count());
        $this->assertSame(0, LeagueDrawPlan::count());

        $this->deleteJson("/api/leagues/{$league->id}/draw/last-round", ['round' => 1])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nenhuma rodada foi sorteada.');

        $this->drawAll($league);
        $this->assertTurnIsValid($league, 1, $this->groupTeamIds($league, 1), 1, 3);
        $this->assertTurnIsValid($league, 1, $this->groupTeamIds($league, 1), 4, 6);
    }

    public function test_undo_requires_the_last_round_number(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4]);
        $this->drawRound($league, 1)->assertOk();
        $this->drawRound($league, 2)->assertOk();

        $this->deleteJson("/api/leagues/{$league->id}/draw/last-round")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['round']);

        $this->deleteJson("/api/leagues/{$league->id}/draw/last-round", ['round' => 1])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A rodada 1 não é a última sorteada (última: 2).');

        $this->assertSame(4, Game::count());
    }

    public static function startedGames(): array
    {
        return [
            'em andamento' => ['in_progress', false],
            'encerrada' => ['finished', false],
            'cancelada' => ['cancelled', false],
            'com evento' => ['scheduled', true],
        ];
    }

    #[DataProvider('startedGames')]
    public function test_undo_and_reset_are_blocked_by_started_games(string $status, bool $withEvent): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4]);
        $this->drawRound($league, 1)->assertOk();
        $this->drawRound($league, 2)->assertOk();

        $game = Game::where('round', 2)->first();
        $game->update(['status' => $status]);
        if ($withEvent) {
            $player = Player::factory()->create(['team_id' => $game->home_team_id]);
            GameEvent::factory()->create(['game_id' => $game->id, 'league_id' => $league->id, 'team_id' => $game->home_team_id, 'player_id' => $player->id]);
        }

        $this->getJson("/api/leagues/{$league->id}/draw")
            ->assertJsonPath('data.can_undo', false)
            ->assertJsonPath('data.undo_blocked_reason', 'A rodada 2 não pode ser desfeita porque tem partidas que já começaram ou têm eventos.')
            ->assertJsonPath('data.can_reset', false)
            ->assertJsonPath('data.reset_blocked_reason', 'O sorteio não pode ser reiniciado porque há partidas de grupo que já começaram ou têm eventos.');

        $this->deleteJson("/api/leagues/{$league->id}/draw/last-round", ['round' => 2])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A rodada 2 não pode ser desfeita porque tem partidas que já começaram ou têm eventos.');
        $this->deleteJson("/api/leagues/{$league->id}/draw")
            ->assertStatus(422)
            ->assertJsonPath('message', 'O sorteio não pode ser reiniciado porque há partidas de grupo que já começaram ou têm eventos.');

        $this->assertSame(4, Game::where('league_id', $league->id)->count());
    }

    public function test_undo_is_allowed_when_only_an_earlier_round_started(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4]);
        $this->drawRound($league, 1)->assertOk();
        $this->drawRound($league, 2)->assertOk();
        Game::where('round', 1)->update(['status' => 'finished', 'home_score' => 1, 'away_score' => 0]);

        $this->deleteJson("/api/leagues/{$league->id}/draw/last-round", ['round' => 2])->assertOk();
        $this->deleteJson("/api/leagues/{$league->id}/draw")->assertStatus(422);
    }

    public function test_reset_removes_every_drawn_round_and_plan(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4, 4]);
        $this->drawRound($league, 1)->assertOk();
        $this->drawRound($league, 2)->assertOk();
        [$home, $away] = $this->groupTeamIds($league, 1);
        $knockout = Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $home, 'away_team_id' => $away, 'round' => null, 'phase' => 'Final']);

        $this->deleteJson("/api/leagues/{$league->id}/draw")
            ->assertOk()
            ->assertJsonPath('data.drawn_rounds', 0)
            ->assertJsonPath('data.next_round', 1)
            ->assertJsonPath('data.can_draw', true);

        $this->assertSame([$knockout->id], Game::pluck('id')->all());
        $this->assertSame(0, LeagueDrawPlan::count());

        $this->deleteJson("/api/leagues/{$league->id}/draw")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nenhuma rodada foi sorteada.');

        $this->drawRound($league, 1)->assertOk();
    }

    public function test_groups_are_locked_after_the_draw(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4, 4]);
        $this->drawRound($league, 1)->assertOk();

        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")->assertStatus(422);
        $this->getJson("/api/leagues/{$league->id}/groups")->assertJsonPath('data.locked', true);
    }

    public function test_deleting_the_league_removes_its_plans(): void
    {
        $this->authenticateAs('master');
        $league = $this->league([4]);
        $this->drawRound($league, 1)->assertOk();

        $league->delete();

        $this->assertSame(0, LeagueDrawPlan::count());
    }

    public function test_admin_can_draw_only_in_own_league(): void
    {
        $own = $this->league([4]);
        $other = $this->league([4]);
        $this->authenticateAs('admin', [$own->id]);

        $this->getJson("/api/leagues/{$own->id}/draw")->assertOk();
        $this->drawRound($own, 1)->assertOk();
        $this->deleteJson("/api/leagues/{$own->id}/draw/last-round", ['round' => 1])->assertOk();
        $this->drawRound($own, 1)->assertOk();
        $this->deleteJson("/api/leagues/{$own->id}/draw")->assertOk();

        $this->getJson("/api/leagues/{$other->id}/draw")->assertStatus(403);
        $this->drawRound($other, 1)->assertStatus(403);
        $this->deleteJson("/api/leagues/{$other->id}/draw/last-round", ['round' => 1])->assertStatus(403);
        $this->deleteJson("/api/leagues/{$other->id}/draw")->assertStatus(403);
    }

    public function test_coach_can_read_but_not_draw(): void
    {
        $league = $this->league([4]);
        $this->authenticateAs('coach', [$league->id]);

        $this->getJson("/api/leagues/{$league->id}/draw")->assertOk();
        $this->drawRound($league, 1)->assertStatus(403);
        $this->deleteJson("/api/leagues/{$league->id}/draw/last-round", ['round' => 1])->assertStatus(403);
        $this->deleteJson("/api/leagues/{$league->id}/draw")->assertStatus(403);
    }

    public function test_unknown_league_returns_422(): void
    {
        $this->authenticateAs('master');

        $this->getJson('/api/leagues/999/draw')->assertStatus(422)->assertJsonPath('message', 'Liga não encontrada!');
        $this->postJson('/api/leagues/999/draw/next-round', ['round' => 1])->assertStatus(422)->assertJsonPath('message', 'Liga não encontrada!');
    }
}
