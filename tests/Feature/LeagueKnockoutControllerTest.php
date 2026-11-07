<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\LeagueDrawService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class LeagueKnockoutControllerTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

    private const NOT_ENABLED = 'O mata-mata pela classificação só está disponível em ligas com fase de grupos e sorteio.';

    private const PHASE_BLOCKED = 'Em ligas com sorteio, as partidas de mata-mata são geradas pela classificação.';

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

        $this->putJson("/api/games/{$semis[1]->id}", ['home_penalties' => 3, 'away_penalties' => 4])->assertOk();

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

    public function test_penalties_are_only_accepted_on_the_decisive_game_of_a_level_tie(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2, ['knockout_legs' => 2, 'knockout_tiebreakers' => ['away_goals', 'penalties']]);
        $this->generate($league, 'SF')->assertOk();
        [$first, $second, $otherFirst, $otherSecond] = $this->stageGames($league, 'SF')->all();

        $this->getJson("/api/games/{$second->id}")->assertJsonPath('data.penalties_allowed', false)->assertJsonPath('data.home_penalties', null);
        $this->putJson("/api/games/{$second->id}", ['home_penalties' => 4, 'away_penalties' => 3])
            ->assertStatus(422)
            ->assertJsonPath('errors.home_penalties.0', 'Os pênaltis só podem ser lançados depois que todos os jogos do confronto forem encerrados.');

        $this->play($first, 1, 1);
        $this->play($second, 1, 1);

        $this->getJson("/api/games/{$second->id}")->assertJsonPath('data.penalties_allowed', true);
        $this->getJson("/api/games/{$first->id}")->assertJsonPath('data.penalties_allowed', false);
        $this->assertArrayNotHasKey('penalties_allowed', $this->getJson("/api/games?league_id={$league->id}")->json('data.0'));

        $this->putJson("/api/games/{$first->id}", ['home_penalties' => 4, 'away_penalties' => 3])
            ->assertStatus(422)
            ->assertJsonPath('errors.home_penalties.0', 'Os pênaltis são lançados no jogo de volta do confronto.');
        $this->putJson("/api/games/{$second->id}", ['home_penalties' => 4])
            ->assertStatus(422)
            ->assertJsonPath('errors.home_penalties.0', 'Informe os pênaltis dos dois times.');
        $this->putJson("/api/games/{$second->id}", ['home_penalties' => 4, 'away_penalties' => 4])
            ->assertStatus(422)
            ->assertJsonPath('errors.home_penalties.0', 'A disputa de pênaltis não pode terminar empatada.');
        $this->putJson("/api/games/{$second->id}", ['home_penalties' => -1, 'away_penalties' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors('home_penalties');
        $this->putJson("/api/games/{$second->id}", ['home_penalties' => 4, 'away_penalties' => 3, 'status' => 'in_progress'])
            ->assertStatus(422)
            ->assertJsonPath('errors.home_penalties.0', 'Os pênaltis só podem ser lançados depois que todos os jogos do confronto forem encerrados.');

        $this->putJson("/api/games/{$second->id}", ['home_penalties' => 4, 'away_penalties' => 3])
            ->assertOk()
            ->assertJsonPath('data.home_penalties', 4)
            ->assertJsonPath('data.away_penalties', 3)
            ->assertJsonPath('data.penalties_allowed', true);

        $this->play($otherFirst, 1, 0);
        $this->play($otherSecond, 2, 1);
        $this->putJson("/api/games/{$otherSecond->id}", ['home_penalties' => 4, 'away_penalties' => 3])
            ->assertStatus(422)
            ->assertJsonPath('errors.home_penalties.0', 'O confronto não está empatado após os critérios de desempate; não há disputa de pênaltis.');
        $this->putJson("/api/games/{$otherSecond->id}", ['home_penalties' => null, 'away_penalties' => null])->assertOk();

        $this->putJson("/api/games/{$second->id}", ['home_penalties' => 4, 'away_penalties' => 3, 'match_date' => '2026-12-01 16:00:00'])->assertOk();
    }

    public function test_penalties_are_rejected_outside_generated_penalty_ties(): void
    {
        $this->authenticateAs('master');
        $campaign = $this->readyLeague(2, 3, 2, ['knockout_tiebreakers' => ['better_campaign']]);
        $this->generate($campaign, 'SF')->assertOk();
        $game = $this->stageGames($campaign, 'SF')->first();
        $this->play($game, 0, 0);

        $this->putJson("/api/games/{$game->id}", ['home_penalties' => 4, 'away_penalties' => 3])
            ->assertStatus(422)
            ->assertJsonPath('errors.home_penalties.0', 'O regulamento desta liga não decide confrontos nos pênaltis.');

        $manualLeague = League::factory()->create(['format' => 'knockout']);
        $manual = Game::factory()->create(['league_id' => $manualLeague->id, 'round' => null, 'phase' => 'Final', 'status' => 'finished', 'home_score' => 1, 'away_score' => 1]);

        $this->getJson("/api/games/{$manual->id}")->assertJsonPath('data.penalties_allowed', false);
        $this->putJson("/api/games/{$manual->id}", ['home_penalties' => 4, 'away_penalties' => 3])
            ->assertStatus(422)
            ->assertJsonPath('errors.home_penalties.0', 'Pênaltis só podem ser lançados em confrontos de mata-mata gerados pela classificação.');
    }

    public function test_penalties_are_cleared_when_the_tie_stops_being_level(): void
    {
        $this->authenticateAs('master');
        $league = $this->readyLeague(2, 3, 2, ['knockout_legs' => 2]);
        $this->generate($league, 'SF')->assertOk();
        [$first, $second] = $this->stageGames($league, 'SF')->all();
        $this->play($first, 1, 1);
        $this->play($second, 1, 1);
        $this->putJson("/api/games/{$second->id}", ['home_penalties' => 5, 'away_penalties' => 4])->assertOk();

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

        $this->putJson("/api/games/{$first->id}", ['status' => 'in_progress'])->assertOk();
        $first->fresh()->update(['status' => 'finished', 'home_score' => 1, 'away_score' => 1]);
        $this->putJson("/api/games/{$second->id}", ['home_penalties' => 5, 'away_penalties' => 4])->assertOk();
        $this->putJson("/api/games/{$second->id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.home_penalties', null)
            ->assertJsonPath('data.penalties_allowed', false);
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
}
