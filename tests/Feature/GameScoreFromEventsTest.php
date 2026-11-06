<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Repositories\GameRepository;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameScoreFromEventsTest extends TestCase
{
    use RefreshDatabase;

    private League $league;

    private Team $home;

    private Team $away;

    private Game $game;

    private Player $homePlayer;

    private Player $awayPlayer;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $user = User::factory()->create();
        $user->assignRole('master');
        Sanctum::actingAs($user);

        $this->league = League::factory()->create(['format' => 'round_robin']);
        [$this->home, $this->away] = Team::factory()->count(2)->create();
        $this->league->teams()->attach([$this->home->id, $this->away->id]);
        $this->game = Game::factory()->create([
            'league_id' => $this->league->id,
            'home_team_id' => $this->home->id,
            'away_team_id' => $this->away->id,
            'round' => 1,
            'status' => 'in_progress',
        ]);
        $this->homePlayer = Player::factory()->create(['team_id' => $this->home->id]);
        $this->awayPlayer = Player::factory()->create(['team_id' => $this->away->id]);
    }

    private function postEvent(string $type, Player $player, ?Game $game = null): int
    {
        return $this->postJson('/api/game-events', [
            'game_id' => ($game ?? $this->game)->id,
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => $type,
            'minute' => 10,
        ])->assertCreated()->json('data.id');
    }

    private function assertScore(?int $home, ?int $away, ?Game $game = null): void
    {
        $game = ($game ?? $this->game)->fresh();

        $this->assertSame([$home, $away], [$game->home_score, $game->away_score]);
    }

    public function test_creating_goal_credits_the_team_of_the_event(): void
    {
        $this->postEvent('goal', $this->homePlayer);
        $this->assertScore(1, 0);

        $this->postEvent('goal', $this->awayPlayer);
        $this->assertScore(1, 1);
    }

    public function test_own_goal_is_credited_to_the_opponent(): void
    {
        $this->postEvent('own_goal', $this->homePlayer);
        $this->assertScore(0, 1);

        $this->postEvent('own_goal', $this->awayPlayer);
        $this->assertScore(1, 1);
    }

    public function test_score_combines_goals_and_own_goals_and_ignores_cards(): void
    {
        $this->postEvent('goal', $this->homePlayer);
        $this->postEvent('goal', $this->homePlayer);
        $this->postEvent('goal', $this->awayPlayer);
        $this->postEvent('own_goal', $this->awayPlayer);
        $this->postEvent('yellow_card', $this->homePlayer);
        $this->postEvent('red_card', $this->awayPlayer);

        $this->assertScore(3, 1);
        $this->getJson("/api/games/{$this->game->id}")
            ->assertOk()
            ->assertJsonPath('data.home_score', 3)
            ->assertJsonPath('data.away_score', 1);
    }

    public function test_card_on_game_without_score_sets_zero_to_zero(): void
    {
        $this->postEvent('yellow_card', $this->homePlayer);

        $this->assertScore(0, 0);
    }

    public function test_updating_type_from_goal_to_own_goal_moves_the_goal_to_the_opponent(): void
    {
        $id = $this->postEvent('goal', $this->homePlayer);
        $this->assertScore(1, 0);

        $this->putJson("/api/game-events/{$id}", ['type' => 'own_goal'])->assertOk();
        $this->assertScore(0, 1);

        $this->putJson("/api/game-events/{$id}", ['type' => 'yellow_card'])->assertOk();
        $this->assertScore(0, 0);

        $this->putJson("/api/game-events/{$id}", ['type' => 'goal'])->assertOk();
        $this->assertScore(1, 0);
    }

    public function test_updating_team_and_player_moves_the_goal_to_the_other_team(): void
    {
        $id = $this->postEvent('goal', $this->homePlayer);

        $this->putJson("/api/game-events/{$id}", [
            'team_id' => $this->away->id,
            'player_id' => $this->awayPlayer->id,
        ])->assertOk();

        $this->assertScore(0, 1);
    }

    public function test_moving_event_to_another_game_recalculates_both_games(): void
    {
        $otherGame = Game::factory()->create([
            'league_id' => $this->league->id,
            'home_team_id' => $this->away->id,
            'away_team_id' => $this->home->id,
            'round' => 2,
        ]);
        $id = $this->postEvent('goal', $this->homePlayer);
        $this->assertScore(1, 0);

        $this->putJson("/api/game-events/{$id}", ['game_id' => $otherGame->id])->assertOk();

        $this->assertScore(0, 0);
        $this->assertScore(0, 1, $otherGame);
    }

    public function test_deleting_goal_and_own_goal_updates_the_score(): void
    {
        $goal = $this->postEvent('goal', $this->homePlayer);
        $ownGoal = $this->postEvent('own_goal', $this->homePlayer);
        $this->assertScore(1, 1);

        $this->deleteJson("/api/game-events/{$ownGoal}")->assertNoContent();
        $this->assertScore(1, 0);

        $this->deleteJson("/api/game-events/{$goal}")->assertNoContent();
        $this->assertScore(0, 0);
    }

    public function test_score_recalculation_query_has_no_order_by(): void
    {
        GameEvent::factory()->create([
            'game_id' => $this->game->id,
            'league_id' => $this->league->id,
            'team_id' => $this->home->id,
            'player_id' => $this->homePlayer->id,
        ]);

        DB::enableQueryLog();
        app(GameRepository::class)->scoringEventCounts($this->game);
        $query = strtolower(DB::getQueryLog()[0]['query']);
        DB::disableQueryLog();

        $this->assertStringContainsString('group by', $query);
        $this->assertStringNotContainsString('order by', $query);
    }

    public function test_own_goal_requires_player_of_the_event_team(): void
    {
        $this->postJson('/api/game-events', [
            'game_id' => $this->game->id,
            'team_id' => $this->away->id,
            'player_id' => $this->homePlayer->id,
            'type' => 'own_goal',
        ])->assertStatus(422)->assertJsonValidationErrors('player_id');

        $this->assertDatabaseCount('game_events', 0);
        $this->assertScore(null, null);
    }

    public function test_event_is_rolled_back_when_score_recalculation_fails(): void
    {
        $this->partialMock(GameRepository::class)
            ->shouldReceive('findForUpdate')
            ->andThrow(new \RuntimeException('falha simulada'));

        $this->postJson('/api/game-events', [
            'game_id' => $this->game->id,
            'team_id' => $this->home->id,
            'player_id' => $this->homePlayer->id,
            'type' => 'goal',
        ])->assertStatus(500);

        $this->assertDatabaseCount('game_events', 0);
        $this->assertScore(null, null);
    }

    public function test_own_goal_does_not_count_for_the_player_in_top_scorers(): void
    {
        $this->postEvent('goal', $this->homePlayer);
        $this->postEvent('own_goal', $this->awayPlayer);
        $this->postEvent('own_goal', $this->awayPlayer);
        $this->putJson("/api/games/{$this->game->id}", ['status' => 'finished'])->assertOk();

        $response = $this->getJson("/api/leagues/{$this->league->id}/statistics")->assertOk();

        $scorers = collect($response->json('data.top_scorers'))->keyBy('player_id');
        $this->assertSame(1, $scorers[$this->homePlayer->id]['goals']);
        $this->assertFalse($scorers->has($this->awayPlayer->id));
        $this->assertSame(3, $response->json('data.totals.goals'));
    }

    public function test_update_game_ignores_score_in_payload(): void
    {
        $this->postEvent('goal', $this->homePlayer);

        $this->putJson("/api/games/{$this->game->id}", ['home_score' => 5, 'away_score' => 4])
            ->assertOk()
            ->assertJsonPath('data.home_score', 1)
            ->assertJsonPath('data.away_score', 0);

        $this->assertScore(1, 0);
    }

    public function test_update_scheduled_game_ignores_score_in_payload(): void
    {
        $this->game->update(['status' => 'scheduled']);

        $this->putJson("/api/games/{$this->game->id}", ['home_score' => 2, 'away_score' => 1])
            ->assertOk()
            ->assertJsonPath('data.home_score', null);

        $this->assertScore(null, null);
    }

    public function test_store_game_ignores_score_in_payload(): void
    {
        $id = $this->postJson('/api/games', [
            'league_id' => $this->league->id,
            'home_team_id' => $this->home->id,
            'away_team_id' => $this->away->id,
            'round' => 2,
            'status' => 'scheduled',
            'home_score' => 3,
            'away_score' => 1,
        ])->assertCreated()->assertJsonPath('data.home_score', null)->json('data.id');

        $this->assertScore(null, null, Game::find($id));
    }

    public function test_store_game_already_finished_starts_zero_to_zero(): void
    {
        $id = $this->postJson('/api/games', [
            'league_id' => $this->league->id,
            'home_team_id' => $this->home->id,
            'away_team_id' => $this->away->id,
            'round' => 2,
            'status' => 'finished',
            'home_score' => 3,
        ])->assertCreated()->json('data.id');

        $this->assertScore(0, 0, Game::find($id));
    }

    public function test_finishing_game_without_events_fills_zero_to_zero_and_counts_in_table(): void
    {
        $this->game->update(['status' => 'scheduled']);

        $this->putJson("/api/games/{$this->game->id}", ['status' => 'finished'])
            ->assertOk()
            ->assertJsonPath('data.home_score', 0)
            ->assertJsonPath('data.away_score', 0);

        $standings = collect($this->getJson("/api/leagues/{$this->league->id}/table")->json('data.standings'))
            ->keyBy('team_id');
        $this->assertSame(1, $standings[$this->home->id]['played']);
        $this->assertSame(1, $standings[$this->home->id]['draws']);
    }

    public function test_starting_game_with_null_score_uses_events_count(): void
    {
        $this->game->update(['status' => 'scheduled']);
        GameEvent::factory()->create([
            'game_id' => $this->game->id,
            'league_id' => $this->league->id,
            'team_id' => $this->away->id,
            'player_id' => $this->awayPlayer->id,
            'type' => 'goal',
        ]);
        GameEvent::factory()->ownGoal()->create([
            'game_id' => $this->game->id,
            'league_id' => $this->league->id,
            'team_id' => $this->away->id,
            'player_id' => $this->awayPlayer->id,
        ]);
        $this->assertScore(null, null);

        $this->putJson("/api/games/{$this->game->id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.home_score', 1)
            ->assertJsonPath('data.away_score', 1);
    }

    public function test_game_back_to_scheduled_without_score_keeps_null(): void
    {
        $this->game->update(['status' => 'scheduled']);

        $this->putJson("/api/games/{$this->game->id}", ['match_date' => '2026-12-01 16:00:00'])->assertOk();

        $this->assertScore(null, null);
    }

    public function test_changing_away_team_recalculates_score_without_old_team_goals(): void
    {
        $this->postEvent('goal', $this->homePlayer);
        $this->postEvent('goal', $this->awayPlayer);
        $this->assertScore(1, 1);
        $newAway = Team::factory()->create();
        $this->league->teams()->attach($newAway->id);

        $this->putJson("/api/games/{$this->game->id}", ['away_team_id' => $newAway->id])
            ->assertOk()
            ->assertJsonPath('data.home_score', 1)
            ->assertJsonPath('data.away_score', 0);
    }
}
