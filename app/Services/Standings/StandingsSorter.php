<?php

namespace App\Services\Standings;

use Illuminate\Support\Str;

final class StandingsSorter
{
    public function sort(array $rows, array $tiebreakers, array $games = [], array $cards = []): array
    {
        return $this->sortBlock(array_values($rows), array_values($tiebreakers), 0, $games, $cards);
    }

    private function sortBlock(array $rows, array $tiebreakers, int $index, array $games, array $cards): array
    {
        if (count($rows) <= 1) {
            return array_map(fn (array $row) => [...$row, 'tied' => false], $rows);
        }

        if ($index >= count($tiebreakers)) {
            usort($rows, fn (array $a, array $b) => $this->nameKey($a) <=> $this->nameKey($b));

            return array_map(fn (array $row) => [...$row, 'tied' => true], $rows);
        }

        $values = $this->values($tiebreakers[$index], $rows, $games, $cards);
        $blocks = [];

        foreach ($rows as $position => $row) {
            $blocks[$values[$position]][] = $row;
        }

        krsort($blocks);

        $sorted = [];
        foreach ($blocks as $block) {
            array_push($sorted, ...$this->sortBlock($block, $tiebreakers, $index + 1, $games, $cards));
        }

        return $sorted;
    }

    private function values(string $criterion, array $rows, array $games, array $cards): array
    {
        if ($criterion === 'head_to_head') {
            return $this->headToHeadPoints($rows, $games);
        }

        return array_map(fn (array $row) => match ($criterion) {
            'points', 'wins', 'goal_difference', 'goals_for' => (int) $row[$criterion],
            'goals_against' => -(int) $row['goals_against'],
            'fewer_red_cards' => -$this->cards($cards, $row, 'red'),
            'fewer_yellow_cards' => -$this->cards($cards, $row, 'yellow'),
            'fewer_cards' => -($this->cards($cards, $row, 'red') + $this->cards($cards, $row, 'yellow')),
            default => 0,
        }, $rows);
    }

    private function headToHeadPoints(array $rows, array $games): array
    {
        $points = array_fill_keys(array_map(fn (array $row) => (int) $row['team_id'], $rows), 0);

        foreach ($games as $game) {
            $homeTeamId = (int) $game['home_team_id'];
            $awayTeamId = (int) $game['away_team_id'];

            if (! isset($points[$homeTeamId], $points[$awayTeamId])) {
                continue;
            }

            $homeScore = (int) $game['home_score'];
            $awayScore = (int) $game['away_score'];

            if ($homeScore === $awayScore) {
                $points[$homeTeamId] += 1;
                $points[$awayTeamId] += 1;
            } elseif ($homeScore > $awayScore) {
                $points[$homeTeamId] += 3;
            } else {
                $points[$awayTeamId] += 3;
            }
        }

        return array_map(fn (array $row) => $points[(int) $row['team_id']], $rows);
    }

    private function cards(array $cards, array $row, string $color): int
    {
        return (int) ($cards[(int) $row['team_id']][$color] ?? 0);
    }

    private function nameKey(array $row): array
    {
        $name = (string) ($row['team']['name'] ?? '');

        return [Str::lower(Str::ascii($name)), $name, (int) $row['team_id']];
    }
}
