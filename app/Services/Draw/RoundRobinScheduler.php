<?php

namespace App\Services\Draw;

use Closure;
use Random\Randomizer;

class RoundRobinScheduler
{
    public const BYE = 0;

    private const CLOCK_CHECK_INTERVAL = 64;

    private readonly Randomizer $randomizer;

    private readonly int $budgetMs;

    private readonly int $maxSteps;

    private readonly Closure $clock;

    private int $steps = 0;

    private float $deadline = 0.0;

    public function __construct(
        ?Randomizer $randomizer = null,
        ?int $budgetMs = null,
        ?int $maxSteps = null,
        ?Closure $clock = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer;
        $this->budgetMs = $budgetMs ?? (int) config('draw.search_budget_ms');
        $this->maxSteps = $maxSteps ?? (int) config('draw.search_max_steps');
        $this->clock = $clock ?? fn (): float => microtime(true);
    }

    public static function roundsPerTurn(int $teamsCount): int
    {
        return $teamsCount + $teamsCount % 2 - 1;
    }

    public function circlePlan(array $teamIds): array
    {
        $slots = $this->shuffle($this->withBye($teamIds));
        $size = count($slots);
        $rounds = [];

        for ($round = 0; $round < $size - 1; $round++) {
            $pairs = [];
            for ($index = 0; $index < intdiv($size, 2); $index++) {
                $pairs[] = [$slots[$index], $slots[$size - 1 - $index]];
            }

            $rounds[] = $pairs;
            $last = array_pop($slots);
            array_splice($slots, 1, 0, [$last]);
        }

        return $this->shuffle($rounds);
    }

    public function withByes(array $teamIds, array $rounds): array
    {
        if (count($teamIds) % 2 === 0) {
            return array_values($rounds);
        }

        return array_values(array_map(function (array $pairs) use ($teamIds) {
            $playing = array_map('intval', $pairs ? array_merge(...$pairs) : []);

            if (in_array(self::BYE, $playing, true)) {
                return $pairs;
            }

            foreach (array_diff($teamIds, $playing) as $teamId) {
                $pairs[] = [(int) $teamId, self::BYE];
            }

            return $pairs;
        }, $rounds));
    }

    public function repeatedPair(array $rounds): ?array
    {
        $seen = [];

        foreach ($rounds as $pairs) {
            foreach ($pairs as [$first, $second]) {
                $key = $this->pairKey($first, $second);

                if (isset($seen[$key])) {
                    return [(int) $first, (int) $second];
                }

                $seen[$key] = true;
            }
        }

        return null;
    }

    public function complete(array $teamIds, array $playedRounds, int $roundsLeft): ?array
    {
        $this->steps = 0;
        $this->deadline = ($this->clock)() + $this->budgetMs / 1000;

        $adjacency = $this->remainingGraph($teamIds, $playedRounds);

        if ($adjacency === null) {
            return null;
        }

        foreach ($adjacency as $neighbors) {
            if (count($neighbors) !== $roundsLeft) {
                return null;
            }
        }

        if ($roundsLeft === 0) {
            return [];
        }

        if ($this->budgetMs <= 0 || $this->maxSteps <= 0) {
            return null;
        }

        $result = $this->searchRound($adjacency, array_fill_keys(array_keys($adjacency), true), [], $roundsLeft);

        return is_array($result) ? $result : null;
    }

    public function nextRound(array $teamIds, array $playedRounds, array $plan): array
    {
        $completion = $this->complete($teamIds, $playedRounds, count($plan));

        if ($completion !== null) {
            $round = array_shift($completion);

            return ['round' => $round, 'plan' => $completion];
        }

        $index = $this->randomizer->getInt(0, count($plan) - 1);
        $round = $plan[$index];
        array_splice($plan, $index, 1);

        return ['round' => $round, 'plan' => array_values($plan)];
    }

