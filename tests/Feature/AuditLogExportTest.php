<?php

namespace Tests\Feature;

use App\Http\Controllers\AuditLogController;
use App\Http\Resources\AuditLogCsvRow;
use App\Models\AuditLog;
use App\Models\Championship;
use App\Models\Game;
use App\Models\League;
use App\Models\Player;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class AuditLogExportTest extends TestCase
{
    use AssertsServerErrorIsLogged, RefreshDatabase;

    private const BOM = "\xEF\xBB\xBF";

    private const HEADER = '"Data (horário de Brasília)";Usuário;Ação;Entidade;Campeonato;Liga;Antes;Depois;Meta;IP;"ID da requisição"';

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['name' => 'Master Exportador']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function export(string $query = ''): TestResponse
    {
        return $this->get('/api/audit-logs/export'.$query, ['Accept' => 'application/json']);
    }

    private function csvLines(TestResponse $response): array
    {
        $content = $response->assertOk()->streamedContent();
        $this->assertStringStartsWith(self::BOM, $content);

        return explode("\r\n", rtrim(substr($content, strlen(self::BOM)), "\r\n"));
    }

    private function rows(TestResponse $response): array
    {
        $lines = $this->csvLines($response);
        $this->assertSame(self::HEADER, $lines[0]);

        return array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), array_slice($lines, 1));
    }

    private function exportedRequestIds(string $query = ''): array
    {
        return array_map(fn (array $row) => $row[10], $this->rows($this->export($query)));
    }

    private function exportLog(): AuditLog
    {
        return AuditLog::query()->where('action', 'audit_logs.exported')->sole();
    }

    public function test_master_downloads_csv_with_bom_separator_header_and_formatted_row(): void
    {
        $this->travelTo('2026-11-18 22:30:00');
        $this->actingAsRole('master');
        $author = User::factory()->create(['name' => 'Fulano Auditor']);
        $championship = Championship::factory()->create(['name' => 'Brasileirão']);
        $league = League::factory()->create(['name' => 'Série A', 'championship_id' => $championship->id]);
        AuditLog::factory()->create([
            'user_id' => $author->id,
            'action' => 'league.updated',
            'auditable_type' => League::class,
            'auditable_id' => $league->id,
            'championship_id' => $championship->id,
            'league_id' => $league->id,
            'old_values' => ['name' => 'Antiga', 'url' => 'a/b'],
            'new_values' => ['name' => 'Série A'],
            'meta' => ['round' => 3],
            'ip' => '10.0.0.1',
            'user_agent' => 'FootfyTest/1.0',
            'request_id' => 'req-123',
            'created_at' => '2026-11-17 09:30:00',
        ]);

        $response = $this->export();

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename=auditoria-2026-11-18.csv');
        $lines = $this->csvLines($response);
        $this->assertSame([
            self::HEADER,
            '"17/11/2026 09:30:00";"Fulano Auditor";"Liga editada";"league #'.$league->id.'";Brasileirão;"Série A";'
                .'"{""name"":""Antiga"",""url"":""a/b""}";"{""name"":""Série A""}";"{""round"":3}";10.0.0.1;req-123',
        ], $lines);
        $this->assertStringNotContainsString('FootfyTest', $response->streamedContent());
    }

    public function test_date_is_written_in_sao_paulo_time(): void
    {
        $log = AuditLog::factory()->create();
        $log->setRawAttributes([...$log->getAttributes(), 'created_at' => CarbonImmutable::create(2026, 11, 17, 23, 15, 5, 'UTC')], true);
        $row = (new AuditLogCsvRow)->toArray($log);

        $this->assertSame('17/11/2026 20:15:05', $row[0]);
    }

    public function test_unknown_action_deleted_references_and_system_actions(): void
    {
        $this->actingAsRole('master');
        $author = User::factory()->create();
        $league = League::factory()->for(Championship::factory())->create();
        $championshipId = $league->championship_id;
        AuditLog::factory()->create([
            'user_id' => $author->id,
            'action' => 'legacy.something',
            'auditable_type' => 'App\\Models\\Legacy',
            'auditable_id' => 9,
            'league_id' => $league->id,
            'championship_id' => $championshipId,
            'old_values' => null,
            'new_values' => null,
            'request_id' => null,
            'ip' => null,
        ]);
        AuditLog::factory()->create(['user_id' => null, 'action' => 'draw.reset']);
        $authorId = $author->id;
        $author->delete();
        $league->delete();
        Championship::query()->whereKey($championshipId)->delete();

        $rows = $this->rows($this->export());

        $this->assertSame(['Sistema', 'Sorteio reiniciado'], array_slice($rows[0], 1, 2));
        $this->assertSame([
            "Usuário #{$authorId} (excluído)",
            'legacy.something',
            'App\\Models\\Legacy #9',
            "Campeonato #{$championshipId} (excluído)",
            "Liga #{$league->id} (excluída)",
            '',
            '',
            '',
            '',
            '',
        ], array_slice($rows[1], 1));
    }

    public function test_every_known_action_has_a_portuguese_label(): void
    {
        $labels = trans('audit.actions');

        $this->assertCount(35, $labels);
        $this->assertSame('Auditoria exportada', $labels['audit_logs.exported']);
        foreach ($labels as $action => $label) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $action);
            $this->assertNotSame($action, $label);
        }
    }

    public function test_cells_that_look_like_formulas_are_prefixed(): void
    {
        $this->actingAsRole('master');
        $author = User::factory()->create(['name' => '=HYPERLINK("http://x")']);
        $championship = Championship::factory()->create(['name' => '+SOMA(1)']);
        $league = League::factory()->create(['name' => '-1+2', 'championship_id' => $championship->id]);
        AuditLog::factory()->create([
            'user_id' => $author->id,
            'league_id' => $league->id,
            'championship_id' => $championship->id,
            'ip' => '@ip',
            'request_id' => "\tcmd",
        ]);
        AuditLog::factory()->create(['request_id' => "\rcmd", 'ip' => '10.0.0.1']);

        $rows = $this->rows($this->export());

        $this->assertSame("'\rcmd", $rows[0][10]);
        $this->assertSame('10.0.0.1', $rows[0][9]);
        $this->assertSame("'=HYPERLINK(\"http://x\")", $rows[1][1]);
        $this->assertSame("'+SOMA(1)", $rows[1][4]);
        $this->assertSame("'-1+2", $rows[1][5]);
        $this->assertSame("'@ip", $rows[1][9]);
        $this->assertSame("'\tcmd", $rows[1][10]);
    }

    public function test_cpf_and_secrets_stay_masked(): void
    {
        $this->actingAsRole('master');
        $player = Player::factory()->create(['document' => '12345678901']);
        app(AuditLogger::class)->record(
            'player.deleted',
            $player,
            old: ['document' => '12345678901', 'password' => 'segredo-forte'],
            meta: ['token' => 'tok-123', 'credit_card_number' => '4111111111111111'],
        );

        $content = $this->export()->assertOk()->streamedContent();

        $this->assertStringContainsString('***.***.***-01', $content);
        foreach (['12345678901', 'segredo-forte', 'tok-123', '4111111111111111'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    public function test_applies_the_same_filters_as_the_listing(): void
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
            'request_id' => 'esperado',
            'created_at' => '2026-11-10 12:00:00',
        ];
        AuditLog::factory()->create($base);
        AuditLog::factory()->create([...$base, 'request_id' => 'x1', 'user_id' => null]);
        AuditLog::factory()->create([...$base, 'request_id' => 'x2', 'action' => 'game.deleted']);
        AuditLog::factory()->create([...$base, 'request_id' => 'x3', 'league_id' => 8]);
        AuditLog::factory()->create([...$base, 'request_id' => 'x4', 'championship_id' => 4]);
        AuditLog::factory()->create([...$base, 'request_id' => 'x5', 'auditable_type' => Player::class]);
        AuditLog::factory()->create([...$base, 'request_id' => 'x6', 'auditable_id' => 6]);
        AuditLog::factory()->create([...$base, 'request_id' => 'x7', 'created_at' => '2026-11-11 00:00:00']);
        AuditLog::factory()->create([...$base, 'request_id' => 'x8', 'created_at' => '2026-11-09 23:59:59']);

        $query = '?'.http_build_query([
            'user_id' => $author->id,
            'action' => 'game.updated',
            'league_id' => 7,
            'championship_id' => 3,
            'auditable_type' => 'game',
            'auditable_id' => 5,
            'from' => '2026-11-10',
            'to' => '2026-11-10',
        ]);

        $this->assertSame(['esperado'], $this->exportedRequestIds($query));
        $this->assertSame(['esperado'], $this->getJson('/api/audit-logs'.$query)->json('data.*.request_id'));
    }

    public function test_rows_come_newest_first_across_chunks_and_exclude_the_export_log(): void
    {
        $this->actingAsRole('master');
        $logs = AuditLog::factory()->count(1203)->sequence(fn ($sequence) => ['request_id' => 'r'.($sequence->index + 1)])->create();

        $ids = $this->exportedRequestIds();

        $this->assertCount(1203, $ids);
        $this->assertSame('r1203', $ids[0]);
        $this->assertSame('r1', $ids[1202]);
        $this->assertSame($logs->sortByDesc('id')->pluck('request_id')->all(), $ids);
        $this->assertSame(1203, $this->exportLog()->meta['rows']);
    }

    public function test_records_one_export_log_with_filters_and_row_count(): void
    {
        $master = $this->actingAsRole('master');
        AuditLog::factory()->count(3)->create(['action' => 'game.updated', 'auditable_type' => Game::class, 'created_at' => '2026-11-10 12:00:00']);
        AuditLog::factory()->create(['action' => 'game.deleted', 'created_at' => '2026-11-10 12:00:00']);

        $this->export('?action=game.updated&from=2026-11-10&auditable_type=game&league_id=')->assertOk()->streamedContent();

        $log = $this->exportLog();
        $this->assertSame($master->id, $log->user_id);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($master->id, $log->auditable_id);
        $this->assertNull($log->league_id);
        $this->assertNull($log->championship_id);
        $this->assertNull($log->old_values);
        $this->assertNull($log->new_values);
        $this->assertEquals(
            ['filters' => ['from' => '2026-11-10', 'action' => 'game.updated', 'auditable_type' => 'game'], 'rows' => 3],
            $log->meta,
        );
    }

    public function test_export_log_is_listed_with_its_label(): void
    {
        $this->actingAsRole('master');
        $this->export()->assertOk()->streamedContent();

        $rows = $this->rows($this->export());

        $this->assertCount(1, $rows);
        $this->assertSame(['Master Exportador', 'Auditoria exportada'], array_slice($rows[0], 1, 2));
        $this->assertStringStartsWith('user #', $rows[0][3]);
    }

    public function test_rejects_export_above_the_limit_without_recording(): void
    {
        config(['audit.export_max_rows' => 2]);
        $this->actingAsRole('master');
        AuditLog::factory()->count(3)->create(['action' => 'game.updated']);
        AuditLog::factory()->create(['action' => 'game.deleted']);

        $this->export()
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'A busca tem 4 registros; o limite da exportação é 2. Refine os filtros.']);
        $this->assertSame(0, AuditLog::query()->where('action', 'audit_logs.exported')->count());

        $this->export('?action=game.updated')->assertUnprocessable();

        config(['audit.export_max_rows' => 3]);
        $this->assertCount(3, $this->exportedRequestIds('?action=game.updated'));
        $this->assertSame(3, $this->exportLog()->meta['rows']);
    }

    public function test_limit_message_uses_thousands_separator(): void
    {
        config(['audit.export_max_rows' => 1000]);
        $this->actingAsRole('master');
        AuditLog::factory()->count(1001)->create();

        $this->export()
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A busca tem 1.001 registros; o limite da exportação é 1.000. Refine os filtros.');
    }

    public function test_empty_result_exports_only_the_header(): void
    {
        $this->actingAsRole('master');

        $this->assertSame([], $this->rows($this->export()));
        $this->assertSame(0, $this->exportLog()->meta['rows']);
    }

    public function test_is_throttled_after_five_exports_per_minute(): void
    {
        $this->actingAsRole('master');

        foreach (range(1, 5) as $attempt) {
            $this->export()->assertOk()->streamedContent();
        }

        $this->export()->assertStatus(429);
        $this->assertSame(5, AuditLog::query()->where('action', 'audit_logs.exported')->count());
        $this->getJson('/api/audit-logs')->assertOk();
    }

    public function test_throttle_is_not_shared_with_the_league_deletion(): void
    {
        $this->actingAsRole('master');
        $league = League::factory()->for(Championship::factory())->create();

        foreach (range(1, 5) as $attempt) {
            $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'errada'])->assertUnprocessable();
        }

        $this->export()->assertOk();
        $this->deleteJson("/api/leagues/{$league->id}", ['password' => 'errada'])->assertStatus(429);
    }

    #[DataProvider('forbiddenRoles')]
    public function test_non_master_roles_are_forbidden(string $role): void
    {
        $this->actingAsRole($role);
        AuditLog::factory()->create();

        $this->export()->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('action', 'audit_logs.exported')->count());
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
        $this->export()->assertUnauthorized();
    }

    #[DataProvider('invalidQueries')]
    public function test_rejects_invalid_filters(string $query, string $field): void
    {
        $this->actingAsRole('master');

        $this->export('?'.$query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public static function invalidQueries(): array
    {
        return [
            'data inicial em outro formato' => ['from=10/11/2026', 'from'],
            'data final antes da inicial' => ['from=2026-11-10&to=2026-11-09', 'to'],
            'usuário como array' => ['user_id[]=1', 'user_id'],
            'liga não numérica' => ['league_id=1abc', 'league_id'],
            'entidade desconhecida' => ['auditable_type=foo', 'auditable_type'],
            'id da entidade sem o tipo' => ['auditable_id=5', 'auditable_type'],
        ];
    }

    public function test_ignores_pagination_parameters(): void
    {
        $this->actingAsRole('master');
        AuditLog::factory()->count(3)->create();

        $this->assertCount(3, $this->exportedRequestIds('?page=2&per_page=1'));
        $this->assertSame([], $this->exportLog()->meta['filters']);
    }

    public function test_cors_exposes_content_disposition(): void
    {
        $this->actingAsRole('master');

        $this->get('/api/audit-logs/export', ['Origin' => 'https://admin.example.com'])
            ->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', 'X-Request-Id, Content-Disposition');
    }

    public function test_export_logs_unexpected_error_and_returns_500(): void
    {
        $this->assertFailingRouteIsLogged(AuditLogController::class.'@export', 'GET', '/api/audit-logs/export', AuditLogService::class, 'export');
    }
}
