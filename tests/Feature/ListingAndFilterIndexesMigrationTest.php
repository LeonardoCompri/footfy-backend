<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ListingAndFilterIndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const INDEXES = [
        ['game_events', ['minute']],
        ['game_events', ['league_id', 'minute']],
        ['games', ['league_id', 'status']],
        ['games', ['league_id', 'knockout_stage']],
        ['players', ['name']],
        ['users', ['name']],
    ];

    private function migration(): object
    {
        return require database_path('migrations/2026_11_17_000000_add_listing_and_filter_indexes.php');
    }

    public function test_migration_creates_the_indexes(): void
    {
        foreach (self::INDEXES as [$table, $columns]) {
            $this->assertTrue(Schema::hasIndex($table, $columns), "Falta o índice {$table}(".implode(', ', $columns).').');
        }
    }

    public function test_rollback_removes_the_indexes_and_keeps_the_others(): void
    {
        $this->migration()->down();

        foreach (self::INDEXES as [$table, $columns]) {
            $this->assertFalse(Schema::hasIndex($table, $columns), "O índice {$table}(".implode(', ', $columns).') não foi removido.');
        }
        $this->assertTrue(Schema::hasIndex('game_events', ['league_id', 'type']));
        $this->assertTrue(Schema::hasIndex('games', ['league_id', 'round', 'phase_order', 'match_date']));

        $this->migration()->up();

        $this->assertTrue(Schema::hasIndex('games', ['league_id', 'knockout_stage']));
    }
}
