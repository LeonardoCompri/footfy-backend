<?php

namespace Tests\Unit;

use App\Services\Knockout\KnockoutBracket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KnockoutBracketTest extends TestCase
{
    private function groupTeams(int $groupsCount, int $qualifiers): array
    {
        $groups = [];

        for ($group = 1; $group <= $groupsCount; $group++) {
            $groups[$group] = array_map(fn (int $position) => $group * 100 + $position, range(1, $qualifiers));
        }

        return $groups;
    }

    private function labels(array $pairs, bool $singleGroup = false): array
    {
        return array_map(fn (array $pair) => implode('x', array_map(
            fn (array $slot) => $singleGroup ? (string) $slot['position'] : $slot['position'].chr(64 + $slot['group_number']),
            $pair,
        )), $pairs);
    }

    public function test_seed_order_follows_the_standard_bracket(): void
    {
        $bracket = new KnockoutBracket;

        $this->assertSame([1, 2], $bracket->seedOrder(2));
        $this->assertSame([1, 4, 2, 3], $bracket->seedOrder(4));
        $this->assertSame([1, 8, 4, 5, 2, 7, 3, 6], $bracket->seedOrder(8));
        $this->assertSame([1, 16, 8, 9, 4, 13, 5, 12, 2, 15, 7, 10, 3, 14, 6, 11], $bracket->seedOrder(16));
    }

    public static function crossings(): array
    {
        return [
            '2 grupos x 1' => [2, 1, ['1Ax1B']],
            '2 grupos x 2' => [2, 2, ['1Ax2B', '1Bx2A']],
            '4 grupos x 1' => [4, 1, ['1Ax1B', '1Cx1D']],
            '4 grupos x 2' => [4, 2, ['1Ax2B', '1Cx2D', '1Bx2A', '1Dx2C']],
            '2 grupos x 4' => [2, 4, ['1Ax4B', '2Bx3A', '1Bx4A', '2Ax3B']],
            '8 grupos x 2' => [8, 2, ['1Ax2B', '1Cx2D', '1Ex2F', '1Gx2H', '1Bx2A', '1Dx2C', '1Fx2E', '1Hx2G']],
            '4 grupos x 4' => [4, 4, ['1Ax4B', '1Cx4D', '2Bx3A', '2Dx3C', '1Bx4A', '1Dx4C', '2Ax3B', '2Cx3D']],
            '2 grupos x 8' => [2, 8, ['1Ax8B', '4Bx5A', '2Bx7A', '3Ax6B', '1Bx8A', '4Ax5B', '2Ax7B', '3Bx6A']],
        ];
    }

    #[DataProvider('crossings')]
    public function test_groups_cross_in_the_documented_order(int $groupsCount, int $qualifiers, array $expected): void
    {
        $pairs = (new KnockoutBracket)->firstStagePairs($this->groupTeams($groupsCount, $qualifiers), $qualifiers);

        $this->assertSame($expected, $this->labels($pairs));
    }

    #[DataProvider('crossings')]
    public function test_teams_of_the_same_group_are_spread_across_the_bracket(int $groupsCount, int $qualifiers): void
    {
        $pairs = (new KnockoutBracket)->firstStagePairs($this->groupTeams($groupsCount, $qualifiers), $qualifiers);
        $slots = array_merge(...$pairs);
        $size = count($slots);

        $this->assertSame($groupsCount * $qualifiers, $size);
        $this->assertSame($size, count(array_unique(array_column($slots, 'team_id'))));

        foreach ($pairs as [$first, $second]) {
            $this->assertNotSame($first['group_number'], $second['group_number'], 'Times do mesmo grupo se enfrentam na primeira fase.');
        }

        $byGroup = [];
        foreach ($slots as $index => $slot) {
            $byGroup[$slot['group_number']][$slot['position']] = $index;
        }

        foreach ($byGroup as $positions) {
            if (isset($positions[2])) {
                $this->assertNotSame(intdiv($positions[1], $size / 2), intdiv($positions[2], $size / 2), '1º e 2º do mesmo grupo na mesma metade.');
            }

            $sections = array_map(fn (int $index) => intdiv($index, intdiv($size, $qualifiers)), $positions);
            $this->assertSame(count($sections), count(array_unique($sections)), 'Times do mesmo grupo na mesma seção da chave.');
        }
    }

    public static function singleGroup(): array
    {
        return [
            '2 times' => [2, ['1x2']],
            '4 times' => [4, ['1x4', '2x3']],
            '8 times' => [8, ['1x8', '4x5', '2x7', '3x6']],
            '16 times' => [16, ['1x16', '8x9', '4x13', '5x12', '2x15', '7x10', '3x14', '6x11']],
        ];
    }

    #[DataProvider('singleGroup')]
    public function test_single_group_follows_the_standard_seed_order(int $qualifiers, array $expected): void
    {
        $pairs = (new KnockoutBracket)->firstStagePairs($this->groupTeams(1, $qualifiers), $qualifiers);

        $this->assertSame($expected, $this->labels($pairs, singleGroup: true));
    }

    public function test_steps_start_at_the_knockout_size_and_put_third_place_with_the_final(): void
    {
        $bracket = new KnockoutBracket;

        $this->assertSame([['R16'], ['QF'], ['SF'], ['F', '3P']], $bracket->steps(16, true));
        $this->assertSame([['QF'], ['SF'], ['F']], $bracket->steps(8, false));
        $this->assertSame([['SF'], ['F', '3P']], $bracket->steps(4, true));
        $this->assertSame([['F']], $bracket->steps(2, true));
    }

    public function test_phase_order_puts_third_place_before_the_final(): void
    {
        $orders = array_map(fn (array $stage) => $stage['phase_order'], KnockoutBracket::STAGES);

        $this->assertSame(['R16' => 1, 'QF' => 2, 'SF' => 3, '3P' => 4, 'F' => 5], $orders);
    }

    public function test_later_stages_put_third_place_with_the_final(): void
    {
        $this->assertSame(['QF', 'SF', 'F', '3P'], KnockoutBracket::laterStages('R16'));
        $this->assertSame(['SF', 'F', '3P'], KnockoutBracket::laterStages('QF'));
        $this->assertSame(['F', '3P'], KnockoutBracket::laterStages('SF'));
        $this->assertSame([], KnockoutBracket::laterStages('F'));
        $this->assertSame([], KnockoutBracket::laterStages('3P'));
        $this->assertSame([], KnockoutBracket::laterStages('XX'));
    }
}
