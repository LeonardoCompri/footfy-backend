<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GameEventType;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameEventTypeBehaviorTest extends TestCase
{
    use RefreshDatabase;

    private League $league;

    private Team $home;

    private Team $away;

    private Game $game;

    private Player $homePlayer;

    private Player $homeMate;

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
        $this->homeMate = Player::factory()->create(['team_id' => $this->home->id]);
        $this->awayPlayer = Player::factory()->create(['team_id' => $this->away->id]);
    }

    private function eventPayload(string $type, ?Player $player = null, array $overrides = []): array
    {
        $player ??= $this->homePlayer;

        return array_merge([
            'game_id' => $this->game->id,
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => $type,
            'minute' => 10,
        ], $overrides);
    }

    private function postEvent(string $type, ?Player $player = null, array $overrides = []): int
    {
        return $this->postJson('/api/game-events', $this->eventPayload($type, $player, $overrides))
            ->assertCreated()
            ->json('data.id');
    }

    private function assertScore(?int $home, ?int $away): void
    {
        $game = $this->game->fresh();

        $this->assertSame([$home, $away], [$game->home_score, $game->away_score]);
    }

    public function test_store_rejects_inactive_and_unknown_types(): void
    {
        GameEventType::factory()->inactive()->create(['slug' => 'antigo']);

        foreach (['antigo', 'inexistente'] as $type) {
            $this->postJson('/api/game-events', $this->eventPayload($type))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['type' => 'O tipo de evento informado não existe ou está inativo.']);
        }

        $this->assertDatabaseCount('game_events', 0);
    }

    public function test_store_accepts_new_active_type_and_returns_slug(): void
    {
        GameEventType::factory()->create(['slug' => 'penalty_saved']);

        $this->postJson('/api/game-events', $this->eventPayload('penalty_saved'))
            ->assertCreated()
            ->assertJsonPath('data.type', 'penalty_saved');
    }

    public function test_update_keeps_inactive_type_already_stored_but_rejects_switching_to_it(): void
    {
        $inactive = GameEventType::factory()->create(['slug' => 'antigo']);
        $id = $this->postEvent('antigo');
        $other = $this->postEvent('goal');
        $inactive->update(['active' => false]);

        $this->putJson("/api/game-events/{$id}", ['type' => 'antigo', 'minute' => 30])
            ->assertOk()
            ->assertJsonPath('data.type', 'antigo')
            ->assertJsonPath('data.minute', 30);
        $this->putJson("/api/game-events/{$id}", ['minute' => 31])->assertOk();

        $this->putJson("/api/game-events/{$other}", ['type' => 'antigo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
        $this->assertDatabaseHas('game_events', ['id' => $other, 'type' => 'goal']);
    }

    public function test_assist_is_rejected_on_type_without_allows_assist(): void
    {
        GameEventType::factory()->create(['slug' => 'penalty_saved']);

        foreach (['yellow_card', 'own_goal', 'penalty_saved'] as $type) {
            $this->postJson('/api/game-events', $this->eventPayload($type, null, ['related_player_id' => $this->homeMate->id]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['related_player_id' => 'O tipo de evento informado não permite assistência.']);
        }

        $this->assertDatabaseCount('game_events', 0);
    }

    public function test_assist_is_accepted_on_type_with_allows_assist(): void
    {
        GameEventType::factory()->scoring()->create(['slug' => 'gol_olimpico']);

        $this->postEvent('goal', null, ['related_player_id' => $this->homeMate->id]);
        $this->postEvent('gol_olimpico', null, ['related_player_id' => $this->homeMate->id]);

        $this->assertDatabaseCount('game_events', 2);
    }

    public function test_update_to_type_without_assist_requires_clearing_the_assist(): void
    {
        $id = $this->postEvent('goal', null, ['related_player_id' => $this->homeMate->id]);

        $this->putJson("/api/game-events/{$id}", ['type' => 'yellow_card'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('related_player_id');
        $this->assertScore(1, 0);

        $this->putJson("/api/game-events/{$id}", ['type' => 'yellow_card', 'related_player_id' => null])
            ->assertOk()
            ->assertJsonPath('data.related_player_id', null);
        $this->assertScore(0, 0);
    }

    public function test_assist_on_event_with_type_as_array_does_not_crash(): void
    {
        $this->postJson('/api/game-events', $this->eventPayload('goal', null, [
            'type' => ['goal'],
            'related_player_id' => $this->homeMate->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_new_scoring_type_counts_in_score_and_top_scorers(): void
    {
        GameEventType::factory()->scoring()->create(['slug' => 'gol_olimpico']);

        $this->postEvent('gol_olimpico', $this->homePlayer, ['related_player_id' => $this->homeMate->id]);
        $this->postEvent('goal', $this->homePlayer);
        $this->assertScore(2, 0);

        $this->putJson("/api/games/{$this->game->id}", ['status' => 'finished'])->assertOk();
        $response = $this->getJson("/api/leagues/{$this->league->id}/statistics")->assertOk();

        $scorers = collect($response->json('data.top_scorers'))->keyBy('player_id');
        $this->assertSame(2, $scorers[$this->homePlayer->id]['goals']);
        $assists = collect($response->json('data.top_assists'))->keyBy('player_id');
        $this->assertSame(1, $assists[$this->homeMate->id]['assists']);
        $this->assertSame(2, $response->json('data.totals.goals'));
    }

    public function test_new_opponent_type_counts_for_the_other_team(): void
    {
        GameEventType::factory()->create(['slug' => 'gol_contra_bizarro', 'counts_for' => 'opponent']);

        $this->postEvent('gol_contra_bizarro', $this->awayPlayer);

        $this->assertScore(1, 0);
    }

    public function test_type_without_flags_does_not_count_anywhere(): void
    {
        GameEventType::factory()->create(['slug' => 'penalty_saved']);

        $this->postEvent('penalty_saved', $this->homePlayer);
        $this->postEvent('penalty_saved', $this->awayPlayer);
        $this->assertScore(0, 0);

        $this->putJson("/api/games/{$this->game->id}", ['status' => 'finished'])->assertOk();
        $response = $this->getJson("/api/leagues/{$this->league->id}/statistics")->assertOk();

        $this->assertSame([], $response->json('data.top_scorers'));
        $this->assertSame([], $response->json('data.top_assists'));
        $this->assertSame([], $response->json('data.top_cards'));
        $this->assertSame(['yellow' => 0, 'red' => 0], $response->json('data.cards_split'));
    }

    public function test_new_red_card_type_counts_in_cards_statistics(): void
    {
        GameEventType::factory()->card('red')->create(['slug' => 'vermelho_direto']);

        $this->postEvent('vermelho_direto', $this->awayPlayer);
        $this->postEvent('yellow_card', $this->awayPlayer);

        $response = $this->getJson("/api/leagues/{$this->league->id}/statistics")->assertOk();

        $this->assertSame(['yellow' => 1, 'red' => 1], $response->json('data.cards_split'));
        $cards = collect($response->json('data.top_cards'))->keyBy('player_id');
        $this->assertSame(1, $cards[$this->awayPlayer->id]['yellow_cards']);
        $this->assertSame(1, $cards[$this->awayPlayer->id]['red_cards']);
        $this->assertSame(2, $cards[$this->awayPlayer->id]['total_cards']);
    }

    public function test_new_card_type_breaks_tie_in_the_table(): void
    {
        GameEventType::factory()->card('red')->create(['slug' => 'vermelho_direto']);
        GameEventType::factory()->create(['slug' => 'penalty_saved']);
        [$teamX, $teamY, $teamA, $teamB] = Team::factory()->count(4)->create();
        $this->league->teams()->attach([$teamX->id, $teamY->id, $teamA->id, $teamB->id]);
        $gameX = Game::factory()->create([
            'league_id' => $this->league->id, 'home_team_id' => $teamX->id, 'away_team_id' => $teamA->id,
            'round' => 2, 'status' => 'finished', 'home_score' => 1, 'away_score' => 0,
        ]);
        $gameY = Game::factory()->create([
            'league_id' => $this->league->id, 'home_team_id' => $teamY->id, 'away_team_id' => $teamB->id,
            'round' => 2, 'status' => 'finished', 'home_score' => 1, 'away_score' => 0,
        ]);
        $event = fn (Game $game, Team $team, string $type) => GameEvent::factory()->create([
            'league_id' => $this->league->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'player_id' => Player::factory()->create(['team_id' => $team->id])->id,
            'type' => $type,
        ]);
        $event($gameX, $teamX, 'vermelho_direto');
        $event($gameY, $teamY, 'penalty_saved');
        $event($gameY, $teamY, 'penalty_saved');

        $standings = collect($this->getJson("/api/leagues/{$this->league->id}/table")->assertOk()->json('data.standings'));

        $positionX = $standings->search(fn ($row) => $row['team_id'] === $teamX->id);
        $positionY = $standings->search(fn ($row) => $row['team_id'] === $teamY->id);
        $this->assertLessThan($positionX, $positionY);
    }
}
