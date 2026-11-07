<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\Game;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManualLeagueSingleGroupTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Ligas com tabela manual têm um único grupo.';

    private const HYBRID_DRAW = [
        'format' => 'hybrid',
        'fixture_mode' => 'draw',
        'groups_count' => 2,
        'group_legs' => 1,
        'qualifiers_per_group' => 2,
        'knockout_size' => 4,
        'knockout_legs' => 1,
        'final_legs' => 1,
        'knockout_tiebreakers' => ['penalties'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $user = User::factory()->create();
        $user->assignRole('master');
        Sanctum::actingAs($user);
    }

    private function leagueWithGroups(array $attributes = []): League
    {
        $league = League::factory()->create([...self::HYBRID_DRAW, ...$attributes]);

        foreach (Team::factory()->count(4)->create() as $index => $team) {
            $league->teams()->attach($team->id, ['group_number' => $index % 2 + 1]);
        }

        return $league;
    }

    private function groupNumbers(League $league): array
    {
        return DB::table('league_team')->where('league_id', $league->id)->pluck('group_number')->all();
    }

    public function test_store_rejects_manual_league_with_several_groups(): void
    {
        $championship = Championship::factory()->create();

        $this->postJson('/api/leagues', [
            'name' => 'Série A',
            'championship_id' => $championship->id,
            'format' => 'hybrid',
            'groups_count' => 2,
        ])->assertStatus(422)->assertJsonPath('errors.groups_count.0', self::MESSAGE);

        $this->postJson('/api/leagues', [
            'name' => 'Série A',
            'championship_id' => $championship->id,
            'format' => 'hybrid',
            'fixture_mode' => 'manual',
            'groups_count' => 1,
        ])->assertCreated();
    }

    public function test_store_accepts_several_groups_with_draw(): void
    {
        $championship = Championship::factory()->create();

        $this->postJson('/api/leagues', [
            'name' => 'Série A',
            'championship_id' => $championship->id,
            ...self::HYBRID_DRAW,
        ])->assertCreated()->assertJsonPath('data.groups_count', 2);
    }

    public function test_update_rejects_several_groups_in_manual_mode(): void
    {
        $league = League::factory()->create(['format' => 'hybrid']);

        $this->putJson("/api/leagues/{$league->id}", ['groups_count' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.groups_count.0', self::MESSAGE);

        $this->putJson("/api/leagues/{$league->id}", ['fixture_mode' => 'manual', 'groups_count' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.groups_count.0', self::MESSAGE);

        $this->assertSame(1, $league->refresh()->groups_count);
    }

    public function test_switching_to_manual_without_groups_count_resets_groups(): void
    {
        $league = $this->leagueWithGroups();

        $this->putJson("/api/leagues/{$league->id}", ['fixture_mode' => 'manual'])
            ->assertOk()
            ->assertJsonPath('data.fixture_mode', 'manual')
            ->assertJsonPath('data.groups_count', 1)
            ->assertJsonPath('data.qualifiers_per_group', 4)
            ->assertJsonPath('data.knockout_size', 4);

        $this->assertSame([null, null, null, null], $this->groupNumbers($league));
    }

    public function test_switching_to_manual_with_several_groups_is_rejected(): void
    {
        $league = $this->leagueWithGroups();

        $this->putJson("/api/leagues/{$league->id}", ['fixture_mode' => 'manual', 'groups_count' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.groups_count.0', self::MESSAGE);

        $this->assertSame('draw', $league->refresh()->fixture_mode);
        $this->assertSame([1, 2, 1, 2], array_map('intval', $this->groupNumbers($league)));
    }

    public function test_switching_single_group_draw_league_to_manual_clears_the_group(): void
    {
        $league = League::factory()->create(['format' => 'round_robin', 'fixture_mode' => 'draw']);
        $league->teams()->attach(Team::factory()->count(2)->create()->pluck('id'), ['group_number' => 1]);

        $this->putJson("/api/leagues/{$league->id}", ['fixture_mode' => 'manual'])->assertOk();

        $this->assertSame([null, null], $this->groupNumbers($league));
    }

    public function test_switching_to_manual_keeps_the_lock_when_rounds_are_drawn(): void
    {
        $league = $this->leagueWithGroups();
        Game::factory()->create(['league_id' => $league->id, 'round' => 1, 'group_number' => 1]);

        $this->putJson("/api/leagues/{$league->id}", ['fixture_mode' => 'manual'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O formato, o modo de tabela, os grupos e os turnos da liga não podem ser alterados porque já existem rodadas sorteadas.');

        $league->refresh();
        $this->assertSame('draw', $league->fixture_mode);
        $this->assertSame(2, $league->groups_count);
        $this->assertSame([1, 2, 1, 2], array_map('intval', $this->groupNumbers($league)));
    }

    public function test_championship_store_rejects_manual_league_with_several_groups(): void
    {
        $response = $this->postJson('/api/championships', [
            'name' => 'Copa',
            'leagues' => [['name' => 'Série A', 'format' => 'hybrid', 'groups_count' => 4]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(self::MESSAGE, $response->json('errors')['leagues.0.groups_count'][0]);
    }

    public function test_championship_update_switches_league_to_manual_and_resets_groups(): void
    {
        $championship = Championship::factory()->create();
        $league = $this->leagueWithGroups(['championship_id' => $championship->id]);

        $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [['id' => $league->id, 'name' => $league->name, 'fixture_mode' => 'manual']],
        ])->assertOk();

        $league->refresh();
        $this->assertSame('manual', $league->fixture_mode);
        $this->assertSame(1, $league->groups_count);
        $this->assertSame([null, null, null, null], $this->groupNumbers($league));

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [['id' => $league->id, 'name' => $league->name, 'groups_count' => 2]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(self::MESSAGE, $response->json('errors')['leagues.0.groups_count'][0]);
    }

    public function test_migration_resets_manual_leagues_with_several_groups(): void
    {
        $manual = $this->leagueWithGroups();
        DB::table('leagues')->where('id', $manual->id)->update(['fixture_mode' => 'manual', 'groups_count' => 2]);
        $draw = $this->leagueWithGroups();

        $migration = require database_path('migrations/2026_11_07_000000_reset_groups_of_manual_leagues.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, $manual->refresh()->groups_count);
        $this->assertSame([null, null, null, null], $this->groupNumbers($manual));
        $this->assertSame(2, $draw->refresh()->groups_count);
        $this->assertSame([1, 2, 1, 2], array_map('intval', $this->groupNumbers($draw)));
    }
}
