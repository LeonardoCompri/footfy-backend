<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\Player;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\User;
use App\Services\LeagueDrawService;
use App\Services\LeagueKnockoutService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use ReflectionMethod;
use ReflectionParameter;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class LeagueKnockoutControllerTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

    private const NOT_ENABLED = 'O mata-mata pela classificação só está disponível em ligas com fase de grupos e sorteio.';

    private const PHASE_BLOCKED = 'Em ligas com sorteio, as partidas de mata-mata são geradas pela classificação.';

    private const GROUP_RESULTS_LOCKED = 'Os resultados da fase de grupos estão travados porque o mata-mata já foi gerado. Para corrigir, desfaça o mata-mata.';

    private const QUARTERS_LOCKED = 'O resultado desta partida está travado porque as semifinais já foram geradas. Para corrigir, desfaça as semifinais no mata-mata.';

    private const SEMIS_LOCKED = 'O resultado desta partida está travado porque a final já foi gerada. Para corrigir, desfaça a final no mata-mata.';

    private const STATUS_LOCKED_BY_NEXT_STAGE = 'O campo status não pode ser alterado porque a fase seguinte do mata-mata já foi gerada.';

    private array $ranking = [];

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

    private function league(int $groupsCount, int $groupSize, int $qualifiers, array $attributes = []): League
    {
        $league = League::factory()->create([
            'format' => 'hybrid',
            'fixture_mode' => 'draw',
            'groups_count' => $groupsCount,
            'group_legs' => 1,
            'qualifiers_per_group' => $qualifiers,
            'knockout_size' => $groupsCount * $qualifiers,
            'knockout_legs' => 1,
            'final_legs' => 1,
            'third_place' => false,
            'knockout_tiebreakers' => ['penalties'],
            ...$attributes,
        ]);

        $this->ranking = [];

        for ($group = 1; $group <= $groupsCount; $group++) {
            for ($position = 1; $position <= $groupSize; $position++) {
                $team = Team::factory()->create(['name' => sprintf('Time %s%d', chr(64 + $group), $position)]);
                $league->teams()->attach($team->id, ['group_number' => $group]);
                $this->ranking[$group][] = (int) $team->id;
            }
        }

        return $league;
    }

    private function team(int $group, int $position): int
    {
        return $this->ranking[$group][$position - 1];
    }

    private function drawAll(League $league): void
    {
        $service = app(LeagueDrawService::class);
        $state = $service->build($league);

        while ($state['next_round'] !== null) {
            $state = $service->drawNextRound((string) $league->id, $state['next_round']);
        }
    }

    private function finishGroupStage(League $league): void
    {
        $groupsCount = count($this->ranking);

        foreach (Game::where('league_id', $league->id)->whereNotNull('group_number')->get() as $game) {
            $teams = $this->ranking[$game->group_number];
            $homeBetter = array_search($game->home_team_id, $teams) < array_search($game->away_team_id, $teams);
            $margin = 1 + $groupsCount - $game->group_number;

            $game->update([
                'status' => 'finished',
                'home_score' => $homeBetter ? $margin : 0,
                'away_score' => $homeBetter ? 0 : $margin,
            ]);
        }
    }

    private function readyLeague(int $groupsCount, int $groupSize, int $qualifiers, array $attributes = []): League
    {
        $league = $this->league($groupsCount, $groupSize, $qualifiers, $attributes);
        $this->drawAll($league);
        $this->finishGroupStage($league);

        return $league;
    }

    private function state(League $league)
    {
        return $this->getJson("/api/leagues/{$league->id}/knockout");
    }

    private function generate(League $league, string $stage, array $payload = [])
    {
        return $this->postJson("/api/leagues/{$league->id}/knockout/next-stage", ['stage' => $stage, ...$payload]);
    }

    private function stageGames(League $league, string $stage)
    {
        return Game::where('league_id', $league->id)->where('knockout_stage', $stage)->orderBy('tie_key')->orderBy('leg')->get();
    }

    private function play(Game $game, int $homeScore, int $awayScore): void
    {
        $game->update(['status' => 'finished', 'home_score' => $homeScore, 'away_score' => $awayScore]);
    }

    private function pairs($games): array
    {
        return $games->map(fn (Game $game) => [$game->tie_key, $game->leg, (int) $game->home_team_id, (int) $game->away_team_id])->all();
    }

    private function groupGame(League $league, int $group = 1): Game
    {
        return Game::where('league_id', $league->id)->where('group_number', $group)->orderBy('round')->firstOrFail();
    }

    private function fullPayload(Game $game, array $overrides = []): array
    {
        return [
            'league_id' => $game->league_id,
            'home_team_id' => $game->home_team_id,
            'away_team_id' => $game->away_team_id,
            'stadium_id' => $game->stadium_id,
            'match_date' => $game->match_date,
            'round' => $game->round,
            'group_number' => $game->group_number,
            'phase' => $game->phase,
            'phase_order' => $game->phase_order,
            'tie_key' => $game->tie_key,
            'leg' => $game->leg,
            'status' => $game->status,
            ...$overrides,
        ];
    }

    private function eventFor(Game $game, array $attributes = []): GameEvent
    {
        $player = Player::factory()->create(['team_id' => $game->home_team_id]);

        return GameEvent::factory()->create([
            'game_id' => $game->id,
            'league_id' => $game->league_id,
            'team_id' => $game->home_team_id,
            'player_id' => $player->id,
            'type' => 'goal',
            'minute' => 10,
            ...$attributes,
        ]);
    }

    private function shootout(Game $game, string $home, string $away, bool $homeFirst = true): array
    {
        $homePlayer = Player::factory()->create(['team_id' => $game->home_team_id]);
        $awayPlayer = Player::factory()->create(['team_id' => $game->away_team_id]);
        $sides = $homeFirst
            ? [[$home, $game->home_team_id, $homePlayer->id], [$away, $game->away_team_id, $awayPlayer->id]]
            : [[$away, $game->away_team_id, $awayPlayer->id], [$home, $game->home_team_id, $homePlayer->id]];
        $kicks = [];

        for ($i = 0; $i < max(strlen($home), strlen($away)); $i++) {
            foreach ($sides as [$pattern, $teamId, $playerId]) {
                if ($i < strlen($pattern)) {
                    $kicks[] = ['team_id' => $teamId, 'player_id' => $playerId, 'scored' => $pattern[$i] === 'G'];
                }
            }
        }

        return $kicks;
    }

    private function saveKicks(Game $game, array $kicks)
    {
        return $this->putJson("/api/games/{$game->id}/penalty-kicks", ['kicks' => $kicks]);
    }

    private function fieldLocked(string $attribute): string
    {
        return sprintf(LeagueKnockoutService::GROUP_RESULT_FIELD_LOCKED, $attribute);
    }

    public function test_state_is_disabled_outside_hybrid_draw_leagues(): void
    {
        $this->authenticateAs('master');
        $manual = League::factory()->create(['format' => 'hybrid', 'qualifiers_per_group' => 4, 'knockout_size' => 4]);

        $this->state($manual)
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.can_generate', false)
            ->assertJsonPath('data.generate_blocked_reason', self::NOT_ENABLED)
            ->assertJsonPath('data.next_stage', null)
            ->assertJsonPath('data.stages', []);

        $this->getJson("/api/leagues/{$manual->id}/knockout/preview")
            ->assertStatus(422)
            ->assertJsonPath('message', self::NOT_ENABLED);
        $this->generate($manual, 'SF')->assertStatus(422)->assertJsonPath('message', self::NOT_ENABLED);
    }

    public function test_first_stage_waits_for_all_rounds_and_group_games(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(2, 3, 2, ['knockout_legs' => 2, 'third_place' => true]);

        $this->state($league)
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.next_stage', ['key' => 'SF', 'label' => 'Semifinal', 'legs' => 2, 'with_third_place' => false])
            ->assertJsonPath('data.stages', [
                ['key' => 'SF', 'label' => 'Semifinal', 'legs' => 2, 'generated' => false],
                ['key' => '3P', 'label' => 'Disputa de 3º lugar', 'legs' => 1, 'generated' => false],
                ['key' => 'F', 'label' => 'Final', 'legs' => 1, 'generated' => false],
            ])
            ->assertJsonPath('data.generate_blocked_reason', 'Sorteie todas as rodadas da fase de grupos antes de gerar o mata-mata.');

        $this->drawAll($league);
        $games = Game::where('league_id', $league->id)->whereNotNull('group_number')->get();

        $this->state($league)->assertJsonPath('data.generate_blocked_reason', 'Há 6 partidas da fase de grupos que ainda não foram encerradas nem canceladas.');

        $this->finishGroupStage($league);
        $games->first()->update(['status' => 'in_progress']);
        $this->state($league)->assertJsonPath('data.generate_blocked_reason', 'Há 1 partida da fase de grupos que ainda não foi encerrada nem cancelada.');
        $this->generate($league, 'SF')->assertStatus(422)->assertJsonPath('message', 'Há 1 partida da fase de grupos que ainda não foi encerrada nem cancelada.');

        $games->first()->update(['status' => 'cancelled']);
        $this->state($league)->assertJsonPath('data.can_generate', true)->assertJsonPath('data.generate_blocked_reason', null);
    }

    public function test_preview_shows_the_crossing_and_matches_the_generated_games(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2, ['knockout_legs' => 2]);

        $preview = $this->getJson("/api/leagues/{$league->id}/knockout/preview")->assertOk();

        $preview
            ->assertJsonPath('data.stage', ['key' => 'SF', 'label' => 'Semifinal', 'legs' => 2])
            ->assertJsonPath('data.third_place', null)
            ->assertJsonPath('data.ties.0.tie_key', 'SF-1')
            ->assertJsonPath('data.ties.0.home_team.id', $this->team(1, 1))
            ->assertJsonPath('data.ties.0.home_team.name', 'Time A1')
            ->assertJsonPath('data.ties.0.away_team.id', $this->team(2, 2))
            ->assertJsonPath('data.ties.0.home_label', '1º Grupo A')
            ->assertJsonPath('data.ties.0.away_label', '2º Grupo B')
            ->assertJsonPath('data.ties.1.home_team.id', $this->team(2, 1))
            ->assertJsonPath('data.ties.1.away_team.id', $this->team(1, 2))
            ->assertJsonPath('data.ties.1.home_label', '1º Grupo B')
            ->assertJsonPath('data.ties.1.away_label', '2º Grupo A');
        $this->assertSame(['id', 'name', 'nickname', 'logo'], array_keys($preview->json('data.ties.0.home_team')));
        $this->assertDatabaseMissing('games', ['league_id' => $league->id, 'knockout_stage' => 'SF']);

        $this->generate($league, 'SF')
            ->assertOk()
            ->assertJsonPath('data.generated_stages', ['SF'])
            ->assertJsonPath('data.last_stage', 'SF')
            ->assertJsonPath('data.next_stage.key', 'F')
            ->assertJsonPath('data.can_generate', false)
            ->assertJsonPath('data.generate_blocked_reason', 'Há confrontos das semifinais ainda não decididos.')
            ->assertJsonPath('data.can_undo', true);

        $expected = [];
        foreach ($preview->json('data.ties') as $tie) {
            foreach ($tie['legs'] as $leg) {
                $expected[] = [$tie['tie_key'], $leg['leg'], $leg['home_team_id'], $leg['away_team_id']];
            }
        }

        $games = $this->stageGames($league, 'SF');
        $this->assertSame($expected, $this->pairs($games));
        $this->assertSame([$this->team(2, 2), $this->team(1, 1)], [(int) $games[0]->home_team_id, (int) $games[0]->away_team_id]);
        $this->assertSame([$this->team(1, 1), $this->team(2, 2)], [(int) $games[1]->home_team_id, (int) $games[1]->away_team_id]);

        foreach ($games as $game) {
            $this->assertSame('Semifinal', $game->phase);
            $this->assertSame(3, $game->phase_order);
            $this->assertSame('scheduled', $game->status);
            $this->assertNull($game->round);
            $this->assertNull($game->group_number);
            $this->assertNull($game->match_date);
        }
    }

    public function test_four_groups_cross_neighbour_groups_with_opposite_halves(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(4, 3, 2);

        $response = $this->getJson("/api/leagues/{$league->id}/knockout/preview")->assertOk();
        $labels = array_map(fn (array $tie) => [$tie['tie_key'], $tie['home_label'], $tie['away_label']], $response->json('data.ties'));

        $this->assertSame([
            ['QF-1', '1º Grupo A', '2º Grupo B'],
            ['QF-2', '1º Grupo C', '2º Grupo D'],
            ['QF-3', '1º Grupo B', '2º Grupo A'],
            ['QF-4', '1º Grupo D', '2º Grupo C'],
        ], $labels);
        $response->assertJsonPath('data.ties.0.legs', [['leg' => null, 'home_team_id' => $this->team(1, 1), 'away_team_id' => $this->team(2, 2)]]);
    }

    public function test_single_group_uses_the_standard_seed_order(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(1, 5, 4);

        $response = $this->getJson("/api/leagues/{$league->id}/knockout/preview")->assertOk();
        $labels = array_map(fn (array $tie) => [$tie['home_label'], $tie['away_label']], $response->json('data.ties'));

        $this->assertSame([['1º colocado', '4º colocado'], ['2º colocado', '3º colocado']], $labels);
        $response->assertJsonPath('data.ties.0.home_team.id', $this->team(1, 1))->assertJsonPath('data.ties.1.home_team.id', $this->team(1, 2));
    }

    public function test_next_stages_until_the_final_with_third_place(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(4, 3, 2, ['third_place' => true]);

        $this->generate($league, 'QF')->assertOk();
        $quarters = $this->stageGames($league, 'QF');
        $this->play($quarters[0], 0, 1);
        $this->play($quarters[1], 2, 0);
        $this->play($quarters[2], 3, 1);
        $this->play($quarters[3], 0, 2);

        $this->getJson("/api/leagues/{$league->id}/knockout/preview")
            ->assertOk()
            ->assertJsonPath('data.stage.key', 'SF')
            ->assertJsonPath('data.ties.0.home_team.id', $this->team(3, 1))
            ->assertJsonPath('data.ties.0.away_team.id', $this->team(2, 2))
            ->assertJsonPath('data.ties.0.home_label', 'Vencedor QF-2')
            ->assertJsonPath('data.ties.0.away_label', 'Vencedor QF-1')
            ->assertJsonPath('data.ties.1.home_team.id', $this->team(2, 1))
            ->assertJsonPath('data.ties.1.away_team.id', $this->team(3, 2));

        $this->generate($league, 'SF')->assertOk()->assertJsonPath('data.next_stage', ['key' => 'F', 'label' => 'Final', 'legs' => 1, 'with_third_place' => true]);
        $semis = $this->stageGames($league, 'SF');
        $this->play($semis[0], 1, 0);
        $this->play($semis[1], 1, 1);

        $this->state($league)
            ->assertJsonPath('data.can_generate', false)
            ->assertJsonPath('data.awaiting_penalties', [['stage' => 'SF', 'tie_key' => 'SF-2', 'game_id' => $semis[1]->id]]);

        $this->saveKicks($semis[1], $this->shootout($semis[1], 'GGGXX', 'GGGG'))
            ->assertOk()
            ->assertJsonPath('data.home_penalties', 3)
            ->assertJsonPath('data.away_penalties', 4);

        $this->getJson("/api/leagues/{$league->id}/knockout/preview")
            ->assertOk()
            ->assertJsonPath('data.stage.key', 'F')
            ->assertJsonPath('data.ties.0.tie_key', 'F-1')
            ->assertJsonPath('data.ties.0.home_team.id', $this->team(3, 1))
            ->assertJsonPath('data.ties.0.away_team.id', $this->team(3, 2))
            ->assertJsonPath('data.third_place.tie_key', '3P-1')
            ->assertJsonPath('data.third_place.home_team.id', $this->team(2, 1))
            ->assertJsonPath('data.third_place.away_team.id', $this->team(2, 2))
            ->assertJsonPath('data.third_place.home_label', 'Perdedor SF-2')
            ->assertJsonPath('data.third_place.away_label', 'Perdedor SF-1');

        $this->generate($league, 'F')
            ->assertOk()
            ->assertJsonPath('data.generated_stages', ['QF', 'SF', '3P', 'F'])
            ->assertJsonPath('data.last_stage', 'F')
            ->assertJsonPath('data.next_stage', null)
            ->assertJsonPath('data.generate_blocked_reason', 'Todas as fases do mata-mata já foram geradas.');

        $final = $this->stageGames($league, 'F');
        $third = $this->stageGames($league, '3P');
        $this->assertSame([['F-1', null, $this->team(3, 1), $this->team(3, 2)]], $this->pairs($final));
        $this->assertSame([['3P-1', null, $this->team(2, 1), $this->team(2, 2)]], $this->pairs($third));
        $this->assertSame([5, 'Final'], [$final[0]->phase_order, $final[0]->phase]);
        $this->assertSame([4, 'Disputa de 3º lugar'], [$third[0]->phase_order, $third[0]->phase]);

        $phases = $this->getJson("/api/leagues/{$league->id}/table")->assertOk()->json('data.phases');
        $this->assertSame(['QF', 'SF', '3P', 'F'], array_column($phases, 'stage'));
        $this->assertSame(['Quartas de final', 'Semifinal', 'Disputa de 3º lugar', 'Final'], array_column($phases, 'phase'));
        $this->assertSame(['QF-1', 'QF-2', 'QF-3', 'QF-4'], array_column($phases[0]['ties'], 'tie_key'));

        $semiTwo = $phases[1]['ties'][1];
        $this->assertSame($this->team(3, 2), $semiTwo['winner_team_id']);
        $this->assertSame('penalties', $semiTwo['decided_by']);
        $this->assertFalse($semiTwo['awaiting_penalties']);
        $this->assertSame([$semis[1]->id, 3, 4], [$semiTwo['decisive_game_id'], $semiTwo['home_penalties'], $semiTwo['away_penalties']]);
        $this->assertSame('aggregate', $phases[1]['ties'][0]['decided_by']);
    }

    public function test_final_with_two_legs_is_played_home_and_away(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 2, 1, ['final_legs' => 2, 'knockout_tiebreakers' => ['away_goals', 'penalties']]);

        $this->state($league)->assertJsonPath('data.next_stage', ['key' => 'F', 'label' => 'Final', 'legs' => 2, 'with_third_place' => false]);
        $this->generate($league, 'F')->assertOk();

        $final = $this->stageGames($league, 'F');
        $this->assertSame([
            ['F-1', 1, $this->team(2, 1), $this->team(1, 1)],
            ['F-1', 2, $this->team(1, 1), $this->team(2, 1)],
        ], $this->pairs($final));

        $this->play($final[0], 1, 1);
        $this->play($final[1], 0, 0);

        $tie = $this->getJson("/api/leagues/{$league->id}/table")->json('data.phases.0.ties.0');
        $this->assertSame($this->team(1, 1), $tie['winner_team_id']);
        $this->assertSame('away_goals', $tie['decided_by']);
    }

    public function test_better_campaign_sends_the_best_seed_through(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2, ['knockout_tiebreakers' => ['better_campaign']]);

        $this->generate($league, 'SF')->assertOk();
        $semis = $this->stageGames($league, 'SF');
        $this->play($semis[0], 2, 2);
        $this->play($semis[1], 0, 1);

        $this->state($league)->assertJsonPath('data.can_generate', true)->assertJsonPath('data.awaiting_penalties', []);
        $this->getJson("/api/leagues/{$league->id}/knockout/preview")
            ->assertJsonPath('data.ties.0.home_team.id', $this->team(1, 1))
            ->assertJsonPath('data.ties.0.away_team.id', $this->team(1, 2));

        $tie = $this->getJson("/api/leagues/{$league->id}/table")->json('data.phases.0.ties.0');
        $this->assertSame('better_campaign', $tie['decided_by']);
    }

    public function test_stage_must_be_the_next_one_and_double_click_is_rejected(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);

        $this->generate($league, 'F')->assertStatus(422)->assertJsonPath('message', 'A próxima fase a ser gerada é: Semifinal.');
        $this->generate($league, 'XX')->assertStatus(422)->assertJsonValidationErrors('stage');
        $this->postJson("/api/leagues/{$league->id}/knockout/next-stage")->assertStatus(422)->assertJsonValidationErrors('stage');

        $this->generate($league, 'SF')->assertOk();
        $this->generate($league, 'SF')->assertStatus(422)->assertJsonPath('message', 'As semifinais já foram geradas.');

        $this->assertCount(2, $this->stageGames($league, 'SF'));
    }

    public function test_undo_last_stage(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2, ['third_place' => true]);

        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'SF'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nenhuma fase do mata-mata foi gerada.');

        $this->generate($league, 'SF')->assertOk();
        $semis = $this->stageGames($league, 'SF');
        $this->play($semis[0], 1, 0);
        $this->play($semis[1], 2, 0);
        $this->generate($league, 'F')->assertOk();

        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'SF'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A última fase gerada é: Final.');

        $final = $this->stageGames($league, 'F')->first();
        $final->update(['status' => 'in_progress']);
        $this->state($league)->assertJsonPath('data.undo_blocked_reason', 'Não é possível desfazer a final e a disputa de 3º lugar porque há partidas que já começaram ou têm eventos.');
        $final->update(['status' => 'scheduled']);

        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'F'])
            ->assertOk()
            ->assertJsonPath('data.generated_stages', ['SF'])
            ->assertJsonPath('data.last_stage', 'SF')
            ->assertJsonPath('data.next_stage.key', 'F');
        $this->assertCount(0, $this->stageGames($league, 'F'));
        $this->assertCount(0, $this->stageGames($league, '3P'));

        GameEvent::factory()->create(['game_id' => $semis[0]->id, 'league_id' => $league->id, 'team_id' => $semis[0]->home_team_id]);
        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'SF'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Não é possível desfazer as semifinais porque há partidas que já começaram ou têm eventos.');
    }

    public function test_manual_phase_games_are_replaced_only_with_confirmation(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $manual = Game::factory()->create([
            'league_id' => $league->id, 'home_team_id' => $this->team(1, 1), 'away_team_id' => $this->team(2, 1),
            'round' => null, 'phase' => 'Final', 'status' => 'finished', 'home_score' => 1, 'away_score' => 0,
        ]);
        $event = GameEvent::factory()->create(['game_id' => $manual->id, 'league_id' => $league->id, 'team_id' => $manual->home_team_id]);
        Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $this->team(1, 2), 'away_team_id' => $this->team(2, 2), 'round' => null, 'phase' => 'Semifinal']);

        $this->state($league)
            ->assertJsonPath('data.manual_phase_games', ['total' => 2, 'with_results' => 1])
            ->assertJsonPath('data.can_generate', true);

        $this->generate($league, 'SF')
            ->assertStatus(422)
            ->assertJsonPath('message', 'A liga já tem 2 partidas de mata-mata cadastradas manualmente; confirme a substituição para gerar o mata-mata.');
        $this->assertNotNull($manual->fresh());

        $this->putJson("/api/leagues/{$league->id}", ['knockout_legs' => 2])->assertOk();

        $this->generate($league, 'SF', ['replace_manual_phases' => true])
            ->assertOk()
            ->assertJsonPath('data.manual_phase_games', ['total' => 0, 'with_results' => 0]);

        $this->assertNull($manual->fresh());
        $this->assertNull($event->fresh());
        $this->assertCount(4, $this->stageGames($league, 'SF'));
        $this->assertSame(0, Game::where('league_id', $league->id)->whereNotNull('phase')->whereNull('knockout_stage')->count());
    }

    public function test_knockout_settings_are_locked_only_by_generated_games(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);

        $this->generate($league, 'SF')->assertOk();

        $this->putJson("/api/leagues/{$league->id}", ['knockout_tiebreakers' => ['better_campaign']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O mata-mata e os critérios de desempate da liga não podem ser alterados porque o mata-mata já foi gerado.');
    }

    public function test_draw_cannot_be_reset_or_undone_after_the_knockout(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $this->generate($league, 'SF')->assertOk();

        $this->getJson("/api/leagues/{$league->id}/draw")
            ->assertJsonPath('data.can_reset', false)
            ->assertJsonPath('data.reset_blocked_reason', 'O sorteio não pode ser reiniciado porque o mata-mata já foi gerado; desfaça as fases do mata-mata antes.')
            ->assertJsonPath('data.can_undo', false)
            ->assertJsonPath('data.undo_blocked_reason', 'A rodada 3 não pode ser desfeita porque o mata-mata já foi gerado; desfaça as fases do mata-mata antes.');

        $this->deleteJson("/api/leagues/{$league->id}/draw")->assertStatus(422);
        $this->deleteJson("/api/leagues/{$league->id}/draw/last-round", ['round' => 3])->assertStatus(422);

        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'SF'])->assertOk();
        $this->getJson("/api/leagues/{$league->id}/draw")->assertJsonPath('data.can_undo', false)->assertJsonPath('data.can_reset', false);
    }

    public function test_games_api_blocks_manual_phases_and_locks_generated_games(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);

        $this->postJson('/api/games', [
            'league_id' => $league->id,
            'home_team_id' => $this->team(1, 1),
            'away_team_id' => $this->team(2, 1),
            'phase' => 'Final',
            'status' => 'scheduled',
        ])->assertStatus(422)->assertJsonPath('errors.phase.0', self::PHASE_BLOCKED);

        $loose = Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $this->team(1, 1), 'away_team_id' => $this->team(2, 1), 'round' => null]);
        $this->putJson("/api/games/{$loose->id}", ['phase' => 'Final'])->assertStatus(422)->assertJsonPath('errors.phase.0', self::PHASE_BLOCKED);

        $legacy = Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $this->team(1, 1), 'away_team_id' => $this->team(2, 1), 'round' => null, 'phase' => 'Final']);
        $this->putJson("/api/games/{$legacy->id}", ['phase' => 'Final', 'status' => 'finished'])->assertOk();
        $this->deleteJson("/api/games/{$legacy->id}")->assertNoContent();
        $loose->delete();

        $this->generate($league, 'SF')->assertOk();
        $game = $this->stageGames($league, 'SF')->first();

        foreach ([
            'home_team_id' => $this->team(1, 3),
            'away_team_id' => $this->team(2, 3),
            'phase' => 'Final',
            'phase_order' => 1,
            'tie_key' => 'X',
            'leg' => 2,
            'round' => 1,
        ] as $field => $value) {
            $response = $this->putJson("/api/games/{$game->id}", [$field => $value])->assertStatus(422);
            $this->assertStringContainsString('não pode ser alterado em partidas geradas pelo mata-mata.', $response->json("errors.{$field}.0"));
        }

        $this->putJson("/api/games/{$game->id}", [
            'home_team_id' => $game->home_team_id,
            'away_team_id' => $game->away_team_id,
            'phase' => $game->phase,
            'phase_order' => $game->phase_order,
            'tie_key' => $game->tie_key,
            'leg' => $game->leg,
            'match_date' => '2026-12-01 16:00:00',
            'status' => 'in_progress',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.knockout_stage', 'SF');

        $this->deleteJson("/api/games/{$game->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Partidas geradas pelo mata-mata não podem ser excluídas; desfaça a fase no mata-mata.');
    }

    public function test_penalty_kicks_are_only_accepted_on_the_decisive_game_of_a_level_tie(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2, ['knockout_legs' => 2, 'knockout_tiebreakers' => ['away_goals', 'penalties']]);
        $this->generate($league, 'SF')->assertOk();
        [$first, $second, $otherFirst, $otherSecond] = $this->stageGames($league, 'SF')->all();

        $this->getJson("/api/games/{$second->id}")
            ->assertJsonPath('data.penalties_allowed', false)
            ->assertJsonPath('data.home_penalties', null)
            ->assertJsonPath('data.penaltyKicks', []);
        $this->saveKicks($second, $this->shootout($second, 'GGGGX', 'GGGXX'))
            ->assertStatus(422)
            ->assertJsonPath('errors.kicks.0', 'Os pênaltis só podem ser lançados depois que todos os jogos do confronto forem encerrados.');

        $this->play($first, 1, 1);
        $this->play($second, 1, 1);

        $this->getJson("/api/games/{$second->id}")->assertJsonPath('data.penalties_allowed', true);
        $this->saveKicks($first, $this->shootout($first, 'GGGGX', 'GGGXX'))
            ->assertStatus(422)
            ->assertJsonPath('errors.kicks.0', 'Os pênaltis são lançados no jogo de volta do confronto.');

        $kicks = $this->shootout($second, 'GGGGX', 'GGGXX');
        $response = $this->saveKicks($second, $kicks)
            ->assertOk()
            ->assertJsonPath('data.home_penalties', 4)
            ->assertJsonPath('data.away_penalties', 3)
            ->assertJsonPath('data.home_score', 1)
            ->assertJsonPath('data.away_score', 1)
            ->assertJsonPath('data.penalties_allowed', true)
            ->assertJsonCount(10, 'data.penaltyKicks');

        $player = Player::find($kicks[0]['player_id']);
        $this->assertSame(range(1, 10), array_column($response->json('data.penaltyKicks'), 'order'));
        $this->assertSame([
            'id' => $response->json('data.penaltyKicks.0.id'),
            'order' => 1,
            'team_id' => $second->home_team_id,
            'player_id' => $player->id,
            'scored' => true,
            'player' => ['id' => $player->id, 'name' => $player->name, 'number' => $player->number, 'removed' => false],
        ], $response->json('data.penaltyKicks.0'));
        $this->assertFalse($response->json('data.penaltyKicks.8.scored'));

        $this->getJson("/api/leagues/{$league->id}/knockout")->assertJsonPath('data.awaiting_penalties', []);

        $this->saveKicks($second, $this->shootout($second, 'GGGGXG', 'GGGXGX', homeFirst: false))
            ->assertOk()
            ->assertJsonPath('data.home_penalties', 5)
            ->assertJsonPath('data.away_penalties', 4)
            ->assertJsonPath('data.penaltyKicks.0.team_id', $second->away_team_id)
            ->assertJsonCount(12, 'data.penaltyKicks');
        $this->assertSame(12, $second->penaltyKicks()->count());

        $this->play($otherFirst, 1, 0);
        $this->play($otherSecond, 2, 1);
        $this->saveKicks($otherSecond, $this->shootout($otherSecond, 'GGGGX', 'GGGXX'))
            ->assertStatus(422)
            ->assertJsonPath('errors.kicks.0', 'O confronto não está empatado após os critérios de desempate; não há disputa de pênaltis.');
        $this->saveKicks($otherSecond, [])->assertOk()->assertJsonPath('data.home_penalties', null);

        $this->saveKicks($second, [])
            ->assertOk()
            ->assertJsonPath('data.home_penalties', null)
            ->assertJsonPath('data.away_penalties', null)
            ->assertJsonPath('data.penaltyKicks', [])
            ->assertJsonPath('data.penalties_allowed', true);
        $this->assertSame(0, $second->penaltyKicks()->count());
    }

    public function test_penalty_kicks_must_alternate_and_end_exactly_when_decided(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $this->generate($league, 'SF')->assertOk();
        $game = $this->stageGames($league, 'SF')->first();
        $this->play($game, 0, 0);

        $this->saveKicks($game, $this->shootout($game, 'GGG', 'GG'))
            ->assertStatus(422)
            ->assertJsonPath('errors.kicks.0', 'A disputa de pênaltis ainda não está decidida; registre as cobranças até o fim.');
        $this->saveKicks($game, $this->shootout($game, 'GGGGG', 'GGGGG'))
            ->assertStatus(422)
            ->assertJsonPath('errors.kicks.0', 'A disputa de pênaltis ainda não está decidida; registre as cobranças até o fim.');
        $this->saveKicks($game, $this->shootout($game, 'GGGG', 'XXX'))
            ->assertStatus(422)
            ->assertJsonPath('errors.kicks.0', 'A disputa de pênaltis foi decidida na cobrança 6; remova as cobranças seguintes.');
        $this->saveKicks($game, $this->shootout($game, 'GGGGGGG', 'GGGGGX'))
            ->assertStatus(422)
            ->assertJsonPath('errors.kicks.0', 'A disputa de pênaltis foi decidida na cobrança 12; remova as cobranças seguintes.');

        $kicks = $this->shootout($game, 'GGG', 'XXX');
        [$kicks[2], $kicks[3]] = [$kicks[3], $kicks[2]];
        $response = $this->saveKicks($game, $kicks)->assertStatus(422);
        $this->assertSame('As cobranças devem alternar entre os times: a cobrança 3 é do mesmo time da anterior.', $response->json('errors')['kicks.2.team_id'][0]);

        $this->saveKicks($game, $this->shootout($game, 'GGG', 'XXX'))
            ->assertOk()
            ->assertJsonPath('data.home_penalties', 3)
            ->assertJsonPath('data.away_penalties', 0)
            ->assertJsonCount(6, 'data.penaltyKicks');

        $this->getJson("/api/public/leagues/{$league->id}/table")
            ->assertJsonPath('data.phases.0.ties.0.decided_by', 'penalties')
            ->assertJsonPath('data.phases.0.ties.0.winner_team_id', $game->home_team_id);
    }

    public function test_penalty_kick_takers_must_play_for_the_kicking_team(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $this->generate($league, 'SF')->assertOk();
        [$game, $other] = $this->stageGames($league, 'SF')->all();
        $this->play($game, 0, 0);
        $kicks = $this->shootout($game, 'GGG', 'XXX');

        $outsider = $this->shootout($other, 'G', '')[0];
        $response = $this->saveKicks($game, [$outsider, ...array_slice($kicks, 1)])->assertStatus(422);
        $this->assertSame('O time informado não participa desta partida.', $response->json('errors')['kicks.0.team_id'][0]);

        $wrongTeam = [['team_id' => $game->home_team_id, 'player_id' => $kicks[1]['player_id'], 'scored' => true], ...array_slice($kicks, 1)];
        $response = $this->saveKicks($game, $wrongTeam)->assertStatus(422);
        $this->assertSame('O jogador informado não pertence ao time informado.', $response->json('errors')['kicks.0.player_id'][0]);

        $missing = [['team_id' => $game->home_team_id, 'player_id' => 999999, 'scored' => true], ...array_slice($kicks, 1)];
        $response = $this->saveKicks($game, $missing)->assertStatus(422);
        $this->assertSame('O jogador informado não existe.', $response->json('errors')['kicks.0.player_id'][0]);

        $removed = Player::factory()->create(['team_id' => $game->home_team_id]);
        $removed->delete();
        $response = $this->saveKicks($game, [['team_id' => $game->home_team_id, 'player_id' => $removed->id, 'scored' => true], ...array_slice($kicks, 1)])->assertStatus(422);
        $this->assertSame('O jogador informado foi removido do elenco.', $response->json('errors')['kicks.0.player_id'][0]);

        $this->saveKicks($game, $kicks)->assertOk();
        Player::find($kicks[0]['player_id'])->delete();

        $this->saveKicks($game, $kicks)
            ->assertOk()
            ->assertJsonPath('data.penaltyKicks.0.player.removed', true)
            ->assertJsonPath('data.penaltyKicks.0.player.id', $kicks[0]['player_id']);
    }

    public function test_penalty_kicks_payload_is_validated(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $this->generate($league, 'SF')->assertOk();
        $game = $this->stageGames($league, 'SF')->first();
        $this->play($game, 0, 0);

        $this->putJson("/api/games/{$game->id}/penalty-kicks", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.kicks.0', 'O campo cobranças deve estar presente.');

        $kicks = $this->shootout($game, 'GGG', 'XXX');
        unset($kicks[1]['scored']);
        $kicks[2]['team_id'] = 'abc';
        $response = $this->saveKicks($game, $kicks)->assertStatus(422);
        $this->assertSame('O campo resultado da cobrança é obrigatório.', $response->json('errors')['kicks.1.scored'][0]);
        $this->assertSame('O campo time da cobrança deve ser um número inteiro.', $response->json('errors')['kicks.2.team_id'][0]);

        $tooMany = array_fill(0, 61, ['team_id' => $game->home_team_id, 'player_id' => 1, 'scored' => true]);
        $this->saveKicks($game, $tooMany)
            ->assertStatus(422)
            ->assertJsonPath('errors.kicks.0', 'A disputa de pênaltis pode ter no máximo 60 cobranças.');

        $this->putJson('/api/games/999999/penalty-kicks', ['kicks' => []])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Partida não encontrada!');
    }

    public function test_penalty_kicks_are_rejected_outside_generated_penalty_ties(): void
    {
        $this->authenticateAs('master');
        $campaign = $this->readyLeague(2, 3, 2, ['knockout_tiebreakers' => ['better_campaign']]);
        $this->generate($campaign, 'SF')->assertOk();
        $game = $this->stageGames($campaign, 'SF')->first();
        $this->play($game, 0, 0);

        $this->saveKicks($game, $this->shootout($game, 'GGG', 'XXX'))
            ->assertStatus(422)
            ->assertJsonPath('errors.kicks.0', 'O regulamento desta liga não decide confrontos nos pênaltis.');

        $manualLeague = League::factory()->create(['format' => 'knockout']);
        $manual = Game::factory()->create(['league_id' => $manualLeague->id, 'round' => null, 'phase' => 'Final', 'status' => 'finished', 'home_score' => 1, 'away_score' => 1]);

        $this->getJson("/api/games/{$manual->id}")->assertJsonPath('data.penalties_allowed', false);
        $this->saveKicks($manual, $this->shootout($manual, 'GGG', 'XXX'))
            ->assertStatus(422)
            ->assertJsonPath('errors.kicks.0', 'Pênaltis só podem ser lançados em confrontos de mata-mata gerados pela classificação.');
    }

    public function test_service_rechecks_the_tie_after_locking_the_game(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $this->generate($league, 'SF')->assertOk();
        $game = $this->stageGames($league, 'SF')->first();
        $this->play($game, 0, 0);
        $kicks = $this->shootout($game, 'GGG', 'XXX');

        $this->partialMock(LeagueKnockoutService::class, function ($mock) {
            $mock->shouldReceive('penaltiesIssue')
                ->andReturn(null, 'O confronto não está empatado após os critérios de desempate; não há disputa de pênaltis.');
            $mock->shouldReceive('knockoutResultsLocked')->andReturn(false);
            $mock->shouldReceive('ensureKnockoutResultsEditable')->andReturnNull();
        });

        $this->saveKicks($game, $kicks)
            ->assertStatus(422)
            ->assertJsonPath('message', 'O confronto não está empatado após os critérios de desempate; não há disputa de pênaltis.');
        $this->assertSame(0, $game->penaltyKicks()->count());
        $this->assertNull($game->fresh()->home_penalties);
    }

    public function test_games_api_no_longer_accepts_penalties(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $this->generate($league, 'SF')->assertOk();
        $game = $this->stageGames($league, 'SF')->first();
        $this->play($game, 0, 0);
        $message = 'O placar dos pênaltis vem das cobranças; registre a disputa de pênaltis da partida.';

        $this->putJson("/api/games/{$game->id}", ['home_penalties' => 4, 'away_penalties' => 3])
            ->assertStatus(422)
            ->assertJsonPath('errors.home_penalties.0', $message)
            ->assertJsonPath('errors.away_penalties.0', $message);
        $this->putJson("/api/games/{$game->id}", ['home_penalties' => null, 'match_date' => '2026-12-01 16:00:00'])->assertOk();

        $this->saveKicks($game, $this->shootout($game, 'GGG', 'XXX'))->assertOk();

        $this->putJson("/api/games/{$game->id}", ['home_penalties' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.home_penalties.0', $message);
        $this->putJson("/api/games/{$game->id}", ['away_penalties' => [1]])
            ->assertStatus(422)
            ->assertJsonPath('errors.away_penalties.0', $message);
        $this->putJson("/api/games/{$game->id}", ['home_penalties' => 3, 'away_penalties' => '0', 'match_date' => '2026-12-02 16:00:00'])
            ->assertOk()
            ->assertJsonPath('data.home_penalties', 3)
            ->assertJsonPath('data.away_penalties', 0)
            ->assertJsonCount(6, 'data.penaltyKicks');
    }

    public function test_penalty_kicks_are_cleared_when_the_tie_stops_being_level(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2, ['knockout_legs' => 2]);
        $this->generate($league, 'SF')->assertOk();
        [$first, $second] = $this->stageGames($league, 'SF')->all();
        $this->play($first, 1, 1);
        $this->play($second, 1, 1);
        $this->saveKicks($second, $this->shootout($second, 'GGGGXG', 'GGGXGX'))->assertOk();

        $player = Player::factory()->create(['team_id' => $first->home_team_id]);
        $this->postJson('/api/game-events', [
            'game_id' => $first->id,
            'team_id' => $first->home_team_id,
            'player_id' => $player->id,
            'type' => 'goal',
            'minute' => 10,
        ])->assertCreated();

        $second->refresh();
        $this->assertSame([1, 0], [(int) $first->fresh()->home_score, (int) $first->fresh()->away_score]);
        $this->assertNull($second->home_penalties);
        $this->assertNull($second->away_penalties);
        $this->assertSame(0, $second->penaltyKicks()->count());

        $this->putJson("/api/games/{$first->id}", ['status' => 'in_progress'])->assertOk();
        $first->fresh()->update(['status' => 'finished', 'home_score' => 1, 'away_score' => 1]);
        $this->saveKicks($second, $this->shootout($second, 'GGGGXG', 'GGGXGX'))->assertOk();
        $this->putJson("/api/games/{$second->id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.home_penalties', null)
            ->assertJsonPath('data.penaltyKicks', [])
            ->assertJsonPath('data.penalties_allowed', false);
        $this->assertSame(0, $second->penaltyKicks()->count());
    }

    public function test_penalty_kicks_do_not_count_as_goals(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $this->generate($league, 'SF')->assertOk();
        $game = $this->stageGames($league, 'SF')->first();
        $kicks = $this->shootout($game, 'GGGGG', 'GGGGX');
        $scorer = Player::find($kicks[0]['player_id']);
        $awayKicker = Player::find($kicks[1]['player_id']);

        foreach ([[$game->home_team_id, $scorer->id], [$game->away_team_id, Player::factory()->create(['team_id' => $game->away_team_id])->id]] as [$teamId, $playerId]) {
            $this->postJson('/api/game-events', [
                'game_id' => $game->id,
                'team_id' => $teamId,
                'player_id' => $playerId,
                'type' => 'goal',
                'minute' => 30,
            ])->assertCreated();
        }
        $this->putJson("/api/games/{$game->id}", ['status' => 'finished'])->assertOk()->assertJsonPath('data.home_score', 1);

        $this->saveKicks($game, $kicks)
            ->assertOk()
            ->assertJsonPath('data.home_score', 1)
            ->assertJsonPath('data.away_score', 1)
            ->assertJsonPath('data.home_penalties', 5)
            ->assertJsonPath('data.away_penalties', 4);

        $this->postJson('/api/game-events', [
            'game_id' => $game->id,
            'team_id' => $game->away_team_id,
            'player_id' => $awayKicker->id,
            'type' => 'yellow_card',
            'minute' => 80,
        ])->assertCreated();

        $game->refresh();
        $this->assertSame([1, 1, 5, 4], [$game->home_score, $game->away_score, $game->home_penalties, $game->away_penalties]);
        $this->assertSame(10, $game->penaltyKicks()->count());

        foreach (["/api/leagues/{$league->id}/statistics", "/api/public/leagues/{$league->id}/statistics"] as $url) {
            $statistics = $this->getJson($url)->assertOk()->json('data');
            $scorers = collect($statistics['top_scorers']);

            $this->assertSame(1, $scorers->firstWhere('player_id', $scorer->id)['goals']);
            $this->assertNull($scorers->firstWhere('player_id', $awayKicker->id));
            $this->assertSame(2, $scorers->sum('goals'));
        }

        $this->getJson("/api/public/games/{$game->id}")
            ->assertJsonPath('data.home_score', 1)
            ->assertJsonPath('data.away_score', 1)
            ->assertJsonCount(3, 'data.events');
    }

    public function test_public_game_shows_penalty_kicks_without_personal_data(): void
    {
        $league = $this->readyLeague(2, 3, 2);
        $this->authenticateAs('master');
        $this->generate($league, 'SF')->assertOk();
        [$game, $legacy] = $this->stageGames($league, 'SF')->all();
        $this->play($game, 0, 0);
        $kicks = $this->shootout($game, 'GGG', 'XXX');
        $this->saveKicks($game, $kicks)->assertOk()->assertJsonMissingPath('data.penaltyKicks.0.player.document');

        $this->app['auth']->forgetGuards();
        $response = $this->getJson("/api/public/games/{$game->id}")
            ->assertOk()
            ->assertJsonCount(6, 'data.penaltyKicks')
            ->assertJsonPath('data.home_penalties', 3)
            ->assertJsonMissingPath('data.penaltyKicks.0.player.document')
            ->assertJsonMissingPath('data.penalties_allowed');
        $this->assertSame(['id', 'name', 'number', 'removed'], array_keys($response->json('data.penaltyKicks.0.player')));

        $legacy->update(['status' => 'finished', 'home_score' => 2, 'away_score' => 2, 'home_penalties' => 4, 'away_penalties' => 2]);
        $this->getJson("/api/public/games/{$legacy->id}")
            ->assertOk()
            ->assertJsonPath('data.home_penalties', 4)
            ->assertJsonPath('data.away_penalties', 2)
            ->assertJsonPath('data.penaltyKicks', []);
    }

    public function test_penalty_kicks_follow_game_update_permission_and_league_access(): void
    {
        $league = $this->readyLeague(2, 3, 2);
        $other = $this->readyLeague(2, 3, 2);
        $this->authenticateAs('master');
        $this->generate($league, 'SF')->assertOk();
        $this->generate($other, 'SF')->assertOk();
        $game = $this->stageGames($league, 'SF')->first();
        $otherGame = $this->stageGames($other, 'SF')->first();
        $this->play($game, 0, 0);
        $this->play($otherGame, 0, 0);
        $kicks = $this->shootout($game, 'GGG', 'XXX');

        $this->authenticateAs('admin', [$league->id]);
        $this->saveKicks($otherGame, $this->shootout($otherGame, 'GGG', 'XXX'))->assertStatus(403);
        $this->saveKicks($game, $kicks)->assertOk()->assertJsonPath('data.home_penalties', 3);

        $this->authenticateAs('coach', [$league->id]);
        $this->saveKicks($game, [])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->assertSame(6, $game->penaltyKicks()->count());
    }

    public function test_awaiting_penalties_appears_in_the_table(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $this->generate($league, 'SF')->assertOk();
        $game = $this->stageGames($league, 'SF')->first();
        $this->play($game, 2, 2);

        $tie = $this->getJson("/api/public/leagues/{$league->id}/table")->assertOk()->json('data.phases.0.ties.0');
        $this->assertTrue($tie['decided']);
        $this->assertNull($tie['winner_team_id']);
        $this->assertNull($tie['decided_by']);
        $this->assertTrue($tie['awaiting_penalties']);
        $this->assertSame($game->id, $tie['decisive_game_id']);
        $this->assertNull($tie['legs'][0]['home_penalties']);
    }

    public function test_admin_manages_knockout_only_in_own_league(): void
    {
        $own = $this->readyLeague(2, 3, 2);
        $other = League::factory()->create(['format' => 'hybrid', 'fixture_mode' => 'draw']);
        $this->authenticateAs('admin', [$own->id]);

        $this->state($own)->assertOk();
        $this->getJson("/api/leagues/{$own->id}/knockout/preview")->assertOk();
        $this->generate($own, 'SF')->assertOk();
        $this->deleteJson("/api/leagues/{$own->id}/knockout/last-stage", ['stage' => 'SF'])->assertOk();

        $this->state($other)->assertStatus(403);
        $this->getJson("/api/leagues/{$other->id}/knockout/preview")->assertStatus(403);
        $this->generate($other, 'SF')->assertStatus(403);
        $this->deleteJson("/api/leagues/{$other->id}/knockout/last-stage", ['stage' => 'SF'])->assertStatus(403);
    }

    public function test_coach_can_read_but_not_generate(): void
    {
        $league = $this->readyLeague(2, 3, 2);
        $this->authenticateAs('coach', [$league->id]);

        $this->state($league)->assertOk();
        $this->generate($league, 'SF')->assertStatus(403);
        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'SF'])->assertStatus(403);
    }

    public function test_unknown_league_returns_422(): void
    {
        $this->authenticateAs('master');

        $this->getJson('/api/leagues/999/knockout')->assertStatus(422)->assertJsonPath('message', 'Liga não encontrada!');
        $this->postJson('/api/leagues/999/knockout/next-stage', ['stage' => 'SF'])->assertStatus(422)->assertJsonPath('message', 'Liga não encontrada!');
    }

    public function test_group_results_are_editable_while_the_knockout_is_not_generated(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $game = $this->groupGame($league);

        $this->getJson("/api/games/{$game->id}")->assertOk()->assertJsonPath('data.results_locked', false);
        $this->assertArrayNotHasKey('results_locked', $this->getJson("/api/games?league_id={$league->id}")->json('data.0'));
        $this->assertArrayNotHasKey('results_locked', $this->getJson("/api/public/games/{$game->id}")->json('data'));

        $this->putJson("/api/games/{$game->id}", $this->fullPayload($game, ['status' => 'in_progress']))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.results_locked', false);

        $player = Player::factory()->create(['team_id' => $game->home_team_id]);
        $awayPlayer = Player::factory()->create(['team_id' => $game->away_team_id]);
        $eventId = $this->postJson('/api/game-events', [
            'game_id' => $game->id,
            'team_id' => $game->home_team_id,
            'player_id' => $player->id,
            'type' => 'goal',
            'minute' => 5,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/game-events/{$eventId}", ['team_id' => $game->away_team_id, 'player_id' => $awayPlayer->id, 'type' => 'yellow_card'])->assertOk();
        $this->deleteJson("/api/game-events/{$eventId}")->assertStatus(204);
    }

    public function test_group_game_fields_are_locked_after_the_knockout_is_generated(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $game = $this->groupGame($league);
        $this->generate($league, 'SF')->assertOk();

        $this->getJson("/api/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.results_locked', true)
            ->assertJsonPath('data.results_lock', 'group_stage');

        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $this->putJson("/api/games/{$game->id}", $this->fullPayload($game, ['match_date' => '2026-12-01 16:00:00', 'stadium_id' => $stadium->id]))
            ->assertOk()
            ->assertJsonPath('data.stadium_id', $stadium->id)
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.results_locked', true);
        $this->putJson("/api/games/{$game->id}", ['match_date' => null, 'stadium_id' => null])->assertOk();

        $third = collect($this->ranking[1])->first(fn (int $id) => ! in_array($id, [(int) $game->home_team_id, (int) $game->away_team_id], true));
        $otherLeague = League::factory()->create(['format' => 'round_robin']);

        foreach ([
            'status' => ['cancelled', 'status'],
            'home_team_id' => [$third, 'time mandante'],
            'away_team_id' => [$third, 'time visitante'],
            'league_id' => [$otherLeague->id, 'liga'],
            'round' => [$game->round + 1, 'rodada'],
        ] as $field => [$value, $attribute]) {
            $this->putJson("/api/games/{$game->id}", $this->fullPayload($game->fresh(), [$field => $value]))
                ->assertStatus(422)
                ->assertJsonPath("errors.{$field}.0", $this->fieldLocked($attribute));
        }

        $this->putJson("/api/games/{$game->id}", ['round' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.round.0', $this->fieldLocked('rodada'));

        $fresh = $game->fresh();
        $this->assertSame([$game->home_team_id, $game->away_team_id, $game->league_id, $game->round, 'finished'], [$fresh->home_team_id, $fresh->away_team_id, $fresh->league_id, $fresh->round, $fresh->status]);

        $semifinal = $this->stageGames($league, 'SF')->first();
        $this->getJson("/api/games/{$semifinal->id}")
            ->assertJsonPath('data.results_locked', false)
            ->assertJsonPath('data.results_lock', null);
        $this->putJson("/api/games/{$semifinal->id}", ['status' => 'in_progress'])->assertOk();
        $response = $this->putJson("/api/games/{$semifinal->id}", ['home_team_id' => $this->team(1, 3)])->assertStatus(422);
        $this->assertSame('O campo time mandante não pode ser alterado em partidas geradas pelo mata-mata.', $response->json('errors.home_team_id.0'));
    }

    public function test_group_game_events_are_locked_after_the_knockout_is_generated(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $game = $this->groupGame($league);
        $otherGroupGame = $this->groupGame($league, 2);
        $this->generate($league, 'SF')->assertOk();
        $semifinal = $this->stageGames($league, 'SF')->first();

        $event = $this->eventFor($game);
        $player = Player::factory()->create(['team_id' => $game->home_team_id]);
        $awayPlayer = Player::factory()->create(['team_id' => $game->away_team_id]);
        $assistant = Player::factory()->create(['team_id' => $game->home_team_id]);

        $this->postJson('/api/game-events', [
            'game_id' => $game->id,
            'team_id' => $game->home_team_id,
            'player_id' => $player->id,
            'type' => 'goal',
        ])->assertStatus(422)->assertJsonPath('message', self::GROUP_RESULTS_LOCKED);
        $this->postJson('/api/game-events', [
            'game_id' => $game->id,
            'team_id' => $game->home_team_id,
            'player_id' => $player->id,
            'type' => 'yellow_card',
        ])->assertStatus(422)->assertJsonPath('message', self::GROUP_RESULTS_LOCKED);

        $this->putJson("/api/game-events/{$event->id}", ['player_id' => $player->id, 'related_player_id' => $assistant->id, 'minute' => 33])
            ->assertOk()
            ->assertJsonPath('data.player_id', $player->id)
            ->assertJsonPath('data.minute', 33);
        $this->putJson("/api/game-events/{$event->id}", [
            'game_id' => $game->id,
            'team_id' => $game->home_team_id,
            'player_id' => $player->id,
            'type' => 'goal',
            'related_player_id' => null,
            'minute' => 40,
        ])->assertOk();

        $otherPlayer = Player::factory()->create(['team_id' => $otherGroupGame->home_team_id]);
        $semifinalPlayer = Player::factory()->create(['team_id' => $semifinal->home_team_id]);

        foreach ([
            ['team_id' => $game->away_team_id, 'player_id' => $awayPlayer->id],
            ['type' => 'yellow_card'],
            ['game_id' => $otherGroupGame->id, 'team_id' => $otherGroupGame->home_team_id, 'player_id' => $otherPlayer->id],
            ['game_id' => $semifinal->id, 'team_id' => $semifinal->home_team_id, 'player_id' => $semifinalPlayer->id],
        ] as $payload) {
            $this->putJson("/api/game-events/{$event->id}", $payload)
                ->assertStatus(422)
                ->assertJsonPath('message', self::GROUP_RESULTS_LOCKED);
        }

        $semifinalEvent = $this->eventFor($semifinal);
        $this->putJson("/api/game-events/{$semifinalEvent->id}", ['game_id' => $game->id, 'team_id' => $game->home_team_id, 'player_id' => $player->id])
            ->assertStatus(422)
            ->assertJsonPath('message', self::GROUP_RESULTS_LOCKED);
        $this->assertSame($semifinal->id, $semifinalEvent->fresh()->game_id);

        $this->deleteJson("/api/game-events/{$event->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', self::GROUP_RESULTS_LOCKED);

        $fresh = $event->fresh();
        $this->assertSame([$game->id, $game->home_team_id, 'goal'], [$fresh->game_id, $fresh->team_id, $fresh->type]);

        $this->postJson('/api/game-events', [
            'game_id' => $semifinal->id,
            'team_id' => $semifinal->home_team_id,
            'player_id' => $semifinalPlayer->id,
            'type' => 'goal',
        ])->assertCreated();
        $this->putJson("/api/game-events/{$semifinalEvent->id}", ['type' => 'yellow_card'])->assertOk();
        $this->deleteJson("/api/game-events/{$semifinalEvent->id}")->assertStatus(204);
    }

    public function test_group_results_unlock_after_undoing_the_whole_knockout(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $game = $this->groupGame($league);
        $this->generate($league, 'SF')->assertOk();
        $semis = $this->stageGames($league, 'SF');
        $this->play($semis[0], 1, 0);
        $this->play($semis[1], 2, 0);
        $this->generate($league, 'F')->assertOk();

        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'F'])->assertOk();
        $this->getJson("/api/games/{$game->id}")->assertJsonPath('data.results_locked', true);
        $this->putJson("/api/games/{$game->id}", ['status' => 'cancelled'])->assertStatus(422);

        $semis->each(fn (Game $semi) => $semi->fresh()->update(['status' => 'scheduled', 'home_score' => null, 'away_score' => null]));
        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'SF'])->assertOk();

        $this->getJson("/api/games/{$game->id}")->assertJsonPath('data.results_locked', false);
        $this->putJson("/api/games/{$game->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.results_locked', false);

        $player = Player::factory()->create(['team_id' => $game->home_team_id]);
        $this->postJson('/api/game-events', [
            'game_id' => $game->id,
            'team_id' => $game->home_team_id,
            'player_id' => $player->id,
            'type' => 'goal',
        ])->assertCreated();
    }

    public function test_state_counts_cancelled_group_games(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);

        $this->state($league)->assertJsonPath('data.cancelled_group_games', 0);

        $this->groupGame($league)->update(['status' => 'cancelled']);
        $this->groupGame($league, 2)->update(['status' => 'cancelled']);

        $this->state($league)->assertJsonPath('data.cancelled_group_games', 2);
        $this->generate($league, 'SF')->assertOk()->assertJsonPath('data.cancelled_group_games', 2);
        $this->stageGames($league, 'SF')->first()->update(['status' => 'cancelled']);
        $this->state($league)->assertJsonPath('data.cancelled_group_games', 2);

        $manual = League::factory()->create(['format' => 'round_robin']);
        Game::factory()->create(['league_id' => $manual->id, 'round' => 1, 'status' => 'cancelled']);
        $this->state($manual)->assertJsonPath('data.enabled', false)->assertJsonPath('data.cancelled_group_games', 0);
    }

    public function test_penalties_possible_marks_the_decisive_game_of_generated_ties_decided_on_penalties(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2, ['knockout_legs' => 2, 'knockout_tiebreakers' => ['away_goals', 'penalties']]);
        $this->generate($league, 'SF')->assertOk();
        [$first, $second, $otherFirst, $otherSecond] = $this->stageGames($league, 'SF')->all();

        $this->getJson("/api/games/{$first->id}")->assertOk()->assertJsonPath('data.penalties_possible', false);
        $this->getJson("/api/games/{$second->id}")
            ->assertOk()
            ->assertJsonPath('data.penalties_possible', true)
            ->assertJsonPath('data.penalties_allowed', false);

        $this->play($first, 1, 1);
        $this->play($second, 1, 1);
        $this->getJson("/api/games/{$second->id}")
            ->assertJsonPath('data.penalties_possible', true)
            ->assertJsonPath('data.penalties_allowed', true);

        $this->play($otherFirst, 1, 0);
        $this->play($otherSecond, 2, 1);
        $this->getJson("/api/games/{$otherSecond->id}")
            ->assertJsonPath('data.penalties_possible', true)
            ->assertJsonPath('data.penalties_allowed', false);

        $this->putJson("/api/games/{$second->id}", ['match_date' => '2026-12-01 16:00:00'])
            ->assertOk()
            ->assertJsonPath('data.penalties_possible', true);
        $this->putJson("/api/games/{$first->id}", ['match_date' => '2026-12-01 16:00:00'])
            ->assertOk()
            ->assertJsonPath('data.penalties_possible', false);

        $this->assertArrayNotHasKey('penalties_possible', $this->getJson("/api/games?league_id={$league->id}")->json('data.0'));
        $this->assertArrayNotHasKey('penalties_possible', $this->getJson("/api/public/games/{$second->id}")->assertOk()->json('data'));
    }

    public function test_penalties_possible_on_single_game_ties_follows_the_rules(): void
    {
        $this->authenticateAs('master');
        $withPenalties = $this->readyLeague(2, 3, 2);
        $this->generate($withPenalties, 'SF')->assertOk();
        $single = $this->stageGames($withPenalties, 'SF')->first();

        $this->getJson("/api/games/{$single->id}")
            ->assertJsonPath('data.penalties_possible', true)
            ->assertJsonPath('data.penalties_allowed', false);
        $this->getJson("/api/games/{$this->groupGame($withPenalties)->id}")
            ->assertJsonPath('data.penalties_possible', false);

        $campaign = $this->readyLeague(2, 3, 2, ['knockout_tiebreakers' => ['better_campaign']]);
        $this->generate($campaign, 'SF')->assertOk();
        $this->getJson("/api/games/{$this->stageGames($campaign, 'SF')->first()->id}")
            ->assertJsonPath('data.penalties_possible', false);

        $manualLeague = League::factory()->create(['format' => 'knockout', 'knockout_tiebreakers' => ['penalties']]);
        $manual = Game::factory()->create(['league_id' => $manualLeague->id, 'round' => null, 'phase' => 'Final', 'status' => 'finished', 'home_score' => 1, 'away_score' => 1]);
        $this->getJson("/api/games/{$manual->id}")
            ->assertJsonPath('data.penalties_possible', false)
            ->assertJsonPath('data.penalties_allowed', false);
    }

    private function lockedQuarterLeague(): array
    {
        $league = $this->readyLeague(4, 3, 2);
        $this->generate($league, 'QF')->assertOk();
        $quarters = $this->stageGames($league, 'QF');
        $this->play($quarters[0], 2, 0);
        $this->play($quarters[1], 1, 1);
        $this->play($quarters[2], 3, 1);
        $this->play($quarters[3], 0, 2);
        $this->saveKicks($quarters[1], $this->shootout($quarters[1], 'GGGXX', 'GGGG'))->assertOk();

        return [$league, $quarters];
    }

    private function eventPayload(Game $game, array $overrides = []): array
    {
        return [
            'game_id' => $game->id,
            'team_id' => $game->home_team_id,
            'player_id' => Player::factory()->create(['team_id' => $game->home_team_id])->id,
            'type' => 'goal',
            ...$overrides,
        ];
    }

    public function test_knockout_results_lock_after_the_next_stage_is_generated(): void
    {
        $this->authenticateAs('master');
        [$league, $quarters] = $this->lockedQuarterLeague();
        $quarter = $quarters[0]->fresh();
        $level = $quarters[1]->fresh();

        $this->getJson("/api/games/{$level->id}")
            ->assertJsonPath('data.penalties_allowed', true)
            ->assertJsonPath('data.results_locked', false)
            ->assertJsonPath('data.results_lock', null);

        $goal = $this->eventFor($quarter);
        $this->eventFor($quarter, ['minute' => 30]);
        $card = $this->eventFor($quarter, ['type' => 'yellow_card', 'minute' => 20]);
        $this->generate($league, 'SF')->assertOk();

        $this->getJson("/api/games/{$level->id}")
            ->assertOk()
            ->assertJsonPath('data.results_locked', true)
            ->assertJsonPath('data.results_lock', 'next_stage')
            ->assertJsonPath('data.penalties_allowed', false)
            ->assertJsonPath('data.penalties_possible', true);

        $this->putJson("/api/games/{$quarter->id}", $this->fullPayload($quarter, ['status' => 'in_progress']))
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', self::STATUS_LOCKED_BY_NEXT_STAGE);
        $this->assertSame('finished', $quarter->fresh()->status);

        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $this->putJson("/api/games/{$quarter->id}", $this->fullPayload($quarter, ['match_date' => '2026-12-01 16:00:00', 'stadium_id' => $stadium->id]))
            ->assertOk()
            ->assertJsonPath('data.stadium_id', $stadium->id)
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.results_lock', 'next_stage');

        $this->postJson('/api/game-events', $this->eventPayload($quarter))
            ->assertStatus(422)
            ->assertJsonPath('message', self::QUARTERS_LOCKED);
        $this->postJson('/api/game-events', $this->eventPayload($quarter, ['type' => 'own_goal']))
            ->assertStatus(422)
            ->assertJsonPath('message', self::QUARTERS_LOCKED);
        $this->postJson('/api/game-events', $this->eventPayload($quarter, ['type' => 'yellow_card']))->assertCreated();

        $awayPlayer = Player::factory()->create(['team_id' => $quarter->away_team_id]);
        $player = Player::factory()->create(['team_id' => $quarter->home_team_id]);

        foreach ([
            ['team_id' => $quarter->away_team_id, 'player_id' => $awayPlayer->id],
            ['type' => 'yellow_card'],
        ] as $payload) {
            $this->putJson("/api/game-events/{$goal->id}", $payload)
                ->assertStatus(422)
                ->assertJsonPath('message', self::QUARTERS_LOCKED);
        }

        $this->putJson("/api/game-events/{$goal->id}", ['player_id' => $player->id, 'minute' => 55])
            ->assertOk()
            ->assertJsonPath('data.player_id', $player->id)
            ->assertJsonPath('data.minute', 55);
        $this->putJson("/api/game-events/{$card->id}", ['type' => 'red_card', 'team_id' => $quarter->away_team_id, 'player_id' => $awayPlayer->id])->assertOk();

        $this->deleteJson("/api/game-events/{$goal->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', self::QUARTERS_LOCKED);
        $this->deleteJson("/api/game-events/{$card->id}")->assertStatus(204);

        $fresh = $goal->fresh();
        $this->assertSame([$quarter->id, $quarter->home_team_id, 'goal'], [$fresh->game_id, $fresh->team_id, $fresh->type]);
        $this->assertSame([2, 0], [$quarter->fresh()->home_score, $quarter->fresh()->away_score]);

        $kicks = $level->penaltyKicks()->count();
        $this->saveKicks($level, $this->shootout($level, 'GGGGG', 'GGGGX'))
            ->assertStatus(422)
            ->assertJsonPath('message', self::QUARTERS_LOCKED);
        $this->saveKicks($level, [])
            ->assertStatus(422)
            ->assertJsonPath('message', self::QUARTERS_LOCKED);
        $this->assertSame($kicks, $level->penaltyKicks()->count());
        $this->assertSame([3, 4], [$level->fresh()->home_penalties, $level->fresh()->away_penalties]);

        $semifinal = $this->stageGames($league, 'SF')->first();
        $this->getJson("/api/games/{$semifinal->id}")
            ->assertJsonPath('data.results_locked', false)
            ->assertJsonPath('data.results_lock', null);
        $this->postJson('/api/game-events', $this->eventPayload($semifinal))->assertCreated();
        $this->putJson("/api/games/{$semifinal->id}", ['status' => 'in_progress'])->assertOk();
    }

    public function test_service_rejects_locked_knockout_results_even_past_the_request(): void
    {
        $this->authenticateAs('master');
        [$league, $quarters] = $this->lockedQuarterLeague();
        $level = $quarters[1]->fresh();
        $this->generate($league, 'SF')->assertOk();

        $arguments = array_map(
            fn (ReflectionParameter $parameter) => app($parameter->getType()->getName()),
            (new ReflectionMethod(LeagueKnockoutService::class, '__construct'))->getParameters(),
        );
        $mock = Mockery::mock(LeagueKnockoutService::class, $arguments)->makePartial();
        $mock->shouldReceive('knockoutResultsLocked')->twice()->andReturn(false, true);
        $this->app->instance(LeagueKnockoutService::class, $mock);
        app('router')->getRoutes()->getByName('games.penalty-kicks.update')->flushController();

        $this->saveKicks($level, [])
            ->assertStatus(422)
            ->assertJsonPath('message', self::QUARTERS_LOCKED);
        $this->assertSame([3, 4], [$level->fresh()->home_penalties, $level->fresh()->away_penalties]);
    }

    public function test_both_legs_of_a_tie_lock_together(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2, ['knockout_legs' => 2, 'knockout_tiebreakers' => ['away_goals', 'penalties']]);
        $this->generate($league, 'SF')->assertOk();
        $semis = $this->stageGames($league, 'SF');
        $this->play($semis[0], 1, 0);
        $this->play($semis[1], 2, 0);
        $this->play($semis[2], 0, 1);
        $this->play($semis[3], 0, 3);
        $this->generate($league, 'F')->assertOk();

        foreach ($semis as $semi) {
            $this->getJson("/api/games/{$semi->id}")->assertJsonPath('data.results_lock', 'next_stage');
            $this->postJson('/api/game-events', $this->eventPayload($semi))
                ->assertStatus(422)
                ->assertJsonPath('message', self::SEMIS_LOCKED);
        }
    }

    public function test_final_and_third_place_are_not_locked_by_each_other(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2, ['third_place' => true]);
        $this->generate($league, 'SF')->assertOk();
        $semis = $this->stageGames($league, 'SF');
        $this->play($semis[0], 1, 0);
        $this->play($semis[1], 2, 0);
        $this->generate($league, 'F')->assertOk();

        $this->getJson("/api/games/{$semis[0]->id}")->assertJsonPath('data.results_lock', 'next_stage');
        $this->putJson("/api/games/{$semis[0]->id}", ['status' => 'scheduled'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', self::STATUS_LOCKED_BY_NEXT_STAGE);

        foreach (['F', '3P'] as $stage) {
            $game = $this->stageGames($league, $stage)->first();

            $this->getJson("/api/games/{$game->id}")
                ->assertJsonPath('data.results_locked', false)
                ->assertJsonPath('data.results_lock', null);
            $this->putJson("/api/games/{$game->id}", ['status' => 'finished'])->assertOk();
            $this->postJson('/api/game-events', $this->eventPayload($game))->assertCreated();
        }
    }

    public function test_undoing_the_next_stage_unlocks_the_tie_for_correction(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2);
        $this->generate($league, 'SF')->assertOk();
        $semis = $this->stageGames($league, 'SF');
        $this->play($semis[0], 1, 0);
        $this->play($semis[1], 2, 0);
        $this->generate($league, 'F')->assertOk();
        $semi = $semis[0]->fresh();
        $oldWinner = (int) $semi->home_team_id;
        $newWinner = (int) $semi->away_team_id;
        $this->assertContains($oldWinner, $this->pairs($this->stageGames($league, 'F'))[0]);

        $this->postJson('/api/game-events', $this->eventPayload($semi, ['team_id' => $newWinner, 'player_id' => Player::factory()->create(['team_id' => $newWinner])->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', self::SEMIS_LOCKED);

        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'F'])->assertOk();
        $this->getJson("/api/games/{$semi->id}")->assertJsonPath('data.results_lock', null);

        $this->postJson('/api/game-events', $this->eventPayload($semi, ['team_id' => $newWinner, 'player_id' => Player::factory()->create(['team_id' => $newWinner])->id]))
            ->assertCreated();
        $this->assertSame([0, 1], [$semi->fresh()->home_score, $semi->fresh()->away_score]);

        $this->generate($league, 'F')->assertOk();
        $final = $this->pairs($this->stageGames($league, 'F'))[0];
        $this->assertContains($newWinner, $final);
        $this->assertNotContains($oldWinner, $final);
        $this->getJson("/api/games/{$semi->id}")->assertJsonPath('data.results_lock', 'next_stage');
    }

    public function test_scoring_events_cannot_move_into_or_out_of_a_locked_knockout_game(): void
    {
        $this->authenticateAs('master');
        [$league, $quarters] = $this->lockedQuarterLeague();
        $this->generate($league, 'SF')->assertOk();
        $quarter = $quarters[0]->fresh();
        $semifinal = $this->stageGames($league, 'SF')->first();
        $quarterPlayer = Player::factory()->create(['team_id' => $quarter->home_team_id]);
        $semifinalPlayer = Player::factory()->create(['team_id' => $semifinal->home_team_id]);

        $free = $this->eventFor($semifinal);
        $this->putJson("/api/game-events/{$free->id}", ['game_id' => $quarter->id, 'team_id' => $quarter->home_team_id, 'player_id' => $quarterPlayer->id])
            ->assertStatus(422)
            ->assertJsonPath('message', self::QUARTERS_LOCKED);
        $this->assertSame($semifinal->id, $free->fresh()->game_id);

        $locked = $this->eventFor($quarter);
        $this->putJson("/api/game-events/{$locked->id}", ['game_id' => $semifinal->id, 'team_id' => $semifinal->home_team_id, 'player_id' => $semifinalPlayer->id])
            ->assertStatus(422)
            ->assertJsonPath('message', self::QUARTERS_LOCKED);
        $this->assertSame($quarter->id, $locked->fresh()->game_id);

        $card = $this->eventFor($semifinal, ['type' => 'yellow_card']);
        $this->putJson("/api/game-events/{$card->id}", ['game_id' => $quarter->id, 'team_id' => $quarter->home_team_id, 'player_id' => $quarterPlayer->id])
            ->assertOk()
            ->assertJsonPath('data.game_id', $quarter->id);
    }
}
