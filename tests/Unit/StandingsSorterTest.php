<?php

namespace Tests\Unit;

use App\Models\League;
use App\Services\Standings\StandingsSorter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StandingsSorterTest extends TestCase
{
    private function row(int $id, string $name, array $stats = []): array
    {
        $stats += ['points' => 0, 'wins' => 0, 'goals_for' => 0, 'goals_against' => 0];

        return [
            'team_id' => $id,
            'team' => ['id' => $id, 'name' => $name],
            'points' => $stats['points'],
            'wins' => $stats['wins'],
            'goals_for' => $stats['goals_for'],
            'goals_against' => $stats['goals_against'],
            'goal_difference' => $stats['goals_for'] - $stats['goals_against'],
        ];
    }

    private function game(int $home, int $away, int $homeScore, int $awayScore): array
    {
        return ['home_team_id' => $home, 'away_team_id' => $away, 'home_score' => $homeScore, 'away_score' => $awayScore];
    }

    private function order(array $sorted): array
    {
        return array_column($sorted, 'team_id');
    }

    private function sort(array $rows, array $tiebreakers, array $games = [], array $cards = []): array
    {
        return (new StandingsSorter)->sort($rows, $tiebreakers, $games, $cards);
    }

    public static function statCriteria(): array
    {
        return [
            'pontos' => ['points', ['points' => 3], ['points' => 1]],
            'vitórias' => ['wins', ['wins' => 2], ['wins' => 1]],
            'saldo' => ['goal_difference', ['goals_for' => 3, 'goals_against' => 1], ['goals_for' => 3, 'goals_against' => 2]],
            'gols marcados' => ['goals_for', ['goals_for' => 4, 'goals_against' => 2], ['goals_for' => 3, 'goals_against' => 1]],
            'menos gols sofridos' => ['goals_against', ['goals_for' => 2, 'goals_against' => 1], ['goals_for' => 3, 'goals_against' => 2]],
        ];
    }

    #[DataProvider('statCriteria')]
    public function test_each_stat_criterion_puts_the_better_team_first(string $criterion, array $better, array $worse): void
    {
        $rows = [$this->row(1, 'Alfa', $worse), $this->row(2, 'Beta', $better)];

        $sorted = $this->sort($rows, [$criterion]);

        $this->assertSame([2, 1], $this->order($sorted));
        $this->assertSame([false, false], array_column($sorted, 'tied'));
    }

    public static function cardCriteria(): array
    {
        return [
            'menos vermelhos' => ['fewer_red_cards', ['red' => 0, 'yellow' => 5], ['red' => 1, 'yellow' => 0]],
            'menos amarelos' => ['fewer_yellow_cards', ['red' => 3, 'yellow' => 1], ['red' => 0, 'yellow' => 2]],
            'menos cartões' => ['fewer_cards', ['red' => 1, 'yellow' => 1], ['red' => 0, 'yellow' => 3]],
        ];
    }

    #[DataProvider('cardCriteria')]
    public function test_each_card_criterion_puts_the_team_with_fewer_cards_first(string $criterion, array $better, array $worse): void
    {
        $rows = [$this->row(1, 'Alfa'), $this->row(2, 'Beta')];

        $sorted = $this->sort($rows, [$criterion], [], [1 => $worse, 2 => $better]);

        $this->assertSame([2, 1], $this->order($sorted));
        $this->assertSame([false, false], array_column($sorted, 'tied'));
    }

    public function test_missing_card_counts_mean_zero_cards(): void
    {
        $rows = [$this->row(1, 'Alfa'), $this->row(2, 'Beta')];

        $this->assertSame([2, 1], $this->order($this->sort($rows, ['fewer_cards'], [], [1 => ['yellow' => 1]])));
    }

    public function test_configured_order_changes_the_result(): void
    {
        $rows = [
            $this->row(1, 'Alfa', ['points' => 6, 'wins' => 2, 'goals_for' => 3, 'goals_against' => 1]),
            $this->row(2, 'Beta', ['points' => 6, 'wins' => 1, 'goals_for' => 6, 'goals_against' => 3]),
        ];

        $this->assertSame([1, 2], $this->order($this->sort($rows, League::DEFAULT_TIEBREAKERS)));
        $this->assertSame([2, 1], $this->order($this->sort($rows, ['points', 'goals_for', 'wins'])));
        $this->assertSame([2, 1], $this->order($this->sort($rows, ['points', 'goal_difference', 'wins'])));
    }

    public function test_criteria_only_apply_inside_tied_blocks(): void
    {
        $rows = [
            $this->row(1, 'Alfa', ['points' => 3, 'goals_for' => 9]),
            $this->row(2, 'Beta', ['points' => 6, 'goals_for' => 1]),
            $this->row(3, 'Gama', ['points' => 3, 'goals_for' => 2]),
        ];

        $this->assertSame([2, 1, 3], $this->order($this->sort($rows, ['points', 'goals_for'])));
    }

    public function test_head_to_head_between_two_teams(): void
    {
        $rows = [$this->row(1, 'Alfa', ['points' => 3]), $this->row(2, 'Beta', ['points' => 3])];
        $games = [$this->game(1, 2, 0, 1), $this->game(1, 3, 2, 0), $this->game(4, 2, 1, 0)];

        $sorted = $this->sort($rows, ['points', 'head_to_head'], $games);

        $this->assertSame([2, 1], $this->order($sorted));
        $this->assertSame([false, false], array_column($sorted, 'tied'));
    }

    public function test_head_to_head_counts_both_legs(): void
    {
        $rows = [$this->row(1, 'Alfa', ['points' => 4]), $this->row(2, 'Beta', ['points' => 4])];
        $games = [$this->game(1, 2, 1, 0), $this->game(2, 1, 1, 1), $this->game(1, 3, 2, 2), $this->game(2, 3, 1, 0)];

        $this->assertSame([1, 2], $this->order($this->sort($rows, ['points', 'head_to_head'], $games)));
    }

    public function test_head_to_head_mini_table_with_three_teams(): void
    {
        $rows = [
            $this->row(1, 'Alfa', ['points' => 4]),
            $this->row(2, 'Beta', ['points' => 4]),
            $this->row(3, 'Gama', ['points' => 4]),
            $this->row(4, 'Delta', ['points' => 4]),
        ];
        $games = [
            $this->game(3, 1, 2, 0),
            $this->game(3, 2, 1, 0),
            $this->game(1, 2, 1, 1),
            $this->game(4, 3, 5, 0),
        ];

        $sorted = $this->sort(array_slice($rows, 0, 3), ['points', 'head_to_head', 'wins'], $games);

        $this->assertSame([3, 1, 2], $this->order($sorted));
        $this->assertSame([false, true, true], array_column($sorted, 'tied'));
    }

    public function test_unresolved_mini_table_falls_through_to_the_next_criterion(): void
    {
        $rows = [
            $this->row(1, 'Alfa', ['points' => 3, 'goals_for' => 2]),
            $this->row(2, 'Beta', ['points' => 3, 'goals_for' => 4]),
            $this->row(3, 'Gama', ['points' => 3, 'goals_for' => 3]),
        ];
        $games = [$this->game(1, 2, 1, 0), $this->game(2, 3, 2, 0), $this->game(3, 1, 3, 1)];

        $this->assertSame([2, 3, 1], $this->order($this->sort($rows, ['points', 'head_to_head', 'goals_for'], $games)));
    }

    public function test_head_to_head_without_games_between_them_keeps_the_tie(): void
    {
        $rows = [$this->row(1, 'Alfa', ['points' => 3]), $this->row(2, 'Beta', ['points' => 3])];
        $games = [$this->game(1, 3, 1, 0), $this->game(2, 4, 1, 0)];

        $this->assertSame([true, true], array_column($this->sort($rows, ['points', 'head_to_head'], $games), 'tied'));
    }

    public function test_last_resort_is_team_name_and_marks_rows_as_tied(): void
    {
        $rows = [
            $this->row(1, 'Zebra', ['points' => 3]),
            $this->row(2, 'Ávila', ['points' => 3]),
            $this->row(3, 'Bahia', ['points' => 3]),
            $this->row(4, 'Líder', ['points' => 9]),
        ];

        $sorted = $this->sort($rows, League::DEFAULT_TIEBREAKERS);

        $this->assertSame([4, 2, 3, 1], $this->order($sorted));
        $this->assertSame([false, true, true, true], array_column($sorted, 'tied'));
    }

    public function test_same_name_falls_back_to_team_id(): void
    {
        $rows = [$this->row(7, 'Clube'), $this->row(3, 'Clube')];

        $this->assertSame([3, 7], $this->order($this->sort($rows, ['points'])));
    }

    public function test_keeps_every_field_of_the_row(): void
    {
        $row = [...$this->row(1, 'Alfa'), 'played' => 0, 'extra' => 'x'];

        $this->assertSame([...$row, 'tied' => false], $this->sort([$row], ['points'])[0]);
        $this->assertSame([], $this->sort([], ['points']));
    }
}
