<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeagueStatisticsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Role::create(['name' => 'master']);
        Role::create(['name' => 'admin']);
    }

    private function authenticate(): User
    {
        $user = User::factory()->create();
        $user->assignRole('master');
        Sanctum::actingAs($user);

        return $user;
    }

    private function authenticateAsAdmin(array $leagueIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        if (! empty($leagueIds)) {
            $user->leagues()->sync($leagueIds);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function finishedGame(League $league, Team $home, Team $away, int $homeScore, int $awayScore, array $overrides = []): Game
    {
        return Game::factory()->create(array_merge([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'status' => 'finished',
            'round' => 1,
            'phase' => null,
            'phase_order' => null,
            'tie_key' => null,
            'leg' => null,
        ], $overrides));
    }

    private function goal(League $league, Game $game, Team $team, Player $scorer, ?Player $assister = null): GameEvent
    {
        return GameEvent::factory()->create([
            'league_id' => $league->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'player_id' => $scorer->id,
            'related_player_id' => $assister?->id,
            'type' => 'goal',
        ]);
    }

    private function card(League $league, Game $game, Team $team, Player $player, string $type): GameEvent
    {
        return GameEvent::factory()->create([
            'league_id' => $league->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'player_id' => $player->id,
            'type' => $type,
        ]);
    }

    // ── Artilharia / assistências / cartões ─────────────────────────────────

    public function test_statistics_aggregate_scorers_assists_and_cards(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);

        $scorer = Player::factory()->create(['team_id' => $teamA->id, 'name' => 'Artilheiro']);
        $assister = Player::factory()->create(['team_id' => $teamA->id, 'name' => 'Garçom']);
        $carded = Player::factory()->create(['team_id' => $teamB->id, 'name' => 'Pistola']);

        $game = $this->finishedGame($league, $teamA, $teamB, 2, 0);
        $this->goal($league, $game, $teamA, $scorer, $assister);
        $this->goal($league, $game, $teamA, $scorer);
        $this->card($league, $game, $teamB, $carded, 'yellow_card');
        $this->card($league, $game, $teamB, $carded, 'yellow_card');
        $this->card($league, $game, $teamB, $carded, 'red_card');

        $response = $this->getJson("/api/leagues/{$league->id}/statistics");

        $response->assertOk();

        $scorers = collect($response->json('data.top_scorers'))->keyBy('player_id');
        $this->assertSame(2, $scorers[$scorer->id]['goals']);
        $this->assertSame($teamA->id, $scorers[$scorer->id]['team_id']);

        $assists = collect($response->json('data.top_assists'))->keyBy('player_id');
        $this->assertSame(1, $assists[$assister->id]['assists']);

        $cards = collect($response->json('data.top_cards'))->keyBy('player_id');
        $this->assertSame(2, $cards[$carded->id]['yellow_cards']);
        $this->assertSame(1, $cards[$carded->id]['red_cards']);
        $this->assertSame(3, $cards[$carded->id]['total_cards']);
    }

    // ── Liga híbrida: mata-mata entra na estatística (diferente da Tabela da Liga) ──

    public function test_hybrid_league_sums_group_stage_and_knockout_goals(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'hybrid']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);
        $player = Player::factory()->create(['team_id' => $teamA->id]);

        $groupGame = $this->finishedGame($league, $teamA, $teamB, 1, 0, ['round' => 1]);
        $this->goal($league, $groupGame, $teamA, $player);

        $knockoutGame = $this->finishedGame($league, $teamA, $teamB, 2, 0, [
            'round' => null, 'phase' => 'Final', 'phase_order' => 1, 'tie_key' => 'final-1', 'leg' => 1,
        ]);
        $this->goal($league, $knockoutGame, $teamA, $player);
        $this->goal($league, $knockoutGame, $teamA, $player);

        $response = $this->getJson("/api/leagues/{$league->id}/statistics");

        $response->assertOk();
        $scorers = collect($response->json('data.top_scorers'))->keyBy('player_id');

        // 1 gol na fase de grupos + 2 no mata-mata = 3, soma que a Tabela da Liga não faria
        // (lá o mata-mata fica isolado em `phases`, sem contar pontos/gols de `standings`).
        $this->assertSame(3, $scorers[$player->id]['goals']);
    }

    // ── Caso de borda: transferência de jogador entre times da mesma liga ───

    public function test_player_transferred_between_teams_sums_goals_across_teams(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamA, $teamB, $teamC] = Team::factory()->count(3)->create();
        $league->teams()->attach([$teamA->id, $teamB->id, $teamC->id]);

        // Jogador atualmente no time B (transferência), mas marcou gols também pelo time A.
        $player = Player::factory()->create(['team_id' => $teamB->id]);

        $gameWithA = $this->finishedGame($league, $teamA, $teamC, 1, 0);
        $this->goal($league, $gameWithA, $teamA, $player);

        $gameWithB = $this->finishedGame($league, $teamB, $teamC, 1, 0);
        $this->goal($league, $gameWithB, $teamB, $player);

        $response = $this->getJson("/api/leagues/{$league->id}/statistics");

        $response->assertOk();
        $scorers = collect($response->json('data.top_scorers'))->keyBy('player_id');

        $this->assertSame(2, $scorers[$player->id]['goals']);
        // Exibição usa o time atual do jogador (players.team_id), não o time do evento.
        $this->assertSame($teamB->id, $scorers[$player->id]['team_id']);
    }

    // ── goals_by_bucket ───────────────────────────────────────────────────────

    public function test_goals_by_bucket_groups_by_round_for_round_robin(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);
        $player = Player::factory()->create(['team_id' => $teamA->id]);

        $round1 = $this->finishedGame($league, $teamA, $teamB, 2, 0, ['round' => 1]);
        $this->goal($league, $round1, $teamA, $player);
        $this->goal($league, $round1, $teamA, $player);

        $round2 = $this->finishedGame($league, $teamB, $teamA, 1, 1, ['round' => 2]);
        $this->goal($league, $round2, $teamB, $player);

        $response = $this->getJson("/api/leagues/{$league->id}/statistics");

        $response->assertOk();
        $buckets = collect($response->json('data.goals_by_bucket'))->keyBy('round');

        $this->assertSame(2, $buckets[1]['goals']);
        $this->assertSame('round', $buckets[1]['type']);
        $this->assertSame(1, $buckets[2]['goals']);
    }

    public function test_goals_by_bucket_groups_by_phase_for_knockout(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'knockout']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);
        $player = Player::factory()->create(['team_id' => $teamA->id]);

        $semi = $this->finishedGame($league, $teamA, $teamB, 2, 1, [
            'round' => null, 'phase' => 'Semifinal', 'phase_order' => 1, 'tie_key' => 'semi-1', 'leg' => 1,
        ]);
        $this->goal($league, $semi, $teamA, $player);
        $this->goal($league, $semi, $teamA, $player);

        $final = $this->finishedGame($league, $teamA, $teamB, 1, 0, [
            'round' => null, 'phase' => 'Final', 'phase_order' => 2, 'tie_key' => 'final-1', 'leg' => 1,
        ]);
        $this->goal($league, $final, $teamA, $player);

        $response = $this->getJson("/api/leagues/{$league->id}/statistics");

        $response->assertOk();
        $buckets = collect($response->json('data.goals_by_bucket'))->keyBy('phase');

        $this->assertSame(2, $buckets['Semifinal']['goals']);
        $this->assertSame('phase', $buckets['Semifinal']['type']);
        $this->assertSame(1, $buckets['Final']['goals']);
    }

    // ── results_split ─────────────────────────────────────────────────────────

    public function test_results_split_matches_real_counts_including_knockout_games(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'hybrid']);
        [$teamA, $teamB, $teamC, $teamD] = Team::factory()->count(4)->create();
        $league->teams()->attach([$teamA->id, $teamB->id, $teamC->id, $teamD->id]);

        $this->finishedGame($league, $teamA, $teamB, 2, 0, ['round' => 1]); // mandante vence
        $this->finishedGame($league, $teamB, $teamA, 1, 1, ['round' => 2]); // empate
        $this->finishedGame($league, $teamC, $teamD, 0, 0, ['round' => 1]); // empate
        $this->finishedGame($league, $teamA, $teamB, 0, 2, [
            'round' => null, 'phase' => 'Final', 'phase_order' => 1, 'tie_key' => 'final-1', 'leg' => 1,
        ]); // visitante vence (mata-mata entra na contagem)

        // Partida não finalizada não deve entrar na contagem.
        Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $teamC->id,
            'away_team_id' => $teamD->id,
            'home_score' => null,
            'away_score' => null,
            'status' => 'scheduled',
            'round' => 3,
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/statistics");

        $response->assertOk();
        $this->assertSame([
            'home_wins' => 1,
            'draws' => 2,
            'away_wins' => 1,
        ], $response->json('data.results_split'));
    }

    // ── Caso de borda: liga sem eventos cadastrados ──────────────────────────

    public function test_league_without_events_returns_empty_statistics_without_error(): void
    {
        $this->authenticate();
        $league = League::factory()->create(['format' => 'round_robin']);

        $response = $this->getJson("/api/leagues/{$league->id}/statistics");

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'top_scorers' => [],
                'top_assists' => [],
                'top_cards' => [],
                'goals_by_bucket' => [],
                'results_split' => ['home_wins' => 0, 'draws' => 0, 'away_wins' => 0],
                'cards_split' => ['yellow' => 0, 'red' => 0],
            ],
        ]);
        // assertJson compara frouxamente; garante inteiros (não null/"0") no cards_split.
        $this->assertSame(['yellow' => 0, 'red' => 0], $response->json('data.cards_split'));
    }

    // ── cards_split ───────────────────────────────────────────────────────────

    /**
     * Monta duas ligas: a liga alvo com 12 jogadores cartoados (acima do RANKING_LIMIT de
     * top_cards) somando 14 amarelos e 3 vermelhos, e outra liga com cartões que não podem
     * vazar para o total da primeira. Gols também são criados para garantir que só cartões
     * entram na soma.
     *
     * @return array{0: League, 1: League}
     */
    private function buildTwoLeaguesWithCards(): array
    {
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);
        $game = $this->finishedGame($league, $teamA, $teamB, 1, 0);

        for ($i = 0; $i < 12; $i++) {
            $player = Player::factory()->create(['team_id' => $teamA->id]);
            $this->card($league, $game, $teamA, $player, 'yellow_card');
        }
        $repeatOffender = Player::factory()->create(['team_id' => $teamB->id]);
        $this->card($league, $game, $teamB, $repeatOffender, 'yellow_card');
        $this->card($league, $game, $teamB, $repeatOffender, 'yellow_card');
        $this->card($league, $game, $teamB, $repeatOffender, 'red_card');
        $sentOff = Player::factory()->create(['team_id' => $teamB->id]);
        $this->card($league, $game, $teamB, $sentOff, 'red_card');
        $this->card($league, $game, $teamB, $sentOff, 'red_card');
        $this->goal($league, $game, $teamA, $sentOff);

        $otherLeague = League::factory()->create(['format' => 'round_robin']);
        [$teamC, $teamD] = Team::factory()->count(2)->create();
        $otherLeague->teams()->attach([$teamC->id, $teamD->id]);
        $otherGame = $this->finishedGame($otherLeague, $teamC, $teamD, 0, 0);
        $otherPlayer = Player::factory()->create(['team_id' => $teamC->id]);
        $this->card($otherLeague, $otherGame, $teamC, $otherPlayer, 'yellow_card');
        $this->card($otherLeague, $otherGame, $teamC, $otherPlayer, 'yellow_card');
        $this->card($otherLeague, $otherGame, $teamC, $otherPlayer, 'red_card');

        return [$league, $otherLeague];
    }

    public function test_cards_split_counts_all_cards_of_the_league_without_ranking_limit(): void
    {
        $this->authenticate();
        [$league, $otherLeague] = $this->buildTwoLeaguesWithCards();

        $response = $this->getJson("/api/leagues/{$league->id}/statistics");

        $response->assertOk();
        // 12 + 2 amarelos, 1 + 2 vermelhos — mesmo com top_cards cortado em 10 jogadores.
        $this->assertSame(['yellow' => 14, 'red' => 3], $response->json('data.cards_split'));
        $this->assertCount(10, $response->json('data.top_cards'));

        $otherResponse = $this->getJson("/api/leagues/{$otherLeague->id}/statistics");
        $this->assertSame(['yellow' => 2, 'red' => 1], $otherResponse->json('data.cards_split'));
    }

    public function test_cards_split_on_public_route_matches_admin_route(): void
    {
        [$league] = $this->buildTwoLeaguesWithCards();

        $publicResponse = $this->getJson("/api/public/leagues/{$league->id}/statistics");
        $publicResponse->assertOk();
        $this->assertSame(['yellow' => 14, 'red' => 3], $publicResponse->json('data.cards_split'));

        $this->authenticateAsAdmin([$league->id]);
        $adminResponse = $this->getJson("/api/leagues/{$league->id}/statistics");
        $adminResponse->assertOk();
        $this->assertSame($adminResponse->json('data.cards_split'), $publicResponse->json('data.cards_split'));
    }

    public function test_cards_split_runs_exactly_one_aggregate_query(): void
    {
        $this->authenticate();
        [$league] = $this->buildTwoLeaguesWithCards();

        // Aquece o cache de roles/permissions antes de medir.
        $this->getJson("/api/leagues/{$league->id}/statistics")->assertOk();

        $cardsSplitQueries = [];
        DB::listen(function ($query) use (&$cardsSplitQueries) {
            // Alias `as yellow,` é exclusivo do cardsSplit (topCards usa `as yellow_cards`).
            if (str_contains($query->sql, 'as yellow,')) {
                $cardsSplitQueries[] = $query->sql;
            }
        });

        $this->getJson("/api/leagues/{$league->id}/statistics")->assertOk();

        $this->assertCount(1, $cardsSplitQueries);
        $sql = strtolower($cardsSplitQueries[0]);
        $this->assertStringContainsString('sum(case when type', $sql);
        $this->assertStringNotContainsString('group by', $sql);
        $this->assertStringNotContainsString('join', $sql);
    }

    // ── Autorização ───────────────────────────────────────────────────────────

    public function test_admin_without_league_access_gets_forbidden(): void
    {
        $league = League::factory()->create(['format' => 'round_robin']);
        $this->authenticateAsAdmin();

        $response = $this->getJson("/api/leagues/{$league->id}/statistics");

        $response->assertForbidden()->assertJsonPath('message', 'Você não tem acesso a este recurso.');
    }

    public function test_admin_with_league_access_can_view_statistics(): void
    {
        $league = League::factory()->create(['format' => 'round_robin']);
        $this->authenticateAsAdmin([$league->id]);

        $response = $this->getJson("/api/leagues/{$league->id}/statistics");

        $response->assertOk();
    }

    public function test_guest_cannot_access_admin_statistics_route(): void
    {
        $league = League::factory()->create(['format' => 'round_robin']);

        $response = $this->getJson("/api/leagues/{$league->id}/statistics");

        $response->assertStatus(401);
    }

    // ── Rota pública usa a mesma fonte de cálculo ───────────────────────────

    public function test_guest_can_view_statistics_via_public_route_with_same_data_as_admin(): void
    {
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);
        $player = Player::factory()->create(['team_id' => $teamA->id]);
        $game = $this->finishedGame($league, $teamA, $teamB, 1, 0);
        $this->goal($league, $game, $teamA, $player);

        $publicResponse = $this->getJson("/api/public/leagues/{$league->id}/statistics");
        $publicResponse->assertOk();

        $this->authenticate();
        $adminResponse = $this->getJson("/api/leagues/{$league->id}/statistics");
        $adminResponse->assertOk();

        $this->assertSame($adminResponse->json('data'), $publicResponse->json('data'));
    }

    // ── Performance: sem N+1 ─────────────────────────────────────────────────

    private function buildLeagueWithGoals(int $playersCount): League
    {
        $league = League::factory()->create(['format' => 'round_robin']);
        [$teamA, $teamB] = Team::factory()->count(2)->create();
        $league->teams()->attach([$teamA->id, $teamB->id]);
        $game = $this->finishedGame($league, $teamA, $teamB, $playersCount, 0);

        for ($i = 0; $i < $playersCount; $i++) {
            $player = Player::factory()->create(['team_id' => $teamA->id]);
            $this->goal($league, $game, $teamA, $player);
        }

        return $league;
    }

    public function test_statistics_query_count_does_not_grow_with_event_count(): void
    {
        $this->authenticate();

        $smallLeague = $this->buildLeagueWithGoals(3);
        $largeLeague = $this->buildLeagueWithGoals(15);

        // Aquece o cache de roles/permissions antes de medir.
        $this->getJson("/api/leagues/{$smallLeague->id}/statistics")->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson("/api/leagues/{$smallLeague->id}/statistics")->assertOk();
        $smallQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->getJson("/api/leagues/{$largeLeague->id}/statistics")->assertOk();
        $largeQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($smallQueryCount, $largeQueryCount);
    }
}
