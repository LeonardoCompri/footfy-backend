<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PruneAuditLogsTest extends TestCase
{
    use RefreshDatabase;

    private function logCreatedAt(Carbon $createdAt): AuditLog
    {
        $user = User::factory()->create();

        return AuditLog::query()->forceCreate([
            'action' => 'user.updated',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'created_at' => $createdAt,
        ]);
    }

    public function test_deletes_only_the_records_older_than_the_default_retention(): void
    {
        $this->freezeTime();
        $expired = $this->logCreatedAt(now()->subMonths(24)->subDay());
        $limit = $this->logCreatedAt(now()->subMonths(24)->addMinute());
        $recent = $this->logCreatedAt(now());

        $this->artisan('audit:prune')
            ->expectsOutput('1 registro(s) de auditoria removido(s).')
            ->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($limit);
        $this->assertModelExists($recent);
    }

    public function test_uses_the_configured_retention(): void
    {
        config(['audit.retention_months' => 6]);
        $expired = $this->logCreatedAt(now()->subMonths(7));
        $kept = $this->logCreatedAt(now()->subMonths(5));

        $this->artisan('audit:prune')->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($kept);
    }

    public function test_deletes_in_chunks_until_nothing_older_remains(): void
    {
        $old = now()->subMonths(30);
        $user = User::factory()->create();
        AuditLog::query()->insert(array_fill(0, 1001, [
            'action' => 'user.updated',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'created_at' => $old,
        ]));

        $this->artisan('audit:prune')
            ->expectsOutput('1001 registro(s) de auditoria removido(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_fails_without_deleting_when_the_retention_is_invalid(): void
    {
        config(['audit.retention_months' => 0]);
        $log = $this->logCreatedAt(now()->subYears(5));

        $this->artisan('audit:prune')
            ->expectsOutput('A retenção da auditoria precisa ser de pelo menos 1 mês.')
            ->assertFailed();

        $this->assertModelExists($log);
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains($event->command, 'audit:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('0 3 * * *', $events->first()->expression);
    }

    public function test_migration_can_be_rolled_back_and_applied_again(): void
    {
        $migration = require database_path('migrations/2026_11_17_000100_create_audit_logs_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('audit_logs'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertTrue(Schema::hasIndex('audit_logs', ['auditable_type', 'auditable_id']));
        $this->assertTrue(Schema::hasIndex('audit_logs', ['league_id', 'created_at']));
        $this->assertTrue(Schema::hasIndex('audit_logs', ['user_id', 'created_at']));
        $this->assertTrue(Schema::hasIndex('audit_logs', ['action', 'created_at']));
    }
}
