<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\League;
use App\Models\LeagueDrawPlan;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LeagueDrawGamesTest extends TestCase
{
    use RefreshDatabase;

    private const ROUND_ROBIN_DRAW = ['format' => 'round_robin', 'fixture_mode' => 'draw', 'groups_count' => 1, 'group_legs' => 1];

    private const HYBRID_DRAW = [
        'format' => 'hybrid',
        'fixture_mode' => 'draw',
        'groups_count' => 2,
        'group_legs' => 1,
        'qualifiers_per_group' => 1,
        'knockout_size' => 2,
        'knockout_legs' => 1,
        'final_legs' => 1,
        'knockout_tiebreakers' => ['penalties'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $user = User::factory()->create();
        $user->assignRole('master');
        Sanctum::actingAs($user);
    }

    private function league(array $groupSizes, array $attributes = []): League
    {
        $league = League::factory()->create([
            ...(count($groupSizes) > 1 ? self::HYBRID_DRAW : self::ROUND_ROBIN_DRAW),
            'groups_count' => count($groupSizes),
            ...$attributes,
        ]);

        foreach (array_values($groupSizes) as $index => $size) {
            foreach (Team::factory()->count($size)->create() as $team) {
                $league->teams()->attach($team->id, ['group_number' => $index + 1]);
            }
        }

        return $league;
    }

    private function teams(League $league, int $groupNumber = 1): array
    {
        $ids = $league->teams()->wherePivot('group_number', $groupNumber)->orderBy('teams.id')->pluck('teams.id')->all();

        return array_combine(range(1, count($ids)), $ids);
    }

    private function drawnGame(League $league, array $teams, int $round, int $home, int $away, int $groupNumber = 1): Game
    {
        return Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $teams[$home],
            'away_team_id' => $teams[$away],
            'round' => $round,
            'group_number' => $groupNumber,
            'match_date' => null,
            'status' => 'scheduled',
        ]);
    }

    private function fiveTeamsTwoRounds(): array
    {
        $league = $this->league([5]);
        $teams = $this->teams($league);

        $games = [
            'r1a' => $this->drawnGame($league, $teams, 1, 1, 4),
            'r1b' => $this->drawnGame($league, $teams, 1, 2, 5),
            'r2a' => $this->drawnGame($league, $teams, 2, 1, 5),
            'r2b' => $this->drawnGame($league, $teams, 2, 3, 4),
        ];

        return [$league, $teams, $games];
    }

    private function drawRound(League $league, int $round)
    {
        return $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => $round]);
    }

    private function pairs(League $league): array
    {
        return Game::where('league_id', $league->id)->get()
            ->map(fn (Game $game) => collect([$game->home_team_id, $game->away_team_id])->sort()->implode('-'))
            ->all();
    }

    public function test_valid_swap_with_the_bye_team_is_saved_and_replaces_the_plan(): void
    {
        [$league, $teams, $games] = $this->fiveTeamsTwoRounds();

        $this->putJson("/api/games/{$games['r2a']->id}", ['home_team_id' => $teams[1], 'away_team_id' => $teams[2]])
            ->assertOk()
            ->assertJsonPath('data.home_team_id', $teams[1])
            ->assertJsonPath('data.away_team_id', $teams[2]);

        $plan = LeagueDrawPlan::where('league_id', $league->id)->sole();
        $this->assertSame(1, $plan->turn);
        $this->assertCount(3, $plan->rounds());

        $this->getJson("/api/leagues/{$league->id}/draw")
            ->assertJsonPath('data.groups.0.byes.1.round', 2)
            ->assertJsonPath('data.groups.0.byes.1.team.id', $teams[5]);

        foreach ([3, 4, 5] as $round) {
            $this->drawRound($league, $round)->assertOk();
        }

        $pairs = $this->pairs($league);
        $this->assertCount(10, $pairs);
        $this->assertSame($pairs, array_values(array_unique($pairs)));
    }

    public function test_swap_that_prevents_completing_the_table_is_rejected(): void
    {
        [$league, $teams] = $this->fiveTeamsTwoRounds();
        $this->drawnGame($league, $teams, 3, 3, 5);
        $game = $this->drawnGame($league, $teams, 3, 1, 2);

        $this->putJson("/api/games/{$game->id}", ['home_team_id' => $teams[2], 'away_team_id' => $teams[4]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Essa troca impede completar a tabela sem repetir confronto.');

        $this->assertSame($teams[1], $game->fresh()->home_team_id);
        $this->assertSame($teams[2], $game->fresh()->away_team_id);
        $this->assertSame(0, LeagueDrawPlan::count());
    }

    public function test_swap_cannot_repeat_a_pair_even_if_the_game_was_cancelled(): void
    {
        [$league, $teams, $games] = $this->fiveTeamsTwoRounds();
        $games['r1b']->update(['status' => 'cancelled']);

        $this->putJson("/api/games/{$games['r2a']->id}", ['home_team_id' => $teams[2], 'away_team_id' => $teams[5]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esse confronto já aconteceu neste turno.');
    }

    public function test_swap_cannot_give_a_team_a_second_bye(): void
    {
        [$league, $teams, $games] = $this->fiveTeamsTwoRounds();
        $teamThree = Team::find($teams[3]);

        $this->putJson("/api/games/{$games['r2b']->id}", ['home_team_id' => $teams[2], 'away_team_id' => $teams[4]])
            ->assertStatus(422)
            ->assertJsonPath('message', "Com essa troca, o time {$teamThree->name} folgaria duas vezes no turno.");
    }

    public function test_swap_cannot_put_a_team_twice_in_the_round(): void
    {
        [$league, $teams, $games] = $this->fiveTeamsTwoRounds();
        $teamThree = Team::find($teams[3]);

        $this->putJson("/api/games/{$games['r2a']->id}", ['home_team_id' => $teams[1], 'away_team_id' => $teams[3]])
            ->assertStatus(422)
            ->assertJsonPath('message', "O time {$teamThree->name} já joga na rodada 2.");
    }

    public function test_swap_requires_teams_from_the_same_group(): void
    {
        $league = $this->league([4, 4]);
        $this->drawRound($league, 1)->assertOk();
        $game = Game::where('league_id', $league->id)->where('group_number', 1)->first();
        $otherGroupTeam = $this->teams($league, 2)[1];

        $this->putJson("/api/games/{$game->id}", ['away_team_id' => $otherGroupTeam])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Os dois times precisam ser do grupo A, o mesmo da partida.');
    }

    public function test_return_leg_only_allows_inverting_home(): void
    {
        $league = $this->league([3], ['group_legs' => 2]);
        $teams = $this->teams($league);
        foreach ([1, 2, 3, 4] as $round) {
            $this->drawRound($league, $round)->assertOk();
        }
        $game = Game::where('league_id', $league->id)->where('round', 4)->sole();
        $bye = collect($teams)->diff([$game->home_team_id, $game->away_team_id])->first();

        $this->putJson("/api/games/{$game->id}", ['home_team_id' => $bye])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No returno espelhado os confrontos seguem o turno; só é possível inverter o mando.');

        $this->putJson("/api/games/{$game->id}", ['home_team_id' => $game->away_team_id, 'away_team_id' => $game->home_team_id])
            ->assertOk()
            ->assertJsonPath('data.home_team_id', $game->away_team_id);
    }

    public function test_date_stadium_status_and_home_inversion_are_free_in_drawn_games(): void
    {
        [$league, $teams, $games] = $this->fiveTeamsTwoRounds();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);

        $this->putJson("/api/games/{$games['r1a']->id}", [
            'home_team_id' => $teams[4],
            'away_team_id' => $teams[1],
            'match_date' => '2026-12-01 16:00:00',
            'stadium_id' => $stadium->id,
            'status' => 'finished',
            'round' => 1,
            'group_number' => 1,
            'league_id' => $league->id,
            'phase' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.home_team_id', $teams[4])
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.stadium_id', $stadium->id);

        $this->assertSame(0, LeagueDrawPlan::count());
    }

    public static function lockedFields(): array
    {
        return [
            'rodada' => ['round', 3, 'O campo rodada não pode ser alterado em partidas sorteadas.'],
            'grupo' => ['group_number', 2, 'O campo grupo não pode ser alterado em partidas sorteadas.'],
            'fase' => ['phase', 'Final', 'O campo fase não pode ser alterado em partidas sorteadas.'],
            'ordem da fase' => ['phase_order', 1, 'O campo ordem da fase não pode ser alterado em partidas sorteadas.'],
            'chave' => ['tie_key', 'SF1', 'O campo chave do confronto não pode ser alterado em partidas sorteadas.'],
            'ida/volta' => ['leg', 1, 'O campo jogo de ida/volta não pode ser alterado em partidas sorteadas.'],
            'rodada nula' => ['round', null, 'O campo rodada não pode ser alterado em partidas sorteadas.'],
        ];
    }

    #[DataProvider('lockedFields')]
    public function test_drawn_game_structure_fields_are_locked(string $field, mixed $value, string $message): void
    {
        [, , $games] = $this->fiveTeamsTwoRounds();

        $response = $this->putJson("/api/games/{$games['r1a']->id}", [$field => $value]);

        $response->assertStatus(422);
        $this->assertSame($message, $response->json("errors.{$field}.0"));
        $this->assertSame(1, $games['r1a']->fresh()->round);
    }

    public function test_drawn_game_cannot_move_to_another_league(): void
    {
        [, , $games] = $this->fiveTeamsTwoRounds();
        $other = League::factory()->create();

        $this->putJson("/api/games/{$games['r1a']->id}", ['league_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.league_id.0', 'O campo liga não pode ser alterado em partidas sorteadas.');
    }

    public function test_drawn_game_cannot_be_deleted(): void
    {
        [, , $games] = $this->fiveTeamsTwoRounds();

        $this->deleteJson("/api/games/{$games['r1a']->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Partidas de rodada sorteada não podem ser excluídas; desfaça a rodada no sorteio.');

        $this->assertNotNull($games['r1a']->fresh());
    }

    public function test_manual_game_without_round_can_be_created_and_deleted_in_draw_league(): void
    {
        $league = $this->league([4, 4]);
        $teams = $this->teams($league);

        $response = $this->postJson('/api/games', [
            'league_id' => $league->id,
            'home_team_id' => $teams[1],
            'away_team_id' => $teams[2],
            'phase' => 'Final',
            'status' => 'scheduled',
        ])->assertCreated()->assertJsonPath('data.group_number', null);

        $this->deleteJson('/api/games/'.$response->json('data.id'))->assertNoContent();
    }

    public function test_draw_league_rejects_manual_round_games(): void
    {
        $league = $this->league([4]);
        $teams = $this->teams($league);

        $this->postJson('/api/games', [
            'league_id' => $league->id,
            'home_team_id' => $teams[1],
            'away_team_id' => $teams[2],
            'round' => 1,
            'status' => 'scheduled',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.round.0', 'Em ligas com sorteio, as partidas de rodada são criadas pelo sorteio.');

        $manual = League::factory()->create();
        $manual->teams()->attach([$teams[1], $teams[2]]);
        $game = Game::factory()->create(['league_id' => $manual->id, 'home_team_id' => $teams[1], 'away_team_id' => $teams[2], 'round' => 4]);

        $this->putJson("/api/games/{$game->id}", ['league_id' => $league->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.round.0', 'Em ligas com sorteio, as partidas de rodada são criadas pelo sorteio.');
    }

    public function test_manual_game_cannot_set_round_or_group_in_draw_league(): void
    {
        $league = $this->league([4, 4]);
        $teams = $this->teams($league);
        $game = Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $teams[1], 'away_team_id' => $teams[2], 'round' => null, 'phase' => 'Final']);

        $this->putJson("/api/games/{$game->id}", ['round' => 2, 'phase' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.round.0', 'Em ligas com sorteio, as partidas de rodada são criadas pelo sorteio.');

        $this->putJson("/api/games/{$game->id}", ['group_number' => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.group_number.0', 'O grupo da partida é definido pelo sorteio.');

        $this->putJson("/api/games/{$game->id}", ['status' => 'finished', 'group_number' => null])->assertOk();
    }

    public function test_manual_leagues_keep_accepting_round_games(): void
    {
        $league = League::factory()->create();
        $teams = Team::factory()->count(2)->create();
        $league->teams()->attach($teams->pluck('id'));

        $this->postJson('/api/games', [
            'league_id' => $league->id,
            'home_team_id' => $teams[0]->id,
            'away_team_id' => $teams[1]->id,
            'round' => 1,
            'status' => 'scheduled',
        ])->assertCreated();
    }

    public function test_team_cannot_join_a_league_with_drawn_rounds(): void
    {
        $league = $this->league([4]);
        $this->drawRound($league, 1)->assertOk();
        $team = Team::factory()->create();
        $member = Team::find($this->teams($league)[1]);

        $this->postJson("/api/teams/{$team->id}/leagues", ['league_ids' => [$league->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', "Não é possível adicionar o time à liga {$league->name} porque ela já tem rodadas sorteadas.");

        $this->postJson('/api/teams', ['name' => 'Novo', 'league_ids' => [$league->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', "Não é possível adicionar o time à liga {$league->name} porque ela já tem rodadas sorteadas.");

        $this->postJson("/api/teams/{$member->id}/leagues", ['league_ids' => [$league->id]])->assertOk();
        $this->assertSame(4, $league->teams()->count());
        $this->assertSame(0, Team::where('name', 'Novo')->count());
    }

    public function test_team_can_join_a_draw_league_before_the_draw(): void
    {
        $league = $this->league([4]);
        $team = Team::factory()->create();

        $this->postJson("/api/teams/{$team->id}/leagues", ['league_ids' => [$league->id]])->assertOk();

        $this->drawRound($league, 1)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Todos os times da liga precisam estar em um grupo antes do sorteio.');
    }

    public function test_round_numbers_are_global_across_groups(): void
    {
        $league = $this->league([3, 3]);

        $this->drawRound($league, 1)->assertOk();
        $this->drawRound($league, 2)->assertOk();

        $games = Game::where('league_id', $league->id)->orderBy('round')->orderBy('group_number')->get();

        $this->assertSame([1, 1, 2, 2], $games->pluck('round')->all());
        $this->assertSame([1, 2, 1, 2], $games->pluck('group_number')->all());
    }
}
