<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Championship;
use App\Models\League;
use App\Models\Stadium;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Fixtures\AuditableLeague;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    private function logger(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    public function test_records_user_ip_user_agent_and_request_id_of_the_request(): void
    {
        $user = User::factory()->create();
        $stadium = Stadium::factory()->create();
        $token = $user->createToken('api-token')->plainTextToken;
        Route::middleware('auth:sanctum')->post('/api/audit-probe', function () use ($stadium) {
            app(AuditLogger::class)->record('stadium.updated', $stadium, ['name' => 'Antigo'], ['name' => 'Novo']);

            return response()->noContent();
        });

        $this->withToken($token)
            ->withHeaders(['X-Request-Id' => 'req-audit-123', 'User-Agent' => 'FootfyTest/1.0'])
            ->post('/api/audit-probe')
            ->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('stadium.updated', $log->action);
        $this->assertSame(Stadium::class, $log->auditable_type);
        $this->assertSame($stadium->id, $log->auditable_id);
        $this->assertSame(['name' => 'Antigo'], $log->old_values);
        $this->assertSame(['name' => 'Novo'], $log->new_values);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertSame('FootfyTest/1.0', $log->user_agent);
        $this->assertSame('req-audit-123', $log->request_id);
        $this->assertNotNull($log->created_at);
    }

    public function test_records_sensitive_values_masked(): void
    {
        $user = User::factory()->create();

        $this->logger()->record(
            'user.updated',
            $user,
            ['password' => 'hash-antigo-secreto', 'document' => '12345678912'],
            ['password' => 'hash-novo-secreto', 'document' => '98765432134', 'name' => 'Fulano'],
            ['request' => ['token' => 'token-secreto', 'api_key' => 'chave-secreta', 'card' => 'yellow']],
        );

        $row = DB::table('audit_logs')->sole();
        $stored = $row->old_values.$row->new_values.$row->meta;
        foreach (['hash-antigo-secreto', 'hash-novo-secreto', '12345678912', '98765432134', 'token-secreto', 'chave-secreta'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }

        $log = AuditLog::query()->sole();
        $this->assertSame(['password' => '***', 'document' => '***.***.***-12'], $log->old_values);
        $this->assertSame(['password' => '***', 'document' => '***.***.***-34', 'name' => 'Fulano'], $log->new_values);
        $this->assertSame(['request' => ['token' => '***', 'api_key' => '***', 'card' => 'yellow']], $log->meta);
    }

    public function test_rollback_of_the_current_transaction_discards_the_record(): void
    {
        $stadium = Stadium::factory()->create();

        try {
            DB::transaction(function () use ($stadium) {
                $this->logger()->record('stadium.deleted', $stadium);

                throw new RuntimeException('falha na operação');
            });
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_commit_of_the_current_transaction_keeps_the_record(): void
    {
        $stadium = Stadium::factory()->create();

        DB::transaction(fn () => $this->logger()->record('stadium.deleted', $stadium));

        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_resolves_league_and_championship_from_the_league_id_of_the_subject(): void
    {
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id]);
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);

        $log = $this->logger()->record('stadium.deleted', $stadium);

        $this->assertSame($league->id, $log->league_id);
        $this->assertSame($championship->id, $log->championship_id);
    }

    public function test_uses_the_league_or_the_championship_that_is_the_subject(): void
    {
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id]);

        $leagueLog = $this->logger()->record('league.updated', $league);
        $championshipLog = $this->logger()->record('championship.updated', $championship);

        $this->assertSame([$league->id, $championship->id], [$leagueLog->league_id, $leagueLog->championship_id]);
        $this->assertSame([null, $championship->id], [$championshipLog->league_id, $championshipLog->championship_id]);
    }

    public function test_uses_the_league_informed_for_a_subject_without_league(): void
    {
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id]);
        $user = User::factory()->create();

        $log = $this->logger()->record('user.league_linked', $user, league: $league);

        $this->assertSame($league->id, $log->league_id);
        $this->assertSame($championship->id, $log->championship_id);
    }

    public function test_records_without_user_context_and_with_empty_values_as_null(): void
    {
        Context::forget('request_id');
        $user = User::factory()->create();

        $log = $this->logger()->record('user.deleted', $user);

        $row = DB::table('audit_logs')->sole();
        $this->assertNull($log->user_id);
        $this->assertNull($log->request_id);
        $this->assertNull($log->league_id);
        $this->assertNull($log->championship_id);
        $this->assertNull($row->old_values);
        $this->assertNull($row->new_values);
        $this->assertNull($row->meta);
    }

    public function test_auditable_trait_records_only_the_changed_attributes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $league = AuditableLeague::query()->find(League::factory()->create([
            'name' => 'Série A',
            'max_players_per_team' => 30,
        ])->id);

        $league->fill(['name' => 'Série A 2026', 'max_players_per_team' => 30, 'tiebreakers' => ['points', 'wins']]);
        $log = $league->recordAudit('league.updated', meta: ['origem' => 'teste']);

        $this->assertSame('league.updated', $log->action);
        $this->assertSame(AuditableLeague::class, $log->auditable_type);
        $this->assertSame($league->id, $log->auditable_id);
        $this->assertSame($league->id, $log->league_id);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(['name' => 'Série A', 'tiebreakers' => null], $log->old_values);
        $this->assertSame(['name' => 'Série A 2026', 'tiebreakers' => ['points', 'wins']], $log->new_values);
        $this->assertSame(['origem' => 'teste'], $log->meta);
    }

    public function test_auditable_changes_ignore_timestamps_and_have_no_old_values_for_a_new_model(): void
    {
        $league = new AuditableLeague(['name' => 'Copa Nova', 'organizer' => 'Liga']);
        $league->created_at = now();
        $league->updated_at = now();

        $this->assertSame(['old' => [], 'new' => ['name' => 'Copa Nova', 'organizer' => 'Liga']], $league->auditChanges());
    }

    public function test_auditable_changes_can_be_captured_before_saving_and_recorded_after(): void
    {
        $league = AuditableLeague::query()->find(League::factory()->create(['name' => 'Série B'])->id);

        $changes = $league->fill(['name' => 'Série B 2026'])->auditChanges();
        $league->save();
        $log = $league->recordAudit('league.updated', $changes);

        $this->assertSame(['name' => 'Série B'], $log->old_values);
        $this->assertSame(['name' => 'Série B 2026'], $log->new_values);
    }
}
