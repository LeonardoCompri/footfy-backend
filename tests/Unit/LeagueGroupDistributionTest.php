<?php

namespace Tests\Unit;

use App\Models\League;
use App\Repositories\LeagueRepository;
use App\Services\LeagueGroupService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LeagueGroupDistributionTest extends TestCase
{
    public static function distributions(): array
    {
        return [
            'um grupo' => [5, 1],
            'dois grupos' => [8, 2],
            'quatro grupos' => [16, 4],
            'oito grupos' => [32, 8],
        ];
    }

    #[DataProvider('distributions')]
    public function test_distribute_assigns_every_team_to_groups_of_equal_size(int $teams, int $groups): void
    {
        $service = new LeagueGroupService(new LeagueRepository);
        $teamIds = range(101, 100 + $teams);

        $groupByTeam = $service->distribute($teamIds, $groups);

        $this->assertEqualsCanonicalizing($teamIds, array_keys($groupByTeam));
        $sizes = array_count_values($groupByTeam);
        ksort($sizes);
        $this->assertSame(array_fill(1, $groups, intdiv($teams, $groups)), $sizes);
    }

    public function test_distribute_shuffles_the_teams(): void
    {
        $service = new LeagueGroupService(new LeagueRepository);
        $teamIds = range(1, 16);
        $results = [];

        for ($i = 0; $i < 20; $i++) {
            $results[] = json_encode($service->distribute($teamIds, 4));
        }

        $this->assertGreaterThan(1, count(array_unique($results)));
    }

    public function test_default_tiebreakers_start_with_points(): void
    {
        $this->assertSame('points', League::DEFAULT_TIEBREAKERS[0]);
        $this->assertSame([], array_diff(League::DEFAULT_TIEBREAKERS, League::TIEBREAKERS));
        $this->assertSame(['penalties', 'better_campaign', 'away_goals'], League::knockoutTiebreakerValues());
    }

    public function test_effective_tiebreakers_fall_back_to_default(): void
    {
        $league = new League;

        $this->assertSame(League::DEFAULT_TIEBREAKERS, $league->effectiveTiebreakers());

        $league->tiebreakers = ['points', 'goals_for'];

        $this->assertSame(['points', 'goals_for'], $league->effectiveTiebreakers());
    }
}
