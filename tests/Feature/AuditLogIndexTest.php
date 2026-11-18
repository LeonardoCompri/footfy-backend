<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Championship;
use App\Models\Game;
use App\Models\League;
use App\Models\Player;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuditLogIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function ids(string $query = ''): array
    {
        return $this->getJson('/api/audit-logs'.$query)->assertOk()->json('data.*.id');
    }

    public function test_master_lists_paginated_with_default_per_page_newest_first(): void
    {
        $this->actingAsRole('master');
        foreach (range(1, 25) as $day) {
            AuditLog::factory()->create(['created_at' => sprintf('2026-10-%02d 10:00:00', $day)]);
        }

        $first = $this->getJson('/api/audit-logs');
        $first->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 20, 'total' => 25, 'last_page' => 2])
            ->assertJsonPath('data.0.id', 25)
            ->assertJsonPath('data.19.id', 6);

        $this->getJson('/api/audit-logs?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.4.id', 1);

        $this->getJson('/api/audit-logs?per_page=100')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_orders_by_created_at_then_id_descending(): void
    {
        $this->actingAsRole('master');
        $older = AuditLog::factory()->create(['created_at' => '2026-11-10 12:00:00']);
        $sameTimeFirst = AuditLog::factory()->create(['created_at' => '2026-11-11 12:00:00']);
        $sameTimeSecond = AuditLog::factory()->create(['created_at' => '2026-11-11 12:00:00']);
        $insertedLaterButOlder = AuditLog::factory()->create(['created_at' => '2026-11-09 12:00:00']);

        $this->assertSame(
            [$sameTimeSecond->id, $sameTimeFirst->id, $older->id, $insertedLaterButOlder->id],
            $this->ids(),
        );
    }

    public function test_item_format(): void
    {
        $this->actingAsRole('master');
        $author = User::factory()->create(['name' => 'Fulano Auditor']);
        $championship = Championship::factory()->create(['name' => 'Brasileirão']);
        $league = League::factory()->create(['name' => 'Série A', 'championship_id' => $championship->id]);
        $log = AuditLog::factory()->create([
            'user_id' => $author->id,
            'action' => 'league.updated',
            'auditable_type' => League::class,
            'auditable_id' => $league->id,
            'championship_id' => $championship->id,
            'league_id' => $league->id,
            'old_values' => ['name' => 'Antiga', 'document' => '***.***.***-12'],
            'new_values' => ['name' => 'Série A'],
            'meta' => ['round' => 3],
            'ip' => '10.0.0.1',
            'user_agent' => 'FootfyTest/1.0',
            'request_id' => 'req-123',
            'created_at' => '2026-11-17 09:30:00',
        ]);

        $this->getJson('/api/audit-logs')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $log->id,
                    'action' => 'league.updated',
                    'user' => ['id' => $author->id, 'name' => 'Fulano Auditor'],
                    'auditable' => ['type' => 'league', 'id' => $league->id],
                    'championship' => ['id' => $championship->id, 'name' => 'Brasileirão'],
                    'league' => ['id' => $league->id, 'name' => 'Série A'],
                    'old_values' => ['name' => 'Antiga', 'document' => '***.***.***-12'],
                    'new_values' => ['name' => 'Série A'],
                    'meta' => ['round' => 3],
                    'ip' => '10.0.0.1',
                    'user_agent' => 'FootfyTest/1.0',
                    'request_id' => 'req-123',
                    'created_at' => '2026-11-17T12:30:00.000000Z',
                ]],
                'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ]);
    }

    public function test_deleted_references_keep_the_id_and_system_actions_have_no_user(): void
    {
        $this->actingAsRole('master');
        $author = User::factory()->create();
        $league = League::factory()->for(Championship::factory())->create();
        $championshipId = $league->championship_id;
        AuditLog::factory()->create([
            'user_id' => $author->id,
            'league_id' => $league->id,
            'championship_id' => $championshipId,
            'auditable_id' => $league->id,
            'created_at' => '2026-11-10 10:00:00',
        ]);
        AuditLog::factory()->create(['user_id' => null, 'created_at' => '2026-11-09 10:00:00']);
        $authorId = $author->id;
        $author->delete();
        $league->delete();
        Championship::query()->whereKey($championshipId)->delete();

        $response = $this->getJson('/api/audit-logs')->assertOk();

        $response->assertJsonPath('data.0.user', ['id' => $authorId, 'name' => null])
            ->assertJsonPath('data.0.league', ['id' => $league->id, 'name' => null])
            ->assertJsonPath('data.0.championship', ['id' => $championshipId, 'name' => null])
            ->assertJsonPath('data.1.user', null)
            ->assertJsonPath('data.1.league', null)
            ->assertJsonPath('data.1.championship', null);
    }

    public function test_unmapped_auditable_type_is_returned_as_stored(): void
    {
        $this->actingAsRole('master');
        AuditLog::factory()->create(['auditable_type' => 'App\\Models\\Legacy']);

        $this->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.auditable.type', 'App\\Models\\Legacy');
    }

    public function test_filters_by_user(): void
    {
        $this->actingAsRole('master');
        $author = User::factory()->create();
        $other = User::factory()->create();
        $expected = AuditLog::factory()->create(['user_id' => $author->id]);
        AuditLog::factory()->create(['user_id' => $other->id]);
        AuditLog::factory()->create(['user_id' => null]);

        $this->assertSame([$expected->id], $this->ids("?user_id={$author->id}"));
    }

    public function test_filters_by_deleted_user(): void
    {
        $this->actingAsRole('master');
        $author = User::factory()->create();
        $expected = AuditLog::factory()->create(['user_id' => $author->id]);
        AuditLog::factory()->create();
        $authorId = $author->id;
        $author->delete();

        $this->assertSame([$expected->id], $this->ids("?user_id={$authorId}"));
    }

    public function test_filters_by_exact_action(): void
    {
        $this->actingAsRole('master');
        $expected = AuditLog::factory()->create(['action' => 'draw.round_undone']);
        AuditLog::factory()->create(['action' => 'draw.round_drawn']);
        AuditLog::factory()->create(['action' => 'draw.round_undone_extra']);

        $this->assertSame([$expected->id], $this->ids('?action=draw.round_undone'));
    }

    public function test_filters_by_league_and_by_championship(): void
    {
        $this->actingAsRole('master');
        $leagueLog = AuditLog::factory()->create(['league_id' => 7, 'championship_id' => 3]);
        $championshipLog = AuditLog::factory()->create(['league_id' => null, 'championship_id' => 3]);
        AuditLog::factory()->create(['league_id' => 8, 'championship_id' => 4]);

        $this->assertSame([$leagueLog->id], $this->ids('?league_id=7'));
        $this->assertSame([$championshipLog->id, $leagueLog->id], $this->ids('?championship_id=3'));
        $this->assertSame([], $this->ids('?league_id=999'));
    }

    public function test_filters_by_entity_alias_and_id(): void
    {
        $this->actingAsRole('master');
        $game = AuditLog::factory()->create(['auditable_type' => Game::class, 'auditable_id' => 5]);
        $otherGame = AuditLog::factory()->create(['auditable_type' => Game::class, 'auditable_id' => 6]);
        AuditLog::factory()->create(['auditable_type' => Player::class, 'auditable_id' => 5]);

        $this->assertSame([$otherGame->id, $game->id], $this->ids('?auditable_type=game'));
        $this->assertSame([$game->id], $this->ids('?auditable_type=game&auditable_id=5'));
    }

    public function test_period_is_inclusive_on_both_days(): void
    {
        $this->actingAsRole('master');
        AuditLog::factory()->create(['created_at' => '2026-11-09 23:59:59']);
        $start = AuditLog::factory()->create(['created_at' => '2026-11-10 00:00:00']);
        $end = AuditLog::factory()->create(['created_at' => '2026-11-12 23:59:59']);
        AuditLog::factory()->create(['created_at' => '2026-11-13 00:00:00']);

        $this->assertSame([$end->id, $start->id], $this->ids('?from=2026-11-10&to=2026-11-12'));
        $this->assertSame([$start->id], $this->ids('?from=2026-11-10&to=2026-11-10'));
        $this->assertCount(3, $this->ids('?from=2026-11-10'));
        $this->assertCount(3, $this->ids('?to=2026-11-12'));
    }

    public function test_combines_filters(): void
    {
        $this->actingAsRole('master');
        $author = User::factory()->create();
        $base = [
            'user_id' => $author->id,
            'action' => 'game.updated',
            'league_id' => 7,
            'championship_id' => 3,
            'auditable_type' => Game::class,
            'auditable_id' => 5,
            'created_at' => '2026-11-10 12:00:00',
        ];
        $expected = AuditLog::factory()->create($base);
        AuditLog::factory()->create([...$base, 'user_id' => null]);
        AuditLog::factory()->create([...$base, 'action' => 'game.deleted']);
        AuditLog::factory()->create([...$base, 'league_id' => 8]);
        AuditLog::factory()->create([...$base, 'championship_id' => 4]);
        AuditLog::factory()->create([...$base, 'auditable_type' => Player::class]);
        AuditLog::factory()->create([...$base, 'auditable_id' => 6]);
        AuditLog::factory()->create([...$base, 'created_at' => '2026-11-11 00:00:00']);

        $query = http_build_query([
            'user_id' => $author->id,
            'action' => 'game.updated',
            'league_id' => 7,
            'championship_id' => 3,
            'auditable_type' => 'game',
            'auditable_id' => 5,
            'from' => '2026-11-10',
            'to' => '2026-11-10',
        ]);

        $this->getJson('/api/audit-logs?'.$query)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $expected->id);
    }

    public function test_query_count_does_not_grow_with_the_number_of_logs(): void
    {
        $this->actingAsRole('master');
        $createLogs = function (int $count): void {
            foreach (range(1, $count) as $i) {
                $league = League::factory()->for(Championship::factory())->create();
                AuditLog::factory()->create([
                    'user_id' => User::factory()->create()->id,
                    'league_id' => $league->id,
                    'championship_id' => $league->championship_id,
                ]);
            }
        };
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $countQueries = function () use (&$count): int {
            $count = 0;
            $this->getJson('/api/audit-logs')->assertOk();

            return $count;
        };
        $this->getJson('/api/audit-logs')->assertOk();

        $createLogs(2);
        $withTwo = $countQueries();
        $createLogs(6);
        $withEight = $countQueries();

        $this->assertSame(5, $withTwo);
        $this->assertSame($withTwo, $withEight);
    }

    #[DataProvider('forbiddenRoles')]
    public function test_non_master_roles_are_forbidden(string $role): void
    {
        $this->actingAsRole($role);
        AuditLog::factory()->create();

        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    public static function forbiddenRoles(): array
    {
        return [
            'admin' => ['admin'],
            'coach' => ['coach'],
            'player' => ['player'],
        ];
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/audit-logs')->assertUnauthorized();
    }

    #[DataProvider('invalidQueries')]
    public function test_rejects_invalid_filters(string $query, string $field): void
    {
        $this->actingAsRole('master');

        $this->getJson('/api/audit-logs?'.$query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    public static function invalidQueries(): array
    {
        return [
            'per_page acima do máximo' => ['per_page=101', 'per_page'],
            'página zero' => ['page=0', 'page'],
            'data inicial em outro formato' => ['from=10/11/2026', 'from'],
            'data inicial inexistente' => ['from=2026-02-30', 'from'],
            'data final inválida' => ['to=amanha', 'to'],
            'data final antes da inicial' => ['from=2026-11-10&to=2026-11-09', 'to'],
            'usuário como array' => ['user_id[]=1', 'user_id'],
            'liga não numérica' => ['league_id=1abc', 'league_id'],
            'campeonato zero' => ['championship_id=0', 'championship_id'],
            'ação longa' => ['action='.str_repeat('a', 101), 'action'],
            'entidade com nome da classe' => ['auditable_type=App%5CModels%5CLeague', 'auditable_type'],
            'entidade desconhecida' => ['auditable_type=foo', 'auditable_type'],
            'id da entidade sem o tipo' => ['auditable_id=5', 'auditable_type'],
        ];
    }

    public function test_validation_messages_are_in_portuguese(): void
    {
        $this->actingAsRole('master');

        $this->getJson('/api/audit-logs?from=2026-11-10&to=2026-11-09')
            ->assertUnprocessable()
            ->assertJsonPath('errors.to.0', 'O campo data final deve ser uma data posterior ou igual a data inicial.');
    }

    public function test_listing_indexes_migration_is_reversible(): void
    {
        $migration = require database_path('migrations/2026_11_17_000200_add_listing_indexes_to_audit_logs_table.php');

        $this->assertTrue(Schema::hasIndex('audit_logs', ['created_at']));
        $this->assertTrue(Schema::hasIndex('audit_logs', ['championship_id', 'created_at']));

        $migration->down();
        $this->assertFalse(Schema::hasIndex('audit_logs', ['created_at']));
        $this->assertFalse(Schema::hasIndex('audit_logs', ['championship_id', 'created_at']));

        $migration->up();
        $this->assertTrue(Schema::hasIndex('audit_logs', ['created_at']));
        $this->assertTrue(Schema::hasIndex('audit_logs', ['championship_id', 'created_at']));
    }
}
