<?php

namespace App\Services\Knockout;

final class KnockoutBracket
{
    public const THIRD_PLACE = '3P';

    public const FINAL = 'F';

    public const STAGES = [
        'R16' => ['label' => 'Oitavas de final', 'teams' => 16, 'phase_order' => 1, 'article' => 'as oitavas de final', 'of' => 'das oitavas de final', 'plural' => true],
        'QF' => ['label' => 'Quartas de final', 'teams' => 8, 'phase_order' => 2, 'article' => 'as quartas de final', 'of' => 'das quartas de final', 'plural' => true],
        'SF' => ['label' => 'Semifinal', 'teams' => 4, 'phase_order' => 3, 'article' => 'as semifinais', 'of' => 'das semifinais', 'plural' => true],
        '3P' => ['label' => 'Disputa de 3º lugar', 'teams' => 2, 'phase_order' => 4, 'article' => 'a disputa de 3º lugar', 'of' => 'da disputa de 3º lugar', 'plural' => false],
        'F' => ['label' => 'Final', 'teams' => 2, 'phase_order' => 5, 'article' => 'a final', 'of' => 'da final', 'plural' => false],
    ];

    private const MAIN_STAGES = ['R16', 'QF', 'SF', 'F'];

    public static function stageKeys(): array
    {
        return array_keys(self::STAGES);
    }

    public static function stepKeys(): array
    {
        return self::MAIN_STAGES;
    }

    public function steps(int $size, bool $thirdPlace): array
    {
        $steps = [];

        foreach (self::MAIN_STAGES as $key) {
            if (self::STAGES[$key]['teams'] > $size) {
                continue;
            }

            $steps[] = [$key];
        }

        if ($thirdPlace && $size >= 4) {
            $steps[count($steps) - 1][] = self::THIRD_PLACE;
        }

        return $steps;
    }

    public function seedOrder(int $size): array
    {
        $order = [1];

        while (count($order) < $size) {
            $total = count($order) * 2;
            $next = [];

            foreach ($order as $seed) {
                $next[] = $seed;
                $next[] = $total + 1 - $seed;
            }

            $order = $next;
        }

        return $order;
    }

    public function firstStagePairs(array $groups, int $qualifiers): array
    {
        $groupNumbers = array_keys($groups);
        sort($groupNumbers);

        if (count($groupNumbers) === 1) {
            $positions = $this->seedOrder($qualifiers);
            $pairs = [];

            for ($index = 0; $index < count($positions); $index += 2) {
                $pairs[] = [
                    $this->slot($groups, $groupNumbers[0], $positions[$index]),
                    $this->slot($groups, $groupNumbers[0], $positions[$index + 1]),
                ];
            }

            return $pairs;
        }

        $couples = array_chunk($groupNumbers, 2);
        $seeds = $this->seedOrder($qualifiers * 2);
        $couplePairs = [];

        foreach ($couples as $couple) {
            $pairs = [];

            for ($index = 0; $index < count($seeds); $index += 2) {
                $pairs[] = [
                    $this->coupleSlot($groups, $couple, $seeds[$index]),
                    $this->coupleSlot($groups, $couple, $seeds[$index + 1]),
                ];
            }

            $couplePairs[] = $pairs;
        }

        $pairs = [];

        for ($tie = 0; $tie < $qualifiers; $tie++) {
            foreach ($couplePairs as $coupleTies) {
                $pairs[] = $coupleTies[$tie];
            }
        }

        return $pairs;
    }

    private function coupleSlot(array $groups, array $couple, int $seed): array
    {
        $groupNumber = $seed % 2 === 1 ? $couple[0] : $couple[1];

        return $this->slot($groups, $groupNumber, intdiv($seed + 1, 2));
    }

    private function slot(array $groups, int $groupNumber, int $position): array
    {
        return [
            'team_id' => (int) $groups[$groupNumber][$position - 1],
            'group_number' => $groupNumber,
            'position' => $position,
        ];
    }
}
