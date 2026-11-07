<?php

namespace Tests\Unit;

use App\Services\Draw\RoundRobinScheduler;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

class RoundRobinSchedulerTest extends TestCase
{
    private const SEEDS = [3, 17, 101, 2024, 65537];

    public static function groupSizes(): array
    {
        $cases = [];

        foreach (range(2, 20) as $size) {
            foreach ([200, 0] as $budget) {
                $cases["{$size} times, orçamento {$budget} ms"] = [$size, $budget];
            }
        }

        return $cases;
    }

    private function scheduler(int $seed, int $budgetMs = 200, int $maxSteps = 200000, ?Closure $clock = null): RoundRobinScheduler
    {
        return new RoundRobinScheduler(new Randomizer(new Mt19937($seed)), $budgetMs, $maxSteps, $clock);
    }

    private function simulateTurn(RoundRobinScheduler $scheduler, array $teamIds): array
    {
        $roundsPerTurn = RoundRobinScheduler::roundsPerTurn(count($teamIds));
        $plan = $scheduler->circlePlan($teamIds);
        $rounds = [];
        $games = [];
        $awayLastRound = [];

        for ($round = 1; $round <= $roundsPerTurn; $round++) {
            $result = $scheduler->nextRound($teamIds, $scheduler->withByes($teamIds, $rounds), $plan);
            $plan = $result['plan'];

            $oriented = $scheduler->orient($teamIds, $games, $result['round'], $awayLastRound);
            $awayLastRound = array_column($oriented, 1);
            $rounds[] = $oriented;
            array_push($games, ...$oriented);

            $this->assertCount($roundsPerTurn - $round, $plan);
        }

        return ['rounds' => $rounds, 'games' => $games];
    }

    #[DataProvider('groupSizes')]
    public function test_turn_has_no_repeated_pair_equal_games_single_bye_and_balanced_home(int $size, int $budget): void
    {
        foreach (self::SEEDS as $seed) {
            $scheduler = $this->scheduler($seed * 100 + $size, $budget);
            $teamIds = range(501, 500 + $size);

            ['rounds' => $rounds, 'games' => $games] = $this->simulateTurn($scheduler, $teamIds);
            $withByes = $scheduler->withByes($teamIds, $rounds);

            $this->assertNull($scheduler->repeatedPair($withByes), "Par repetido (seed {$seed}).");
            $this->assertCount(RoundRobinScheduler::roundsPerTurn($size), $rounds);

            $played = array_fill_keys($teamIds, 0);
            $home = array_fill_keys($teamIds, 0);
            $byes = array_fill_keys($teamIds, 0);

            foreach ($games as [$homeTeamId, $awayTeamId]) {
                $played[$homeTeamId]++;
                $played[$awayTeamId]++;
                $home[$homeTeamId]++;
            }

            foreach ($withByes as $pairs) {
                $teamsInRound = array_merge(...$pairs);
                $this->assertSame(count($teamsInRound), count(array_unique($teamsInRound)), 'Time jogando duas vezes na rodada.');

                foreach ($pairs as [$first, $second]) {
                    if ($second === RoundRobinScheduler::BYE) {
                        $byes[$first]++;
                    }
                }
            }

            foreach ($teamIds as $teamId) {
                $this->assertSame($size - 1, $played[$teamId]);
                $this->assertSame($size % 2, $byes[$teamId], 'Folga diferente de uma vez por turno.');

                $difference = abs($home[$teamId] - ($played[$teamId] - $home[$teamId]));
                $this->assertLessThanOrEqual($size % 2 === 1 ? 0 : 1, $difference, "Mando desequilibrado (seed {$seed}).");
            }
        }
    }

    public function test_circle_plan_is_a_complete_turn(): void
    {
        foreach ([2, 5, 8, 13, 20] as $size) {
            $scheduler = $this->scheduler($size);
            $teamIds = range(1, $size);
            $plan = $scheduler->circlePlan($teamIds);

            $this->assertCount(RoundRobinScheduler::roundsPerTurn($size), $plan);
            $this->assertNull($scheduler->repeatedPair($plan));

            foreach ($plan as $pairs) {
                $this->assertCount(intdiv($size + 1, 2), $pairs);
            }
        }
    }

