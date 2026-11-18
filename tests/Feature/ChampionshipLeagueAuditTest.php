<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Championship;
use App\Models\Game;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class ChampionshipLeagueAuditTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

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

    private function league(array $attributes = []): League
    {
        return League::factory()->create(['championship_id' => Championship::factory()->create()->id, ...$attributes]);
    }

    private function drawLeague(int $teams = 4): League
    {
        $league = $this->league(self::HYBRID_DRAW);
        $league->teams()->attach(Team::factory()->count($teams)->create()->pluck('id'));

        return $league;
    }

    private function failOnAudit(string $action): void
    {
        AuditLog::created(function (AuditLog $log) use ($action) {
            if ($log->action === $action) {
                throw new RuntimeException('falha simulada');
            }
        });
    }

    private function assertLeagueContext(AuditLog $log, League $league): void
    {
        $this->assertSame(League::class, $log->auditable_type);
        $this->assertSame($league->id, $log->auditable_id);
        $this->assertSame($league->id, $log->league_id);
        $this->assertSame($league->championship_id, $log->championship_id);
    }

    public function test_creating_a_championship_records_one_log_with_the_image_path(): void
    {
        Storage::fake('public');
        $user = $this->authenticateAs('master');

        $this->post('/api/championships', ['name' => 'Copa Audit', 'image' => UploadedFile::fake()->image('logo.jpg')], ['Accept' => 'application/json'])
            ->assertCreated();

        $championship = Championship::query()->sole();
        $log = AuditLog::query()->sole();
        $this->assertSame('championship.created', $log->action);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(Championship::class, $log->auditable_type);
        $this->assertSame($championship->id, $log->auditable_id);
        $this->assertSame($championship->id, $log->championship_id);
        $this->assertNull($log->league_id);
        $this->assertNull($log->old_values);
        $this->assertSame('Copa Audit', $log->new_values['name']);
        $this->assertSame($championship->image, $log->new_values['image']);
        $this->assertStringStartsWith('championships/', $log->new_values['image']);
    }

    public function test_creating_a_championship_with_leagues_records_one_log_per_created_entity(): void
    {
        $this->authenticateAs('master');

        $this->postJson('/api/championships', [
            'name' => 'Copa Audit',
            'leagues' => [['name' => 'Série A'], ['name' => 'Série B']],
        ])->assertCreated();

        $championship = Championship::query()->sole();
        $this->assertSame(1, AuditLog::where('action', 'championship.created')->count());
        $leagueLogs = AuditLog::where('action', 'league.created')->orderBy('id')->get();
        $this->assertCount(2, $leagueLogs);
        $this->assertSame(3, AuditLog::count());

        foreach ($championship->leagues()->orderBy('id')->get() as $index => $league) {
            $this->assertLeagueContext($leagueLogs[$index], $league);
            $this->assertSame($league->name, $leagueLogs[$index]->new_values['name']);
        }
    }

    public function test_failure_while_creating_the_championship_leagues_leaves_no_log(): void
    {
        $this->authenticateAs('master');
        $this->failOnAudit('league.created');

        $this->postJson('/api/championships', ['name' => 'Copa Audit', 'leagues' => [['name' => 'Série A']]])
            ->assertStatus(500);

        $this->assertDatabaseCount('championships', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_updating_a_championship_records_only_the_changed_fields(): void
    {
        Storage::fake('public');
        $user = $this->authenticateAs('master');
        $oldPath = UploadedFile::fake()->image('old.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['name' => 'Antigo', 'image' => $oldPath]);

        $this->post("/api/championships/{$championship->id}", [
            '_method' => 'PUT',
            'name' => 'Novo',
            'image' => UploadedFile::fake()->image('new.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('championship.updated', $log->action);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($championship->id, $log->championship_id);
        $this->assertSame(['name' => 'Antigo', 'image' => $oldPath], $log->old_values);
        $this->assertSame(['name' => 'Novo', 'image' => $championship->fresh()->image], $log->new_values);
    }

    public function test_updating_only_a_nested_league_records_only_the_league_update(): void
    {
        $this->authenticateAs('master');
        $championship = Championship::factory()->create(['name' => 'Copa']);
        $league = League::factory()->create(['championship_id' => $championship->id, 'name' => 'Série A']);

        $this->putJson("/api/championships/{$championship->id}", [
            'name' => 'Copa',
            'leagues' => [['id' => $league->id, 'name' => 'Série A 2026']],
        ])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('league.updated', $log->action);
        $this->assertLeagueContext($log, $league);
        $this->assertSame(['name' => 'Série A'], $log->old_values);
        $this->assertSame(['name' => 'Série A 2026'], $log->new_values);
    }

    public function test_deleting_a_championship_records_one_log_with_the_cascaded_leagues(): void
    {
        $user = $this->authenticateAs('master');
        $championship = Championship::factory()->create(['name' => 'Copa', 'image' => 'championships/logo.jpg']);
        $leagues = League::factory()->count(2)->create(['championship_id' => $championship->id]);

        $this->deleteJson("/api/championships/{$championship->id}", ['password' => 'password'])->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertSame('championship.deleted', $log->action);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($championship->id, $log->auditable_id);
        $this->assertSame($championship->id, $log->championship_id);
        $this->assertNull($log->league_id);
        $this->assertSame('Copa', $log->old_values['name']);
        $this->assertSame('championships/logo.jpg', $log->old_values['image']);
        $this->assertNull($log->new_values);
        $this->assertSame(
            $leagues->map(fn (League $league) => ['id' => $league->id, 'name' => $league->name])->all(),
            $log->meta['deleted_leagues'],
        );
    }

    public function test_creating_a_league_records_one_log(): void
    {
        $user = $this->authenticateAs('master');
        $championship = Championship::factory()->create();

        $this->postJson('/api/leagues', ['championship_id' => $championship->id, 'name' => 'Série A'])->assertCreated();

        $league = League::query()->sole();
        $log = AuditLog::query()->sole();
        $this->assertSame('league.created', $log->action);
        $this->assertSame($user->id, $log->user_id);
        $this->assertLeagueContext($log, $league);
        $this->assertSame('Série A', $log->new_values['name']);
        $this->assertSame('round_robin', $log->new_values['format']);
        $this->assertSame(35, $log->new_values['max_players_per_team']);
    }

    public function test_updating_the_league_structure_records_one_log_with_the_diff(): void
    {
        $user = $this->authenticateAs('admin');
        $league = $this->league(['format' => 'round_robin']);
        $this->linkUserToLeagues($user, [$league->id]);

        $this->putJson("/api/leagues/{$league->id}", [
            'format' => 'hybrid',
            'fixture_mode' => 'draw',
            'groups_count' => 2,
            'qualifiers_per_group' => 1,
            'knockout_size' => 2,
            'knockout_legs' => 1,
            'final_legs' => 1,
            'knockout_tiebreakers' => ['penalties'],
            'tiebreakers' => ['points', 'goal_difference'],
            'max_players_per_team' => 25,
        ])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('league.updated', $log->action);
        $this->assertSame($user->id, $log->user_id);
        $this->assertLeagueContext($log, $league);
        $this->assertSame('round_robin', $log->old_values['format']);
        $this->assertSame('hybrid', $log->new_values['format']);
        $this->assertSame('draw', $log->new_values['fixture_mode']);
        $this->assertSame(2, $log->new_values['groups_count']);
        $this->assertNull($log->old_values['tiebreakers']);
        $this->assertSame(['points', 'goal_difference'], $log->new_values['tiebreakers']);
        $this->assertSame(['penalties'], $log->new_values['knockout_tiebreakers']);
        $this->assertSame(35, $log->old_values['max_players_per_team']);
        $this->assertSame(25, $log->new_values['max_players_per_team']);
        $this->assertArrayNotHasKey('name', $log->new_values);
        $this->assertArrayNotHasKey('updated_at', $log->new_values);
        $this->assertSame(['groups_cleared' => true], $log->meta);
    }

    public function test_updating_a_league_without_changes_records_nothing(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(['name' => 'Série A']);

        $this->putJson("/api/leagues/{$league->id}", ['name' => 'Série A'])->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_failure_while_updating_a_league_keeps_the_league_and_leaves_no_log(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(['name' => 'Série A']);
        $this->failOnAudit('league.updated');

        $this->putJson("/api/leagues/{$league->id}", ['name' => 'Série B'])->assertStatus(500);

        $this->assertSame('Série A', $league->fresh()->name);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_deleting_a_league_records_one_log_with_the_counts(): void
    {
        $user = $this->authenticateAs('master');
        $league = $this->league(['name' => 'Série A']);
        $teams = Team::factory()->count(2)->create();
        $league->teams()->attach($teams->pluck('id'));
        Game::factory()->create(['league_id' => $league->id, 'home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);

        $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'password'])->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertSame('league.deleted', $log->action);
        $this->assertSame($user->id, $log->user_id);
        $this->assertLeagueContext($log, $league);
        $this->assertSame('Série A', $log->old_values['name']);
        $this->assertNull($log->new_values);
        $this->assertSame(['teams_count' => 2, 'games_count' => 1, 'stadiums_count' => 0], $log->meta);
    }

    public function test_attaching_a_team_records_one_log_per_newly_linked_league(): void
    {
        $user = $this->authenticateAs('master');
        $team = Team::factory()->create();
        $linked = $this->league();
        $new = $this->league();
        $team->leagues()->attach($linked->id);

        $this->postJson("/api/teams/{$team->id}/leagues", ['league_ids' => [$linked->id, $new->id]])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('league.teams_attached', $log->action);
        $this->assertSame($user->id, $log->user_id);
        $this->assertLeagueContext($log, $new);
        $this->assertSame(['team_ids' => [$team->id]], $log->meta);
    }

    public function test_creating_a_team_with_leagues_records_the_attachment(): void
    {
        $user = $this->authenticateAs('admin');
        $league = $this->league();
        $this->linkUserToLeagues($user, [$league->id]);

        $this->postJson('/api/teams', ['name' => 'Time Audit', 'league_ids' => [$league->id]])->assertCreated();

        $team = Team::where('name', 'Time Audit')->sole();
        $log = AuditLog::query()->sole();
        $this->assertSame('league.teams_attached', $log->action);
        $this->assertLeagueContext($log, $league);
        $this->assertSame(['team_ids' => [$team->id]], $log->meta);
    }

    public function test_detaching_a_team_records_one_log_and_nothing_when_it_was_not_linked(): void
    {
        $user = $this->authenticateAs('master');
        $team = Team::factory()->create();
        $league = $this->league(self::HYBRID_DRAW);
        $other = $this->league();
        $team->leagues()->attach($league->id, ['group_number' => 2]);

        $this->deleteJson("/api/teams/{$team->id}/leagues/{$other->id}")->assertOk();
        $this->assertDatabaseCount('audit_logs', 0);

        $this->deleteJson("/api/teams/{$team->id}/leagues/{$league->id}")->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('league.teams_detached', $log->action);
        $this->assertSame($user->id, $log->user_id);
        $this->assertLeagueContext($log, $league);
        $this->assertSame(['team_ids' => [$team->id]], $log->meta);
    }

    public function test_assigning_groups_records_one_log_with_the_old_and_new_groups(): void
    {
        $user = $this->authenticateAs('admin');
        $league = $this->drawLeague();
        $this->linkUserToLeagues($user, [$league->id]);
        $teamIds = $league->teams()->orderBy('teams.id')->pluck('teams.id')->all();
        $league->teams()->updateExistingPivot($teamIds[0], ['group_number' => 1]);

        $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => [
            ['team_id' => $teamIds[0], 'group_number' => 2],
            ['team_id' => $teamIds[1], 'group_number' => 1],
            ['team_id' => $teamIds[2], 'group_number' => 2],
            ['team_id' => $teamIds[3], 'group_number' => 1],
        ]])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('league.groups_updated', $log->action);
        $this->assertSame($user->id, $log->user_id);
        $this->assertLeagueContext($log, $league);
        $this->assertSame(['groups' => [
            ['group_number' => null, 'team_ids' => [$teamIds[1], $teamIds[2], $teamIds[3]]],
            ['group_number' => 1, 'team_ids' => [$teamIds[0]]],
        ]], $log->old_values);
        $this->assertSame(['groups' => [
            ['group_number' => 1, 'team_ids' => [$teamIds[1], $teamIds[3]]],
            ['group_number' => 2, 'team_ids' => [$teamIds[0], $teamIds[2]]],
        ]], $log->new_values);
    }

    public function test_invalid_group_assignment_records_nothing(): void
    {
        $this->authenticateAs('master');
        $league = $this->drawLeague();
        $teamIds = $league->teams()->orderBy('teams.id')->pluck('teams.id')->all();

        $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => [
            ['team_id' => $teamIds[0], 'group_number' => 1],
        ]])->assertStatus(422);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_shuffling_groups_records_one_log_with_the_result(): void
    {
        $user = $this->authenticateAs('master');
        $league = $this->drawLeague();

        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('league.groups_shuffled', $log->action);
        $this->assertSame($user->id, $log->user_id);
        $this->assertLeagueContext($log, $league);
        $this->assertSame([['group_number' => null, 'team_ids' => $league->teams()->orderBy('teams.id')->pluck('teams.id')->all()]], $log->old_values['groups']);

        $current = $league->teams()->get()
            ->groupBy(fn (Team $team) => $team->pivot->group_number)
            ->map(fn ($teams, $group) => ['group_number' => $group, 'team_ids' => $teams->pluck('id')->sort()->values()->all()])
            ->sortKeys()
            ->values()
            ->all();
        $this->assertSame($current, $log->new_values['groups']);
    }
}
