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
use App\Services\Draw\RoundRobinScheduler;
use App\Services\LeagueDrawService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class DrawKnockoutAuditTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

    private array $ranking = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->app->instance(RoundRobinScheduler::class, new RoundRobinScheduler(new Randomizer(new Mt19937(7)), 200, 200000));
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

    private function league(int $groupsCount = 2, int $groupSize = 4, int $qualifiers = 2): League
    {
        $league = League::factory()->create([
            'championship_id' => Championship::factory()->create()->id,
            'format' => 'hybrid',
            'fixture_mode' => 'draw',
            'groups_count' => $groupsCount,
            'group_legs' => 1,
            'qualifiers_per_group' => $qualifiers,
            'knockout_size' => $groupsCount * $qualifiers,
            'knockout_legs' => 1,
            'final_legs' => 1,
            'third_place' => true,
            'knockout_tiebreakers' => ['penalties'],
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

    private function drawAll(League $league): void
    {
        $service = app(LeagueDrawService::class);
        $state = $service->build($league);

        while ($state['next_round'] !== null) {
            $state = $service->drawNextRound((string) $league->id, $state['next_round']);
        }
    }

    private function finishGames($games): void
    {
        foreach ($games as $game) {
            $homeBetter = $this->position($game->home_team_id) < $this->position($game->away_team_id);
            $game->update(['status' => 'finished', 'home_score' => $homeBetter ? 2 : 0, 'away_score' => $homeBetter ? 0 : 2]);
        }
    }

    private function position(int $teamId): int
    {
        foreach ($this->ranking as $teams) {
            if (($index = array_search($teamId, $teams, true)) !== false) {
                return $index;
            }
        }

        return PHP_INT_MAX;
    }

    private function readyForKnockout(): League
    {
        $league = $this->league();
        $this->drawAll($league);
        $this->finishGames(Game::where('league_id', $league->id)->whereNotNull('group_number')->get());
        AuditLog::query()->delete();

        return $league;
    }

    private function gameIds(League $league, callable $scope): array
    {
        return $scope(Game::where('league_id', $league->id))->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function failOnAudit(string $action): void
    {
        AuditLog::created(function (AuditLog $log) use ($action) {
            if ($log->action === $action) {
                throw new RuntimeException('falha simulada');
            }
        });
    }

    private function assertLeagueContext(AuditLog $log, League $league, User $user): void
    {
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(League::class, $log->auditable_type);
        $this->assertSame($league->id, $log->auditable_id);
        $this->assertSame($league->id, $log->league_id);
        $this->assertSame($league->championship_id, $log->championship_id);
    }

    public function test_drawing_a_round_records_one_log_with_the_created_games(): void
    {
        $league = $this->league();
        $user = $this->authenticateAs('admin', [$league->id]);

        $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => 1])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('draw.round_drawn', $log->action);
        $this->assertLeagueContext($log, $league, $user);
        $this->assertSame(['drawn_rounds' => 0], $log->old_values);
        $this->assertSame(['drawn_rounds' => 1], $log->new_values);
        $this->assertSame(['round' => 1, 'game_ids' => $this->gameIds($league, fn ($q) => $q->where('round', 1))], $log->meta);
        $this->assertCount(4, $log->meta['game_ids']);
    }

    public function test_drawing_a_round_that_replaces_manual_games_lists_them_in_the_meta(): void
    {
        $league = $this->league();
        $user = $this->authenticateAs('master');
        $manual = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $this->ranking[1][0],
            'away_team_id' => $this->ranking[1][1],
            'round' => 1,
            'group_number' => null,
        ]);

        $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => 1, 'replace_manual_rounds' => true])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertLeagueContext($log, $league, $user);
        $this->assertSame([$manual->id], $log->meta['replaced_manual_game_ids']);
        $this->assertSame($this->gameIds($league, fn ($q) => $q->where('round', 1)), $log->meta['game_ids']);
    }

    public function test_blocked_draw_records_nothing(): void
    {
        $league = $this->league();
        $this->authenticateAs('master');

        $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => 2])->assertStatus(422);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_failure_while_recording_the_draw_rolls_back_the_round(): void
    {
        $league = $this->league();
        $this->authenticateAs('master');
        $this->failOnAudit('draw.round_drawn');

        $this->postJson("/api/leagues/{$league->id}/draw/next-round", ['round' => 1])->assertStatus(500);

        $this->assertSame(0, Game::where('league_id', $league->id)->count());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_undoing_a_round_records_one_log_with_the_deleted_games(): void
    {
        $league = $this->league();
        $user = $this->authenticateAs('admin', [$league->id]);
        $service = app(LeagueDrawService::class);
        $service->drawNextRound((string) $league->id, 1);
        $service->drawNextRound((string) $league->id, 2);
        $roundTwo = $this->gameIds($league, fn ($q) => $q->where('round', 2));
        AuditLog::query()->delete();

        $this->deleteJson("/api/leagues/{$league->id}/draw/last-round", ['round' => 2])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('draw.round_undone', $log->action);
        $this->assertLeagueContext($log, $league, $user);
        $this->assertSame(['drawn_rounds' => 2], $log->old_values);
        $this->assertSame(['drawn_rounds' => 1], $log->new_values);
        $this->assertSame(['round' => 2, 'game_ids' => $roundTwo], $log->meta);
        $this->assertSame(0, Game::whereIn('id', $roundTwo)->count());
    }

    public function test_resetting_the_draw_records_one_log_with_all_deleted_games(): void
    {
        $league = $this->league();
        $user = $this->authenticateAs('master');
        $service = app(LeagueDrawService::class);
        $service->drawNextRound((string) $league->id, 1);
        $service->drawNextRound((string) $league->id, 2);
        $drawn = $this->gameIds($league, fn ($q) => $q->whereNotNull('group_number'));
        AuditLog::query()->delete();

        $this->deleteJson("/api/leagues/{$league->id}/draw")->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('draw.reset', $log->action);
        $this->assertLeagueContext($log, $league, $user);
        $this->assertSame(['drawn_rounds' => 2], $log->old_values);
        $this->assertSame(['drawn_rounds' => 0], $log->new_values);
        $this->assertSame(['game_ids' => $drawn], $log->meta);
    }

    public function test_generating_a_knockout_stage_records_one_log_with_the_created_games(): void
    {
        $league = $this->readyForKnockout();
        $user = $this->authenticateAs('admin', [$league->id]);

        $this->postJson("/api/leagues/{$league->id}/knockout/next-stage", ['stage' => 'SF'])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('knockout.stage_generated', $log->action);
        $this->assertLeagueContext($log, $league, $user);
        $this->assertSame(['last_stage' => null], $log->old_values);
        $this->assertSame(['last_stage' => 'SF'], $log->new_values);
        $this->assertSame(['stages' => ['SF'], 'game_ids' => $this->gameIds($league, fn ($q) => $q->where('knockout_stage', 'SF'))], $log->meta);
        $this->assertCount(2, $log->meta['game_ids']);
    }

    public function test_generating_the_final_records_the_third_place_in_the_same_log(): void
    {
        $league = $this->readyForKnockout();
        $user = $this->authenticateAs('master');
        $this->postJson("/api/leagues/{$league->id}/knockout/next-stage", ['stage' => 'SF'])->assertOk();
        $this->finishGames(Game::where('league_id', $league->id)->where('knockout_stage', 'SF')->get());
        AuditLog::query()->delete();

        $this->postJson("/api/leagues/{$league->id}/knockout/next-stage", ['stage' => 'F'])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertLeagueContext($log, $league, $user);
        $this->assertSame(['last_stage' => 'SF'], $log->old_values);
        $this->assertSame(['last_stage' => 'F'], $log->new_values);
        $this->assertSame(['F', '3P'], $log->meta['stages']);
        $this->assertSame($this->gameIds($league, fn ($q) => $q->whereIn('knockout_stage', ['F', '3P'])), $log->meta['game_ids']);
    }

    public function test_generating_the_first_stage_replacing_manual_phases_lists_them_in_the_meta(): void
    {
        $league = $this->readyForKnockout();
        $this->authenticateAs('master');
        $manual = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $this->ranking[1][0],
            'away_team_id' => $this->ranking[2][0],
            'round' => null,
            'group_number' => null,
            'phase' => 'Final',
            'phase_order' => 1,
        ]);

        $this->postJson("/api/leagues/{$league->id}/knockout/next-stage", ['stage' => 'SF', 'replace_manual_phases' => true])->assertOk();

        $this->assertSame([$manual->id], AuditLog::query()->sole()->meta['replaced_manual_game_ids']);
    }

    public function test_undoing_a_knockout_stage_records_one_log_with_the_deleted_games(): void
    {
        $league = $this->readyForKnockout();
        $user = $this->authenticateAs('admin', [$league->id]);
        $this->postJson("/api/leagues/{$league->id}/knockout/next-stage", ['stage' => 'SF'])->assertOk();
        $semis = $this->gameIds($league, fn ($q) => $q->where('knockout_stage', 'SF'));
        AuditLog::query()->delete();

        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'SF'])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('knockout.stage_undone', $log->action);
        $this->assertLeagueContext($log, $league, $user);
        $this->assertSame(['last_stage' => 'SF'], $log->old_values);
        $this->assertSame(['last_stage' => null], $log->new_values);
        $this->assertSame(['stages' => ['SF'], 'game_ids' => $semis], $log->meta);
    }

    public function test_undoing_the_final_records_the_semifinal_as_the_new_last_stage(): void
    {
        $league = $this->readyForKnockout();
        $user = $this->authenticateAs('master');
        $this->postJson("/api/leagues/{$league->id}/knockout/next-stage", ['stage' => 'SF'])->assertOk();
        $this->finishGames(Game::where('league_id', $league->id)->where('knockout_stage', 'SF')->get());
        $this->postJson("/api/leagues/{$league->id}/knockout/next-stage", ['stage' => 'F'])->assertOk();
        $finals = $this->gameIds($league, fn ($q) => $q->whereIn('knockout_stage', ['F', '3P']));
        AuditLog::query()->delete();

        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'F'])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('knockout.stage_undone', $log->action);
        $this->assertLeagueContext($log, $league, $user);
        $this->assertSame(['last_stage' => 'F'], $log->old_values);
        $this->assertSame(['last_stage' => 'SF'], $log->new_values);
        $this->assertSame(['stages' => ['F', '3P'], 'game_ids' => $finals], $log->meta);
    }

    public function test_blocked_knockout_undo_records_nothing(): void
    {
        $league = $this->readyForKnockout();
        $this->authenticateAs('master');
        $this->postJson("/api/leagues/{$league->id}/knockout/next-stage", ['stage' => 'SF'])->assertOk();
        $semi = Game::where('league_id', $league->id)->where('knockout_stage', 'SF')->firstOrFail();
        GameEvent::factory()->create([
            'game_id' => $semi->id,
            'league_id' => $league->id,
            'team_id' => $semi->home_team_id,
            'player_id' => Player::factory()->create(['team_id' => $semi->home_team_id])->id,
            'type' => 'yellow_card',
        ]);
        AuditLog::query()->delete();

        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'SF'])->assertStatus(422);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_failure_while_recording_the_knockout_undo_keeps_the_stage(): void
    {
        $league = $this->readyForKnockout();
        $this->authenticateAs('master');
        $this->postJson("/api/leagues/{$league->id}/knockout/next-stage", ['stage' => 'SF'])->assertOk();
        AuditLog::query()->delete();
        $this->failOnAudit('knockout.stage_undone');

        $this->deleteJson("/api/leagues/{$league->id}/knockout/last-stage", ['stage' => 'SF'])->assertStatus(500);

        $this->assertSame(2, Game::where('league_id', $league->id)->where('knockout_stage', 'SF')->count());
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
