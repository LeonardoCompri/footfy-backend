<?php

namespace Tests\Unit;

use App\Services\Knockout\TieResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TieResolverTest extends TestCase
{
    private const SEEDS = [1 => 3, 2 => 1];

    private function leg(int $id, ?int $leg, int $home, int $away, ?int $homeScore, ?int $awayScore, string $status = 'finished', ?int $homePenalties = null, ?int $awayPenalties = null): array
    {
        return [
            'id' => $id,
            'leg' => $leg,
            'home_team_id' => $home,
            'away_team_id' => $away,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'home_penalties' => $homePenalties,
            'away_penalties' => $awayPenalties,
            'status' => $status,
        ];
    }

    private function resolve(array $legs, array $sequence): array
    {
        return (new TieResolver)->resolve($legs, $sequence, self::SEEDS);
    }

    public static function sequences(): array
    {
        return array_map(fn (array $sequence) => [$sequence], [
            'pênaltis' => ['penalties'],
            'melhor campanha' => ['better_campaign'],
            'gol fora e pênaltis' => ['away_goals', 'penalties'],
            'gol fora e melhor campanha' => ['away_goals', 'better_campaign'],
        ]);
    }

    #[DataProvider('sequences')]
    public function test_aggregate_decides_first_in_every_sequence(array $sequence): void
    {
        $single = $this->resolve([$this->leg(10, null, 2, 1, 0, 1)], $sequence);
        $this->assertSame(1, $single['winner_team_id']);
        $this->assertSame(2, $single['loser_team_id']);
        $this->assertSame('aggregate', $single['decided_by']);
        $this->assertFalse($single['awaiting_penalties']);

        $twoLegs = $this->resolve([$this->leg(10, 1, 1, 2, 2, 0), $this->leg(11, 2, 2, 1, 1, 0)], $sequence);
        $this->assertSame(1, $twoLegs['winner_team_id']);
        $this->assertSame('aggregate', $twoLegs['decided_by']);
        $this->assertSame([1 => 2, 2 => 1], $twoLegs['aggregate']);
        $this->assertSame(11, $twoLegs['decisive_game_id']);
    }

    public function test_penalties_sequence_waits_for_penalties_and_then_uses_them(): void
    {
        $waiting = $this->resolve([$this->leg(10, null, 2, 1, 1, 1)], ['penalties']);
        $this->assertTrue($waiting['finished']);
        $this->assertNull($waiting['winner_team_id']);
        $this->assertNull($waiting['decided_by']);
        $this->assertTrue($waiting['awaiting_penalties']);
        $this->assertTrue($waiting['penalties_reachable']);

        $decided = $this->resolve([$this->leg(10, null, 2, 1, 1, 1, 'finished', 3, 4)], ['penalties']);
        $this->assertSame(1, $decided['winner_team_id']);
        $this->assertSame('penalties', $decided['decided_by']);
        $this->assertFalse($decided['awaiting_penalties']);
        $this->assertSame(3, $decided['home_penalties']);
        $this->assertSame(4, $decided['away_penalties']);

        $twoLegs = $this->resolve([$this->leg(10, 1, 1, 2, 1, 0), $this->leg(11, 2, 2, 1, 1, 0, 'finished', 5, 4)], ['penalties']);
        $this->assertSame(2, $twoLegs['winner_team_id']);
        $this->assertSame('penalties', $twoLegs['decided_by']);
    }

    public function test_better_campaign_breaks_the_tie(): void
    {
        $single = $this->resolve([$this->leg(10, null, 1, 2, 2, 2)], ['better_campaign']);
        $this->assertSame(2, $single['winner_team_id']);
        $this->assertSame('better_campaign', $single['decided_by']);
        $this->assertFalse($single['penalties_reachable']);

        $twoLegs = $this->resolve([$this->leg(10, 1, 1, 2, 1, 1), $this->leg(11, 2, 2, 1, 0, 0)], ['better_campaign']);
        $this->assertSame(2, $twoLegs['winner_team_id']);
        $this->assertSame('better_campaign', $twoLegs['decided_by']);
    }

    public function test_away_goals_only_count_in_two_legged_ties(): void
    {
        $twoLegs = [$this->leg(10, 1, 2, 1, 2, 1), $this->leg(11, 2, 1, 2, 1, 0)];

        $byAwayGoals = $this->resolve($twoLegs, ['away_goals', 'penalties']);
        $this->assertSame(1, $byAwayGoals['winner_team_id']);
        $this->assertSame('away_goals', $byAwayGoals['decided_by']);
        $this->assertFalse($byAwayGoals['penalties_reachable']);

        $single = $this->resolve([$this->leg(10, null, 2, 1, 1, 1)], ['away_goals', 'penalties']);
        $this->assertTrue($single['awaiting_penalties']);

        $singleCampaign = $this->resolve([$this->leg(10, null, 1, 2, 1, 1)], ['away_goals', 'better_campaign']);
        $this->assertSame(2, $singleCampaign['winner_team_id']);
        $this->assertSame('better_campaign', $singleCampaign['decided_by']);
    }

    public function test_level_away_goals_fall_through_to_the_next_criterion(): void
    {
        $twoLegs = [$this->leg(10, 1, 1, 2, 1, 1), $this->leg(11, 2, 2, 1, 1, 1)];

        $penalties = $this->resolve($twoLegs, ['away_goals', 'penalties']);
        $this->assertTrue($penalties['awaiting_penalties']);
        $this->assertSame(11, $penalties['decisive_game_id']);

        $campaign = $this->resolve($twoLegs, ['away_goals', 'better_campaign']);
        $this->assertSame(2, $campaign['winner_team_id']);
        $this->assertSame('better_campaign', $campaign['decided_by']);
    }

    public function test_unfinished_or_incomplete_tie_has_no_winner(): void
    {
        $inProgress = $this->resolve([$this->leg(10, 1, 1, 2, 3, 0), $this->leg(11, 2, 2, 1, 0, 0, 'in_progress')], ['penalties']);
        $this->assertFalse($inProgress['finished']);
        $this->assertNull($inProgress['winner_team_id']);
        $this->assertFalse($inProgress['awaiting_penalties']);
        $this->assertSame([1 => 3, 2 => 0], $inProgress['aggregate']);

        $missingLeg = $this->resolve([$this->leg(10, 1, 1, 2, 3, 0)], ['penalties']);
        $this->assertFalse($missingLeg['finished']);
        $this->assertNull($missingLeg['decisive_game_id']);

        $cancelled = $this->resolve([$this->leg(10, null, 1, 2, null, null, 'cancelled')], ['better_campaign']);
        $this->assertFalse($cancelled['finished']);
    }
}