    public function orient(array $teamIds, array $playedGames, array $pairs, array $awayLastRound): array
    {
        $teamIds = array_map('intval', array_values($teamIds));
        $teamsCount = count($teamIds);
        $low = intdiv($teamsCount - 1, 2);
        $high = intdiv($teamsCount, 2);

        $home = array_fill_keys($teamIds, 0);
        $undecided = [];

        foreach ($teamIds as $index => $first) {
            foreach (array_slice($teamIds, $index + 1) as $second) {
                $undecided[$this->pairKey($first, $second)] = [$first, $second];
            }
        }

        foreach ($playedGames as [$homeTeamId, $awayTeamId]) {
            $home[(int) $homeTeamId] = ($home[(int) $homeTeamId] ?? 0) + 1;
            unset($undecided[$this->pairKey($homeTeamId, $awayTeamId)]);
        }

        $awayLastRound = array_flip(array_map('intval', $awayLastRound));
        $games = [];

        foreach ($pairs as [$first, $second]) {
            $first = (int) $first;
            $second = (int) $second;

            if ($first === self::BYE || $second === self::BYE) {
                continue;
            }

            unset($undecided[$this->pairKey($first, $second)]);

            $preferred = $this->preferredHome($first, $second, $home, $awayLastRound);
            $other = $preferred === $first ? $second : $first;
            $chosen = $preferred;

            if (! $this->canBalance($undecided, $this->withHomeGame($home, $preferred), $low, $high)
                && $this->canBalance($undecided, $this->withHomeGame($home, $other), $low, $high)) {
                $chosen = $other;
            }

            $home[$chosen]++;
            $games[] = [$chosen, $chosen === $first ? $second : $first];
        }

        return $games;
    }

    private function withHomeGame(array $home, int $teamId): array
    {
        $home[$teamId]++;

        return $home;
    }

    private function preferredHome(int $first, int $second, array $home, array $awayLastRound): int
    {
        if ($home[$first] !== $home[$second]) {
            return $home[$first] < $home[$second] ? $first : $second;
        }

        $firstAway = isset($awayLastRound[$first]);
        $secondAway = isset($awayLastRound[$second]);

        if ($firstAway !== $secondAway) {
            return $firstAway ? $first : $second;
        }

        return $this->randomizer->getInt(0, 1) === 0 ? $first : $second;
    }

    private function canBalance(array $edges, array $home, int $low, int $high): bool
    {
        $degree = array_fill_keys(array_keys($home), 0);

        foreach ($edges as [$first, $second]) {
            $degree[$first]++;
            $degree[$second]++;
        }

        $homeCapacity = [];
        $awayCapacity = [];

        foreach ($home as $teamId => $count) {
            $minimum = max(0, $low - $count);
            $maximum = $high - $count;

            if ($maximum < 0 || $minimum > $degree[$teamId]) {
                return false;
            }

            $homeCapacity[$teamId] = min($maximum, $degree[$teamId]);
            $awayCapacity[$teamId] = $degree[$teamId] - $minimum;
        }

        $edges = array_values($edges);

        return $this->assignsEveryEdge($edges, $homeCapacity) && $this->assignsEveryEdge($edges, $awayCapacity);
    }

    private function assignsEveryEdge(array $edges, array $capacity): bool
    {
        $assigned = array_fill_keys(array_keys($capacity), []);

        foreach (array_keys($edges) as $edge) {
            $visited = [];

            if (! $this->augment($edge, $edges, $capacity, $assigned, $visited)) {
                return false;
            }
        }

        return true;
    }

    private function augment(int $edge, array $edges, array $capacity, array &$assigned, array &$visited): bool
    {
        foreach ($edges[$edge] as $teamId) {
            if (isset($visited[$teamId])) {
                continue;
            }

            $visited[$teamId] = true;

            if (count($assigned[$teamId]) < $capacity[$teamId]) {
                $assigned[$teamId][$edge] = true;

                return true;
            }

            foreach (array_keys($assigned[$teamId]) as $current) {
                $alternative = $edges[$current][0] === $teamId ? $edges[$current][1] : $edges[$current][0];

                if (isset($visited[$alternative])) {
                    continue;
                }

                unset($assigned[$teamId][$current]);

                if ($this->augment($current, $edges, $capacity, $assigned, $visited)) {
                    $assigned[$teamId][$edge] = true;

                    return true;
                }

                $assigned[$teamId][$current] = true;
            }
        }

        return false;
    }

