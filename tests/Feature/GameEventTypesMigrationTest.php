<?php

namespace Tests\Feature;

use App\Models\GameEvent;
use App\Models\GameEventType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GameEventTypesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function foreignKeyMigration(): object
    {
        return require database_path('migrations/2026_11_05_000001_add_event_type_foreign_key_to_game_events_table.php');
    }

    public function test_migration_inserts_the_four_default_types_in_order(): void
    {
        $types = GameEventType::orderBy('sort_order')->get()
            ->map(fn (GameEventType $type) => $type->only(['slug', 'name', 'icon', 'color', 'counts_for', 'allows_assist', 'card', 'sort_order', 'active']))
            ->all();

        $this->assertSame([
            ['slug' => 'goal', 'name' => 'Gol', 'icon' => 'mdi-soccer', 'color' => 'primary', 'counts_for' => 'team', 'allows_assist' => true, 'card' => null, 'sort_order' => 1, 'active' => true],
            ['slug' => 'own_goal', 'name' => 'Gol contra', 'icon' => 'mdi-soccer', 'color' => 'error', 'counts_for' => 'opponent', 'allows_assist' => false, 'card' => null, 'sort_order' => 2, 'active' => true],
            ['slug' => 'yellow_card', 'name' => 'Cartão amarelo', 'icon' => 'mdi-card', 'color' => 'card-yellow', 'counts_for' => null, 'allows_assist' => false, 'card' => 'yellow', 'sort_order' => 3, 'active' => true],
            ['slug' => 'red_card', 'name' => 'Cartão vermelho', 'icon' => 'mdi-card', 'color' => 'card-red', 'counts_for' => null, 'allows_assist' => false, 'card' => 'red', 'sort_order' => 4, 'active' => true],
        ], $types);
    }

    public function test_legacy_event_type_becomes_inactive_neutral_type(): void
    {
        $this->foreignKeyMigration()->down();
        $legacy = GameEvent::factory()->create(['type' => 'penalty_miss']);
        GameEvent::factory()->create(['type' => 'penalty_miss']);
        GameEvent::factory()->create(['type' => 'goal']);

        $this->foreignKeyMigration()->up();

        $this->assertSame(5, GameEventType::count());
        $type = GameEventType::where('slug', 'penalty_miss')->firstOrFail();
        $this->assertSame('penalty_miss', $type->name);
        $this->assertFalse($type->active);
        $this->assertNull($type->counts_for);
        $this->assertNull($type->card);
        $this->assertFalse($type->allows_assist);
        $this->assertSame(5, $type->sort_order);
        $this->assertSame('penalty_miss', $legacy->fresh()->eventType->slug);
    }

    public function test_up_again_does_not_duplicate_legacy_types(): void
    {
        $this->foreignKeyMigration()->down();
        GameEvent::factory()->create(['type' => 'penalty_miss']);
        $this->foreignKeyMigration()->up();
        $this->foreignKeyMigration()->down();

        $this->foreignKeyMigration()->up();

        $this->assertSame(1, GameEventType::where('slug', 'penalty_miss')->count());
    }

    public function test_foreign_key_rejects_unknown_type(): void
    {
        $this->expectException(QueryException::class);

        GameEvent::factory()->create(['type' => 'inexistente']);
    }

    public function test_foreign_key_restricts_deleting_type_in_use(): void
    {
        GameEvent::factory()->create(['type' => 'goal']);

        $this->expectException(QueryException::class);

        DB::table('game_event_types')->where('slug', 'goal')->delete();
    }
}
