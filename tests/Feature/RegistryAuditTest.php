<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Championship;
use App\Models\GameEventType;
use App\Models\League;
use App\Models\Player;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\TechnicalCommitteeMember;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class RegistryAuditTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

    private const CPF = '12345678901';

    private const OTHER_CPF = '98765432155';

    private League $league;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->league = $this->league();
        $this->team = Team::factory()->create();
        $this->league->teams()->attach($this->team->id);
    }

    private function league(): League
    {
        return League::factory()->create(['championship_id' => Championship::factory()->create()->id]);
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

    private function failOnAudit(string $action): void
    {
        AuditLog::created(function (AuditLog $log) use ($action) {
            if ($log->action === $action) {
                throw new RuntimeException('falha simulada');
            }
        });
    }

    private function rawLogs(): string
    {
        return DB::table('audit_logs')->get()->map(fn ($row) => implode('|', (array) $row))->implode("\n");
    }

    private function assertLog(AuditLog $log, string $action, User $user, $subject, ?League $league): void
    {
        $this->assertSame($action, $log->action);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($subject::class, $log->auditable_type);
        $this->assertSame($subject->id, $log->auditable_id);
        $this->assertSame($league?->id, $log->league_id);
        $this->assertSame($league?->championship_id, $log->championship_id);
    }

    public function test_deleting_a_player_records_the_snapshot_with_masked_cpf_and_team_league(): void
    {
        $user = $this->authenticateAs('admin', [$this->league->id]);
        $player = Player::factory()->create(['team_id' => $this->team->id, 'document' => self::CPF]);

        $this->deleteJson("/api/players/{$player->id}")->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'player.deleted', $user, $player, $this->league);
        $this->assertSame('***.***.***-01', $log->old_values['document']);
        $this->assertNull($log->old_values['deleted_at']);
        $this->assertSame(['team_id' => $this->team->id], $log->meta);
        $this->assertStringNotContainsString(self::CPF, $this->rawLogs());
    }

    public function test_player_of_a_team_in_several_leagues_has_no_league_context(): void
    {
        $other = $this->league();
        $this->team->leagues()->attach($other->id);
        $user = $this->authenticateAs('master');
        $player = Player::factory()->create(['team_id' => $this->team->id]);

        $this->deleteJson("/api/players/{$player->id}")->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'player.deleted', $user, $player, null);
        $this->assertSame(['team_id' => $this->team->id], $log->meta);
    }

    public function test_failure_while_auditing_the_player_deletion_keeps_the_player(): void
    {
        $this->authenticateAs('master');
        $player = Player::factory()->create(['team_id' => $this->team->id]);
        $this->failOnAudit('player.deleted');

        $this->deleteJson("/api/players/{$player->id}")->assertStatus(500);

        $this->assertNull($player->fresh()->deleted_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_restoring_a_player_by_cpf_records_one_log(): void
    {
        $user = $this->authenticateAs('master');
        $player = Player::factory()->create(['team_id' => $this->team->id, 'document' => self::CPF, 'name' => 'Antigo', 'number' => 9]);
        $player->delete();

        $this->postJson('/api/players', ['team_id' => $this->team->id, 'name' => 'Novo', 'document' => self::CPF])
            ->assertCreated()
            ->assertJsonPath('data.id', $player->id);

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'player.restored', $user, $player, $this->league);
        $this->assertSame('Antigo', $log->old_values['name']);
        $this->assertSame(9, $log->old_values['number']);
        $this->assertSame('Novo', $log->new_values['name']);
        $this->assertNull($log->new_values['number']);
        $this->assertStringNotContainsString(self::CPF, $this->rawLogs());
    }

    public function test_transferring_a_player_and_changing_cpf_records_one_log(): void
    {
        $user = $this->authenticateAs('master');
        $newTeam = Team::factory()->create();
        $player = Player::factory()->create(['team_id' => $this->team->id, 'document' => self::CPF]);

        $this->putJson("/api/players/{$player->id}", ['team_id' => $newTeam->id, 'document' => self::OTHER_CPF])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'player.updated', $user, $player, null);
        $this->assertSame(['team_id' => $this->team->id, 'document' => '***.***.***-01'], $log->old_values);
        $this->assertSame(['team_id' => $newTeam->id, 'document' => '***.***.***-55'], $log->new_values);
        $this->assertSame(['team_id' => $newTeam->id], $log->meta);
        $this->assertStringNotContainsString(self::CPF, $this->rawLogs());
        $this->assertStringNotContainsString(self::OTHER_CPF, $this->rawLogs());

        $this->putJson("/api/players/{$player->id}", ['team_id' => $newTeam->id])->assertOk();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_player_editing_his_own_profile_records_one_log(): void
    {
        $user = User::factory()->create();
        $user->assignRole('player');
        $player = Player::factory()->create(['team_id' => $this->team->id, 'user_id' => $user->id, 'name' => 'Antigo']);
        Sanctum::actingAs($user);

        $this->putJson('/api/me/player', ['name' => 'Novo'])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'player.updated', $user, $player, $this->league);
        $this->assertSame(['name' => 'Antigo'], $log->old_values);
        $this->assertSame(['name' => 'Novo'], $log->new_values);
    }

    public function test_deleting_a_committee_member_records_one_log(): void
    {
        $user = $this->authenticateAs('admin', [$this->league->id]);
        $member = TechnicalCommitteeMember::factory()->create(['team_id' => $this->team->id, 'name' => 'Tite']);

        $this->deleteJson("/api/technical-committee-members/{$member->id}")->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'technical_committee_member.deleted', $user, $member, $this->league);
        $this->assertSame('Tite', $log->old_values['name']);
        $this->assertSame(['team_id' => $this->team->id], $log->meta);
    }

    public function test_deleting_a_stadium_records_one_log_with_its_league(): void
    {
        $user = $this->authenticateAs('admin', [$this->league->id]);
        $stadium = Stadium::factory()->create(['league_id' => $this->league->id, 'name' => 'Arena']);

        $this->deleteJson("/api/stadiums/{$stadium->id}")->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'stadium.deleted', $user, $stadium, $this->league);
        $this->assertSame('Arena', $log->old_values['name']);
    }

    public function test_failure_while_auditing_the_stadium_deletion_keeps_it(): void
    {
        $this->authenticateAs('master');
        $stadium = Stadium::factory()->create(['league_id' => $this->league->id]);
        $this->failOnAudit('stadium.deleted');

        $this->deleteJson("/api/stadiums/{$stadium->id}")->assertStatus(500);

        $this->assertDatabaseHas('stadiums', ['id' => $stadium->id]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_event_type_lifecycle_records_one_log_per_action(): void
    {
        $user = $this->authenticateAs('master');

        $id = $this->postJson('/api/event-types', [
            'slug' => 'penalty_goal',
            'name' => 'Gol de pênalti',
            'icon' => 'mdi-soccer',
            'color' => 'success',
            'counts_for' => 'team',
        ])->assertCreated()->json('data.id');
        $type = GameEventType::find($id);

        $created = AuditLog::query()->sole();
        $this->assertLog($created, 'game_event_type.created', $user, $type, null);
        $this->assertSame('team', $created->new_values['counts_for']);
        $this->assertFalse($created->new_values['allows_assist']);

        $this->putJson("/api/event-types/{$id}", ['counts_for' => null, 'card' => 'yellow'])->assertOk();
        $updated = AuditLog::query()->latest('id')->first();
        $this->assertLog($updated, 'game_event_type.updated', $user, $type, null);
        $this->assertSame(['counts_for' => 'team', 'card' => null], $updated->old_values);
        $this->assertSame(['counts_for' => null, 'card' => 'yellow'], $updated->new_values);

        $this->putJson("/api/event-types/{$id}", ['card' => 'yellow'])->assertOk();
        $this->assertDatabaseCount('audit_logs', 2);

        $this->deleteJson("/api/event-types/{$id}")->assertNoContent();
        $deleted = AuditLog::query()->latest('id')->first();
        $this->assertLog($deleted, 'game_event_type.deleted', $user, $type, null);
        $this->assertSame('penalty_goal', $deleted->old_values['slug']);
        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_failure_while_auditing_the_event_type_creation_rolls_it_back(): void
    {
        $this->authenticateAs('master');
        $this->failOnAudit('game_event_type.created');

        $this->postJson('/api/event-types', ['slug' => 'x', 'name' => 'X', 'icon' => 'mdi-x', 'color' => 'grey'])->assertStatus(500);

        $this->assertDatabaseMissing('game_event_types', ['slug' => 'x']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_creating_a_user_records_one_log_without_password(): void
    {
        $master = $this->authenticateAs('master');

        $id = $this->postJson('/api/users', [
            'name' => 'Técnico',
            'email' => 'tecnico@example.com',
            'document' => self::CPF,
            'password' => 'segredo123',
            'role' => 'coach',
            'team_id' => $this->team->id,
        ])->assertCreated()->json('data.id');

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'user.created', $master, User::find($id), null);
        $this->assertSame([
            'name' => 'Técnico',
            'email' => 'tecnico@example.com',
            'document' => '***.***.***-01',
            'team_id' => $this->team->id,
            'role' => 'coach',
            'player_id' => null,
            'league_ids' => [],
        ], $log->new_values);
        $this->assertNoSecretsIn($this->rawLogs(), User::find($id));
    }

    public function test_failure_while_auditing_the_user_creation_rolls_it_back(): void
    {
        $this->authenticateAs('master');
        $this->failOnAudit('user.created');

        $this->postJson('/api/users', [
            'name' => 'Técnico',
            'document' => self::CPF,
            'password' => 'segredo123',
            'role' => 'admin',
        ])->assertStatus(500);

        $this->assertDatabaseMissing('users', ['document' => self::CPF]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_changing_role_and_player_link_records_one_log_with_side_effects(): void
    {
        $master = $this->authenticateAs('master');
        $target = User::factory()->create(['document' => self::CPF]);
        $target->assignRole('admin');
        $target->leagues()->attach($this->league->id);
        $player = Player::factory()->create(['team_id' => $this->team->id]);

        $this->putJson("/api/users/{$target->id}", ['role' => 'player', 'player_id' => $player->id])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'user.updated', $master, $target, null);
        $this->assertSame(['role' => 'admin', 'player_id' => null, 'league_ids' => [$this->league->id]], $log->old_values);
        $this->assertSame(['role' => 'player', 'player_id' => $player->id, 'league_ids' => []], $log->new_values);
        $this->assertNull($log->meta);

        $this->putJson("/api/users/{$target->id}", ['name' => $target->name])->assertOk();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_password_change_records_only_the_fact(): void
    {
        $master = $this->authenticateAs('master');
        $target = User::factory()->create();
        $target->assignRole('admin');

        $this->putJson("/api/users/{$target->id}", ['password' => 'novaSenha123'])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'user.updated', $master, $target, null);
        $this->assertNull($log->old_values);
        $this->assertNull($log->new_values);
        $this->assertSame(['password_changed' => true], $log->meta);
        $this->assertNoSecretsIn($this->rawLogs(), $target->fresh(), 'novaSenha123');
    }

    public function test_profile_update_records_one_log_for_the_user_himself(): void
    {
        $user = $this->authenticateAs('admin', [$this->league->id]);

        $this->putJson('/api/me', [
            'name' => 'Meu nome',
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
            'role' => 'master',
        ])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'user.updated', $user, $user, null);
        $this->assertSame(['name' => 'Meu nome'], $log->new_values);
        $this->assertSame(['password_changed' => true], $log->meta);
        $this->assertNoSecretsIn($this->rawLogs(), $user->fresh(), 'novaSenha123');
    }

    public function test_linking_and_unlinking_leagues_records_one_log_each(): void
    {
        $master = $this->authenticateAs('master');
        $other = $this->league();
        $target = User::factory()->create();
        $target->assignRole('admin');
        $target->leagues()->attach($this->league->id);

        $this->postJson("/api/users/{$target->id}/leagues", ['league_ids' => [$this->league->id, $other->id]])->assertOk();

        $attached = AuditLog::query()->sole();
        $this->assertLog($attached, 'user.leagues_attached', $master, $target, $other);
        $this->assertSame(['league_ids' => [$other->id]], $attached->meta);

        $this->postJson("/api/users/{$target->id}/leagues", ['league_ids' => [$other->id]])->assertOk();
        $this->assertDatabaseCount('audit_logs', 1);

        $this->deleteJson("/api/users/{$target->id}/leagues/{$this->league->id}")->assertOk();
        $detached = AuditLog::query()->latest('id')->first();
        $this->assertLog($detached, 'user.leagues_detached', $master, $target, $this->league);
        $this->assertSame(['league_ids' => [$this->league->id]], $detached->meta);

        $this->deleteJson("/api/users/{$target->id}/leagues/{$this->league->id}")->assertOk();
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_linking_several_leagues_at_once_has_no_league_context(): void
    {
        $master = $this->authenticateAs('master');
        $other = $this->league();
        $target = User::factory()->create();
        $target->assignRole('admin');

        $this->postJson("/api/users/{$target->id}/leagues", ['league_ids' => [$this->league->id, $other->id]])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'user.leagues_attached', $master, $target, null);
        $this->assertSame(['league_ids' => [$this->league->id, $other->id]], $log->meta);
    }

    public function test_deleting_a_user_records_the_snapshot_without_secrets(): void
    {
        $master = $this->authenticateAs('master');
        $target = User::factory()->create(['document' => self::CPF, 'team_id' => $this->team->id]);
        $target->assignRole('coach');

        $this->deleteJson("/api/users/{$target->id}")->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertLog($log, 'user.deleted', $master, $target, null);
        $this->assertSame('coach', $log->old_values['role']);
        $this->assertSame($this->team->id, $log->old_values['team_id']);
        $this->assertSame('***.***.***-01', $log->old_values['document']);
        $this->assertNoSecretsIn($this->rawLogs(), $target);
    }

    public function test_failure_while_auditing_the_user_deletion_keeps_the_user(): void
    {
        $this->authenticateAs('master');
        $target = User::factory()->create();
        $target->assignRole('admin');
        $this->failOnAudit('user.deleted');

        $this->deleteJson("/api/users/{$target->id}")->assertStatus(500);

        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function assertNoSecretsIn(string $raw, User $user, ?string $plainPassword = null): void
    {
        $hash = $user->getAttributes()['password'];

        $this->assertStringNotContainsString($user->document, $raw);
        $this->assertStringNotContainsString('"password"', $raw);
        $this->assertStringNotContainsString($hash, $raw);
        $this->assertStringNotContainsString(str_replace('/', '\\/', $hash), $raw);
        $this->assertStringNotContainsString('remember_token', $raw);

        if ($user->remember_token !== null) {
            $this->assertStringNotContainsString($user->remember_token, $raw);
        }

        if ($plainPassword !== null) {
            $this->assertStringNotContainsString($plainPassword, $raw);
        }
    }
}
