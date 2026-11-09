<?php

namespace Tests\Unit;

use App\Services\Knockout\ShootoutResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShootoutResolverTest extends TestCase
{
    private const HOME = 10;

    private const AWAY = 20;

    private function kicks(string $first, string $second, bool $homeFirst = true): array
    {
        $teams = $homeFirst ? [self::HOME, self::AWAY] : [self::AWAY, self::HOME];
        $kicks = [];

        for ($i = 0; $i < max(strlen($first), strlen($second)); $i++) {
            foreach ([$first, $second] as $side => $pattern) {
                if ($i < strlen($pattern)) {
                    $kicks[] = ['team_id' => $teams[$side], 'scored' => $pattern[$i] === 'G'];
                }
            }
        }

        return $kicks;
    }

    private function resolve(array $kicks): array
    {
        return (new ShootoutResolver)->resolve($kicks, self::HOME, self::AWAY);
    }

    public static function shootouts(): array
    {
        return [
            'decidida nas cinco, antes do fim' => ['GGG', 'XXX', true, true, 3, 0, self::HOME, 6],
            'decidida na cobrança de quem bateu primeiro' => ['GGGG', 'XXG', false, true, 1, 4, self::AWAY, 7],
            'decidida na última das cinco' => ['GGGGX', 'GGGXX', true, true, 4, 3, self::HOME, 10],
            'morte súbita no primeiro par' => ['GGGGGG', 'GGGGGX', true, true, 6, 5, self::HOME, 12],
            'morte súbita depois de pares convertidos' => ['GGGGGGX', 'GGGGGGG', true, true, 6, 7, self::AWAY, 14],
            'morte súbita perdendo os dois' => ['GGGGGXG', 'GGGGGXX', true, true, 6, 5, self::HOME, 14],
            'inacabada nas cinco' => ['GGG', 'GG', true, false, 3, 2, null, null],
            'empatada nas cinco' => ['GGGGG', 'GGGGG', true, false, 5, 5, null, null],
            'morte súbita esperando a resposta' => ['GGGGGG', 'GGGGG', true, false, 6, 5, null, null],
            'cobrança depois da decisão' => ['GGGX', 'XXX', true, true, 3, 0, self::HOME, 6],
            'sem cobranças' => ['', '', true, false, 0, 0, null, null],
        ];
    }

    #[DataProvider('shootouts')]
    public function test_resolves_when_the_shootout_is_decided(string $first, string $second, bool $homeFirst, bool $finished, int $home, int $away, ?int $winner, ?int $decidedAt): void
    {
        $this->assertSame(
            ['finished' => $finished, 'home' => $home, 'away' => $away, 'winner' => $winner, 'decided_at' => $decidedAt],
            $this->resolve($this->kicks($first, $second, $homeFirst)),
        );
    }

    public function test_finished_shootouts_never_end_level(): void
    {
        foreach (self::shootouts() as [$first, $second, $homeFirst, $finished]) {
            $result = $this->resolve($this->kicks($first, $second, $homeFirst));

            if ($result['finished']) {
                $this->assertNotSame($result['home'], $result['away']);
            }
        }
    }

    public function test_kicks_of_other_teams_are_ignored(): void
    {
        $kicks = [...$this->kicks('GGG', 'XXX'), ['team_id' => 99, 'scored' => true]];

        $this->assertSame(['finished' => true, 'home' => 3, 'away' => 0, 'winner' => self::HOME, 'decided_at' => 6], $this->resolve($kicks));
    }

    public function test_out_of_turn_returns_the_first_kick_that_breaks_the_alternation(): void
    {
        $resolver = new ShootoutResolver;
        $kick = fn (int $teamId) => ['team_id' => $teamId, 'scored' => true];

        $this->assertNull($resolver->outOfTurn([]));
        $this->assertNull($resolver->outOfTurn([$kick(self::AWAY)]));
        $this->assertNull($resolver->outOfTurn([$kick(self::AWAY), $kick(self::HOME), $kick(self::AWAY)]));
        $this->assertSame(2, $resolver->outOfTurn([$kick(self::HOME), $kick(self::HOME)]));
        $this->assertSame(4, $resolver->outOfTurn([$kick(self::HOME), $kick(self::AWAY), $kick(self::HOME), $kick(self::HOME)]));
    }
}