    public function test_draw_is_random_but_reproducible_with_the_same_seed(): void
    {
        $teamIds = range(1, 10);

        $first = $this->simulateTurn($this->scheduler(7), $teamIds)['rounds'];
        $again = $this->simulateTurn($this->scheduler(7), $teamIds)['rounds'];
        $other = $this->simulateTurn($this->scheduler(8), $teamIds)['rounds'];

        $this->assertSame($first, $again);
        $this->assertNotSame($first, $other);
    }

    public function test_zero_budget_falls_back_to_a_round_of_the_stored_plan(): void
    {
        $scheduler = $this->scheduler(11, budgetMs: 0);
        $teamIds = range(1, 6);
        $plan = $scheduler->circlePlan($teamIds);

        $this->assertNull($scheduler->complete($teamIds, [], 5));

        $result = $scheduler->nextRound($teamIds, [], $plan);

        $this->assertContains($result['round'], $plan);
        $this->assertCount(4, $result['plan']);
        $this->assertEqualsCanonicalizing($plan, [$result['round'], ...$result['plan']]);
    }

    public function test_expired_clock_stops_the_search_and_uses_the_plan(): void
    {
        $now = 0.0;
        $clock = function () use (&$now) {
            $now += 10.0;

            return $now;
        };
        $scheduler = $this->scheduler(5, budgetMs: 200, clock: $clock);
        $teamIds = range(1, 20);

        $this->assertNull($scheduler->complete($teamIds, [], 19));

        $plan = $scheduler->circlePlan($teamIds);
        $result = $scheduler->nextRound($teamIds, [], $plan);

        $this->assertContains($result['round'], $plan);
    }

    public function test_step_budget_stops_the_search(): void
    {
        $scheduler = $this->scheduler(5, maxSteps: 3);

        $this->assertNull($scheduler->complete(range(1, 12), [], 11));
    }

    public function test_complete_rejects_states_that_cannot_be_finished(): void
    {
        $scheduler = $this->scheduler(1);
        $teamIds = [1, 2, 3, 4, 5];

        $twoTriangles = [
            [[1, 4], [2, 5], [3, 0]],
            [[1, 5], [2, 0], [3, 4]],
            [[1, 0], [2, 4], [3, 5]],
        ];
        $this->assertNull($scheduler->complete($teamIds, $twoTriangles, 2));

        $evenCycle = [
            [[1, 4], [2, 5], [3, 0]],
            [[1, 5], [2, 0], [3, 4]],
        ];
        $completion = $scheduler->complete($teamIds, $evenCycle, 3);
        $this->assertCount(3, $completion);
        $this->assertNull($scheduler->repeatedPair([...$evenCycle, ...$completion]));

        $this->assertNull($scheduler->complete($teamIds, [[[1, 2], [1, 3]]], 4));
        $this->assertNull($scheduler->complete($teamIds, $evenCycle, 2));
    }

    public function test_with_byes_adds_the_missing_team_only_in_odd_groups(): void
    {
        $scheduler = $this->scheduler(1);

        $this->assertSame([[[1, 2], [3, 0]]], $scheduler->withByes([1, 2, 3], [[[1, 2]]]));
        $this->assertSame([[[1, 2], [3, 4]]], $scheduler->withByes([1, 2, 3, 4], [[[1, 2], [3, 4]]]));
    }

    public function test_home_prefers_fewer_home_games_then_away_last_round(): void
    {
        $scheduler = $this->scheduler(1);
        $teamIds = [1, 2, 3, 4];

        $this->assertSame([[2, 1]], $scheduler->orient($teamIds, [[1, 3]], [[1, 2]], []));
        $this->assertSame([[2, 1]], $scheduler->orient($teamIds, [[3, 1], [4, 2]], [[1, 2]], [2]));
        $this->assertSame([[1, 2]], $scheduler->orient($teamIds, [[3, 1], [4, 2]], [[2, 1]], [1]));
    }

    public function test_twenty_teams_search_stays_well_under_the_budget(): void
    {
        $scheduler = $this->scheduler(99);
        $teamIds = range(1, 20);
        $start = microtime(true);

        $completion = $scheduler->complete($teamIds, [], 19);
        $elapsed = microtime(true) - $start;

        $this->assertCount(19, $completion);
        $this->assertNull($scheduler->repeatedPair($completion));
        $this->assertLessThan(0.2, $elapsed);
    }
}