    private function remainingGraph(array $teamIds, array $playedRounds): ?array
    {
        $vertices = $this->shuffle($this->withBye($teamIds));
        $adjacency = array_fill_keys($vertices, []);

        foreach ($vertices as $first) {
            foreach ($vertices as $second) {
                if ($first !== $second) {
                    $adjacency[$first][$second] = true;
                }
            }
        }

        foreach ($playedRounds as $pairs) {
            foreach ($pairs as [$first, $second]) {
                $first = (int) $first;
                $second = (int) $second;

                if (! isset($adjacency[$first][$second])) {
                    return null;
                }

                unset($adjacency[$first][$second], $adjacency[$second][$first]);
            }
        }

        return $adjacency;
    }

    private function searchRound(array $adjacency, array $free, array $pairs, int $roundsLeft): array|false|null
    {
        if ($this->budgetExhausted()) {
            return null;
        }

        if (empty($free)) {
            foreach ($pairs as [$first, $second]) {
                unset($adjacency[$first][$second], $adjacency[$second][$first]);
            }

            if ($roundsLeft === 1) {
                return [$pairs];
            }

            if ($roundsLeft === 3 && ! $this->hasOnlyEvenCycles($adjacency)) {
                return false;
            }

            $rest = $this->searchRound($adjacency, array_fill_keys(array_keys($adjacency), true), [], $roundsLeft - 1);

            if (! is_array($rest)) {
                return $rest;
            }

            array_unshift($rest, $pairs);

            return $rest;
        }

        $vertex = null;
        $options = null;

        foreach (array_keys($free) as $candidate) {
            $candidateOptions = array_values(array_filter(
                array_keys($adjacency[$candidate]),
                fn ($neighbor) => isset($free[$neighbor]),
            ));

            if ($options === null || count($candidateOptions) < count($options)) {
                $vertex = $candidate;
                $options = $candidateOptions;

                if (empty($options)) {
                    return false;
                }
            }
        }

        foreach ($this->shuffle($options) as $partner) {
            $remaining = $free;
            unset($remaining[$vertex], $remaining[$partner]);

            $result = $this->searchRound($adjacency, $remaining, [...$pairs, [$vertex, $partner]], $roundsLeft);

            if ($result !== false) {
                return $result;
            }
        }

        return false;
    }

    private function hasOnlyEvenCycles(array $adjacency): bool
    {
        $seen = [];

        foreach (array_keys($adjacency) as $start) {
            if (isset($seen[$start])) {
                continue;
            }

            $size = 0;
            $stack = [$start];
            $seen[$start] = true;

            while ($stack) {
                $vertex = array_pop($stack);
                $size++;

                foreach (array_keys($adjacency[$vertex]) as $neighbor) {
                    if (! isset($seen[$neighbor])) {
                        $seen[$neighbor] = true;
                        $stack[] = $neighbor;
                    }
                }
            }

            if ($size % 2 !== 0) {
                return false;
            }
        }

        return true;
    }

    private function budgetExhausted(): bool
    {
        $this->steps++;

        if ($this->steps > $this->maxSteps) {
            return true;
        }

        return $this->steps % self::CLOCK_CHECK_INTERVAL === 0 && ($this->clock)() > $this->deadline;
    }

    private function withBye(array $teamIds): array
    {
        $teamIds = array_map('intval', array_values($teamIds));

        if (count($teamIds) % 2 !== 0) {
            $teamIds[] = self::BYE;
        }

        return $teamIds;
    }

    private function shuffle(array $items): array
    {
        $items = array_values($items);

        for ($index = count($items) - 1; $index > 0; $index--) {
            $swap = $this->randomizer->getInt(0, $index);
            [$items[$index], $items[$swap]] = [$items[$swap], $items[$index]];
        }

        return $items;
    }

    private function pairKey(int|string $first, int|string $second): string
    {
        $first = (int) $first;
        $second = (int) $second;

        return $first < $second ? "{$first}-{$second}" : "{$second}-{$first}";
    }
}
