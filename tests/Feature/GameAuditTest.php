<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Championship;
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
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class GameAuditTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

    private League $league;

    private Team $home;

    private Team $away;

    private Game $game;

    private Player $homePlayer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->league = $this->league();
        [$this->home, $this->away] = Team::factory()->count(2)->create();
        $this->league->teams()->attach([$this->home->id, $this->away->id]);
        $this->game = Game::factory()->create([
            'league_id' => $this->league->id,
            'home_team_id' => $this->home->id,
            'away_team_id' => $this->away->id,
            'round' => 1,
            'status' => 'in_progress',
            'home_score' => 0,
            'away_score' => 0,
        ]);
        $this->homePlayer = Player::factory()->create(['team_id' => $this->home->id]);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        $this->linkUserToLeagues($this->user, [$this->league->id]);
        Sanctum::actingAs($this->user);
    }

    private function league(array $attributes = []): League
    {
        return League::factory()->create(['championship_id' => Championship::factory()->create()->id, ...$attributes]);
    }

    private function failOnAudit(string $action): void
    {
        AuditLog::created(function (AuditLog $log) use ($action) {
            if ($log->action === $action) {
                throw new RuntimeException('falha simulada');
            }
        });
    }

    private function assertGameContext(AuditLog $log, string $action, $subject, ?League $league = null): void
    {
        $league ??= $this->league;

        $this->assertSame($action, $log->action);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame($subject::class, $log->auditable_type);
        $this->assertSame($subject->id, $log->auditable_id);
        $this->assertSame($league->id, $log->league_id);
        $this->assertSame($league->championship_id, $log->championship_id);
    }

    private function postGoal(?Player $player = null, ?Game $game = null)
    {
        $player ??= $this->homePlayer;

        return $this->postJson('/api/game-events', [
            'game_id' => ($game ?? $this->game)->id,
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => 'goal',
            'minute' => 10,
        ]);
    }

    public function test_finishing_a_game_records_one_log_with_the_status_change(): void
    {
        $this->putJson("/api/games/{$this->game->id}", ['status' => 'finished'])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertGameContext($log, 'game.updated', $this->game);
        $this->assertSame(['status' => 'in_progress'], $log->old_values);
        $this->assertSame(['status' => 'finished'], $log->new_values);
        $this->assertNull($log->meta);
    }

    public function test_changing_teams_and_league_records_one_log_with_the_diff(): void
    {
        $other = $this->league();
        $this->linkUserToLeagues($this->user, [$this->league->id, $other->id]);
        $newAway = Team::factory()->create();
        $other->teams()->attach([$this->home->id, $newAway->id]);
        AuditLog::query()->delete();

        $this->putJson("/api/games/{$this->game->id}", ['league_id' => $other->id, 'away_team_id' => $newAway->id])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertGameContext($log, 'game.updated', $this->game, $other);
        $this->assertSame(['away_team_id' => $this->away->id, 'league_id' => $this->league->id], $log->old_values);
        $this->assertSame(['away_team_id' => $newAway->id, 'league_id' => $other->id], $log->new_values);
    }

    public function test_update_without_changes_records_nothing(): void
    {
        $this->putJson("/api/games/{$this->game->id}", ['status' => 'in_progress'])->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_failure_while_auditing_the_game_update_rolls_it_back(): void
    {
        $this->failOnAudit('game.updated');

        $this->putJson("/api/games/{$this->game->id}", ['status' => 'finished'])->assertStatus(500);

        $this->assertSame('in_progress', $this->game->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_deleting_a_game_records_one_log_with_the_snapshot(): void
    {
        $this->deleteJson("/api/games/{$this->game->id}")->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertGameContext($log, 'game.deleted', $this->game);
        $this->assertSame($this->home->id, $log->old_values['home_team_id']);
        $this->assertSame('in_progress', $log->old_values['status']);
        $this->assertNull($log->new_values);
    }

    public function test_failure_while_auditing_the_game_deletion_keeps_the_game(): void
    {
        $this->failOnAudit('game.deleted');

        $this->deleteJson("/api/games/{$this->game->id}")->assertStatus(500);

        $this->assertDatabaseHas('games', ['id' => $this->game->id]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_creating_a_goal_records_the_score_before_and_after(): void
    {
        $eventId = $this->postGoal()->assertCreated()->json('data.id');

        $log = AuditLog::query()->sole();
        $this->assertGameContext($log, 'game_event.created', GameEvent::find($eventId));
        $this->assertNull($log->old_values);
        $this->assertSame('goal', $log->new_values['type']);
        $this->assertSame($this->homePlayer->id, $log->new_values['player_id']);
        $this->assertSame([[
            'game_id' => $this->game->id,
            'before' => ['home_score' => 0, 'away_score' => 0],
            'after' => ['home_score' => 1, 'away_score' => 0],
        ]], $log->meta['scores']);
    }

    public function test_failure_while_auditing_the_event_keeps_event_and_score_unchanged(): void
    {
        $this->failOnAudit('game_event.created');

        $this->postGoal()->assertStatus(500);

        $this->assertDatabaseCount('game_events', 0);
        $this->assertSame(0, $this->game->fresh()->home_score);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_updating_an_event_records_only_the_diff(): void
    {
        $eventId = $this->postGoal()->json('data.id');
        AuditLog::query()->delete();

        $this->putJson("/api/game-events/{$eventId}", ['minute' => 55])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertGameContext($log, 'game_event.updated', GameEvent::find($eventId));
        $this->assertSame(['minute' => 10], $log->old_values);
        $this->assertSame(['minute' => 55], $log->new_values);
        $this->assertSame([['home_score' => 1, 'away_score' => 0]], array_column($log->meta['scores'], 'after'));

        $this->putJson("/api/game-events/{$eventId}", ['minute' => 55])->assertOk();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_moving_an_event_to_another_game_records_both_scores(): void
    {
        $eventId = $this->postGoal()->json('data.id');
        $other = Game::factory()->create([
            'league_id' => $this->league->id,
            'home_team_id' => $this->home->id,
            'away_team_id' => $this->away->id,
            'round' => 2,
            'status' => 'in_progress',
            'home_score' => 0,
            'away_score' => 0,
        ]);
        AuditLog::query()->delete();

        $this->putJson("/api/game-events/{$eventId}", ['game_id' => $other->id])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('game_event.updated', $log->action);
        $this->assertSame(['game_id' => $this->game->id], $log->old_values);
        $this->assertSame([
            ['game_id' => $this->game->id, 'before' => ['home_score' => 1, 'away_score' => 0], 'after' => ['home_score' => 0, 'away_score' => 0]],
            ['game_id' => $other->id, 'before' => ['home_score' => 0, 'away_score' => 0], 'after' => ['home_score' => 1, 'away_score' => 0]],
        ], $log->meta['scores']);
    }

    public function test_deleting_an_event_records_the_snapshot_and_the_score(): void
    {
        $eventId = $this->postGoal()->json('data.id');
        AuditLog::query()->delete();

        $this->deleteJson("/api/game-events/{$eventId}")->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertSame('game_event.deleted', $log->action);
        $this->assertSame($eventId, $log->auditable_id);
        $this->assertSame($this->league->id, $log->league_id);
        $this->assertSame('goal', $log->old_values['type']);
        $this->assertSame([[
            'game_id' => $this->game->id,
            'before' => ['home_score' => 1, 'away_score' => 0],
            'after' => ['home_score' => 0, 'away_score' => 0],
        ]], $log->meta['scores']);
    }

    public function test_failure_while_auditing_the_event_deletion_keeps_the_event(): void
    {
        $eventId = $this->postGoal()->json('data.id');
        $this->failOnAudit('game_event.deleted');

        $this->deleteJson("/api/game-events/{$eventId}")->assertStatus(500);

        $this->assertDatabaseHas('game_events', ['id' => $eventId]);
        $this->assertSame(1, $this->game->fresh()->home_score);
        $this->assertSame(0, AuditLog::where('action', 'game_event.deleted')->count());
    }

    public function test_penalty_kicks_record_one_log_with_previous_and_new_kicks(): void
    {
        $final = $this->finalTiedOneOne();
        $kicks = $this->kicks($final);
        $awayKicker = $kicks[1]['player_id'];
        AuditLog::query()->delete();

        $this->putJson("/api/games/{$final->id}/penalty-kicks", ['kicks' => $kicks])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertGameContext($log, 'game.penalty_kicks_updated', $final);
        $this->assertSame(['kicks' => [], 'home_penalties' => null, 'away_penalties' => null], $log->old_values);
        $this->assertSame(5, $log->new_values['home_penalties']);
        $this->assertSame(4, $log->new_values['away_penalties']);
        $this->assertCount(10, $log->new_values['kicks']);
        $this->assertSame(
            ['team_id' => $final->away_team_id, 'player_id' => $awayKicker, 'order' => 10, 'scored' => false],
            $log->new_values['kicks'][9],
        );

        $this->putJson("/api/games/{$final->id}/penalty-kicks", ['kicks' => $kicks])->assertOk();
        $this->assertDatabaseCount('audit_logs', 1);

        $this->putJson("/api/games/{$final->id}/penalty-kicks", ['kicks' => []])->assertOk();
        $cleared = AuditLog::query()->latest('id')->first();
        $this->assertSame('game.penalty_kicks_updated', $cleared->action);
        $this->assertCount(10, $cleared->old_values['kicks']);
        $this->assertSame(['kicks' => [], 'home_penalties' => null, 'away_penalties' => null], $cleared->new_values);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_reopening_a_game_that_clears_penalties_records_it_in_meta(): void
    {
        $final = $this->finalTiedOneOne();
        $kicks = $this->kicks($final);
        $this->putJson("/api/games/{$final->id}/penalty-kicks", ['kicks' => $kicks])->assertOk();
        AuditLog::query()->delete();

        $this->putJson("/api/games/{$final->id}", ['status' => 'in_progress'])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('game.updated', $log->action);
        $this->assertSame(['status' => 'finished'], $log->old_values);
        $this->assertSame(['penalties_cleared_game_id' => $final->id], $log->meta);
    }

    private function kicks(Game $final): array
    {
        $homeKicker = Player::factory()->create(['team_id' => $final->home_team_id]);
        $awayKicker = Player::factory()->create(['team_id' => $final->away_team_id]);
        $kicks = [];

        for ($i = 0; $i < 5; $i++) {
            $kicks[] = ['team_id' => $final->home_team_id, 'player_id' => $homeKicker->id, 'scored' => true];
            $kicks[] = ['team_id' => $final->away_team_id, 'player_id' => $awayKicker->id, 'scored' => $i < 4];
        }

        return $kicks;
    }

    private function finalTiedOneOne(): Game
    {
        $this->league->update([
            'format' => 'hybrid',
            'fixture_mode' => 'draw',
            'groups_count' => 2,
            'group_legs' => 1,
            'qualifiers_per_group' => 1,
            'knockout_size' => 2,
            'knockout_legs' => 1,
            'final_legs' => 1,
            'knockout_tiebreakers' => ['penalties'],
        ]);
        $this->game->delete();
        $this->league->teams()->detach();

        foreach ([1, 2] as $group) {
            $this->league->teams()->attach(Team::factory()->count(2)->create()->pluck('id'), ['group_number' => $group]);
        }

        $draw = app(LeagueDrawService::class);
        $state = $draw->build($this->league->fresh());
        while ($state['next_round'] !== null) {
            $state = $draw->drawNextRound((string) $this->league->id, $state['next_round']);
        }

        foreach (Game::where('league_id', $this->league->id)->get() as $game) {
            $game->update(['status' => 'finished', 'home_score' => 1, 'away_score' => 0]);
        }

        $this->postJson("/api/leagues/{$this->league->id}/knockout/next-stage", ['stage' => 'F'])->assertOk();
        $final = Game::where('league_id', $this->league->id)->where('knockout_stage', 'F')->sole();
        $final->update(['status' => 'finished', 'home_score' => 1, 'away_score' => 1]);

        return $final->fresh();
    }
}
