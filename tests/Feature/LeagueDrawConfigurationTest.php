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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LeagueDrawConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const HYBRID_DRAW = [
        'format' => 'hybrid',
        'fixture_mode' => 'draw',
        'groups_count' => 2,
        'group_legs' => 2,
        'qualifiers_per_group' => 2,
        'knockout_size' => 4,
        'knockout_legs' => 2,
        'final_legs' => 1,
        'third_place' => true,
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

    private function leagueWithTeams(int $teams, array $attributes = []): League
    {
        $league = League::factory()->create($attributes);
        $league->teams()->attach(Team::factory()->count($teams)->create()->pluck('id'));

        return $league;
    }

    private function drawnRound(League $league): Game
    {
        return Game::factory()->create(['league_id' => $league->id, 'round' => 1, 'group_number' => 1]);
    }

    public function test_existing_league_without_configuration_keeps_working_and_exposes_defaults(): void
    {
        $league = League::factory()->create(['format' => 'hybrid']);

        $this->putJson("/api/leagues/{$league->id}", ['name' => 'Série A', 'format' => 'hybrid'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Série A')
            ->assertJsonPath('data.fixture_mode', 'manual')
            ->assertJsonPath('data.groups_count', 1)
            ->assertJsonPath('data.group_legs', 1)
            ->assertJsonPath('data.qualifiers_per_group', null)
            ->assertJsonPath('data.knockout_size', null)
            ->assertJsonPath('data.knockout_legs', null)
            ->assertJsonPath('data.final_legs', null)
            ->assertJsonPath('data.third_place', false)
            ->assertJsonPath('data.tiebreakers', null)
            ->assertJsonPath('data.knockout_tiebreakers', null);
    }

    public function test_show_exposes_configuration_fields(): void
    {
        $league = League::factory()->create([...self::HYBRID_DRAW, 'tiebreakers' => ['points', 'goal_difference']]);

        $this->getJson("/api/leagues/{$league->id}")
            ->assertOk()
            ->assertJsonPath('data.fixture_mode', 'draw')
            ->assertJsonPath('data.groups_count', 2)
            ->assertJsonPath('data.third_place', true)
            ->assertJsonPath('data.tiebreakers', ['points', 'goal_difference'])
            ->assertJsonPath('data.knockout_tiebreakers', ['penalties']);
    }

    public function test_master_can_configure_hybrid_league_with_draw(): void
    {
        $league = $this->leagueWithTeams(8);

        $this->putJson("/api/leagues/{$league->id}", [
            ...self::HYBRID_DRAW,
            'tiebreakers' => ['points', 'head_to_head', 'wins', 'fewer_red_cards'],
        ])
            ->assertOk()
            ->assertJsonPath('data.fixture_mode', 'draw')
            ->assertJsonPath('data.knockout_size', 4)
            ->assertJsonPath('data.tiebreakers', ['points', 'head_to_head', 'wins', 'fewer_red_cards']);

        $league->refresh();
        $this->assertSame('draw', $league->fixture_mode);
        $this->assertSame(2, $league->qualifiers_per_group);
        $this->assertTrue($league->third_place);
        $this->assertSame(['points', 'head_to_head', 'wins', 'fewer_red_cards'], $league->effectiveTiebreakers());
    }

    public function test_store_league_accepts_configuration(): void
    {
        $championship = Championship::factory()->create();

        $this->postJson('/api/leagues', [
            'championship_id' => $championship->id,
            'name' => 'Copa',
            ...self::HYBRID_DRAW,
        ])
            ->assertCreated()
            ->assertJsonPath('data.fixture_mode', 'draw')
            ->assertJsonPath('data.groups_count', 2);

        $this->assertDatabaseHas('leagues', ['name' => 'Copa', 'fixture_mode' => 'draw', 'knockout_size' => 4]);
    }

    public function test_store_league_without_configuration_uses_defaults(): void
    {
        $championship = Championship::factory()->create();

        $this->postJson('/api/leagues', ['championship_id' => $championship->id, 'name' => 'Liga'])
            ->assertCreated()
            ->assertJsonPath('data.fixture_mode', 'manual')
            ->assertJsonPath('data.groups_count', 1)
            ->assertJsonPath('data.third_place', false);

        $this->assertDatabaseHas('leagues', ['name' => 'Liga', 'fixture_mode' => 'manual', 'groups_count' => 1, 'group_legs' => 1, 'third_place' => false]);
    }

    public function test_default_tiebreakers_apply_when_null(): void
    {
        $league = League::factory()->create();

        $this->assertNull($league->tiebreakers);
        $this->assertSame(
            ['points', 'wins', 'goal_difference', 'goals_for', 'head_to_head', 'fewer_cards'],
            $league->effectiveTiebreakers(),
        );
    }

    public function test_hybrid_draw_requires_knockout_fields(): void
    {
        $league = League::factory()->create();

        $response = $this->putJson("/api/leagues/{$league->id}", ['format' => 'hybrid', 'fixture_mode' => 'draw', 'groups_count' => 2]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['qualifiers_per_group', 'knockout_size', 'knockout_legs', 'final_legs', 'knockout_tiebreakers'])
            ->assertJsonMissingValidationErrors(['third_place'])
            ->assertJsonPath('errors.knockout_size.0', 'O campo tamanho do mata-mata é obrigatório.');
    }

    public function test_hybrid_manual_does_not_require_knockout_fields(): void
    {
        $league = League::factory()->create();

        $this->putJson("/api/leagues/{$league->id}", ['format' => 'hybrid', 'fixture_mode' => 'manual'])
            ->assertOk()
            ->assertJsonPath('data.format', 'hybrid');
    }

    public function test_knockout_size_must_match_groups_times_qualifiers(): void
    {
        $league = League::factory()->create();

        $this->putJson("/api/leagues/{$league->id}", [...self::HYBRID_DRAW, 'knockout_size' => 8])
            ->assertStatus(422)
            ->assertJsonPath('errors.knockout_size.0', 'O tamanho do mata-mata deve ser igual a grupos × classificados por grupo (4).');
    }

    public function test_partial_update_validates_against_stored_configuration(): void
    {
        $league = League::factory()->create(self::HYBRID_DRAW);

        $this->putJson("/api/leagues/{$league->id}", ['qualifiers_per_group' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['knockout_size']);

        $this->putJson("/api/leagues/{$league->id}", ['qualifiers_per_group' => 4, 'knockout_size' => 8])
            ->assertOk()
            ->assertJsonPath('data.knockout_size', 8);
    }

    public function test_round_robin_rejects_knockout_fields(): void
    {
        $league = League::factory()->create();

        $response = $this->putJson("/api/leagues/{$league->id}", [
            'format' => 'round_robin',
            'qualifiers_per_group' => 2,
            'knockout_size' => 4,
            'knockout_legs' => 1,
            'final_legs' => 1,
            'third_place' => true,
            'knockout_tiebreakers' => ['penalties'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['qualifiers_per_group', 'knockout_size', 'knockout_legs', 'final_legs', 'third_place', 'knockout_tiebreakers'])
            ->assertJsonPath('errors.third_place.0', 'Ligas de pontos corridos não têm mata-mata.');
    }

    public function test_round_robin_has_a_single_group(): void
    {
        $league = League::factory()->create();

        $this->putJson("/api/leagues/{$league->id}", ['fixture_mode' => 'draw', 'groups_count' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.groups_count.0', 'Ligas de pontos corridos têm um único grupo.');
    }

    public function test_round_robin_accepts_draw_with_one_group(): void
    {
        $league = $this->leagueWithTeams(6);

        $this->putJson("/api/leagues/{$league->id}", ['fixture_mode' => 'draw', 'group_legs' => 2, 'tiebreakers' => ['points', 'goals_against']])
            ->assertOk()
            ->assertJsonPath('data.fixture_mode', 'draw')
            ->assertJsonPath('data.group_legs', 2);
    }

    public function test_knockout_format_only_accepts_manual_mode_without_groups(): void
    {
        $league = League::factory()->create(['format' => 'knockout']);

        $this->putJson("/api/leagues/{$league->id}", ['fixture_mode' => 'draw', 'groups_count' => 2, 'qualifiers_per_group' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.fixture_mode.0', 'Ligas mata-mata só aceitam tabela manual.')
            ->assertJsonPath('errors.groups_count.0', 'Ligas mata-mata não usam grupos.')
            ->assertJsonPath('errors.qualifiers_per_group.0', 'Ligas mata-mata não usam classificados por grupo.');

        $this->putJson("/api/leagues/{$league->id}", ['knockout_legs' => 2, 'final_legs' => 1, 'third_place' => true, 'knockout_tiebreakers' => ['away_goals', 'penalties']])
            ->assertOk()
            ->assertJsonPath('data.knockout_tiebreakers', ['away_goals', 'penalties']);
    }

    public function test_invalid_field_values_return_422(): void
    {
        $league = League::factory()->create();

        $this->putJson("/api/leagues/{$league->id}", [
            'fixture_mode' => 'auto',
            'groups_count' => 0,
            'group_legs' => 3,
            'knockout_size' => 6,
            'knockout_legs' => 3,
            'final_legs' => 0,
            'third_place' => 'talvez',
            'tiebreakers' => ['points', 'sorteio'],
            'knockout_tiebreakers' => ['coin_toss'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'fixture_mode', 'groups_count', 'group_legs', 'knockout_size', 'knockout_legs',
                'final_legs', 'third_place', 'tiebreakers.1', 'knockout_tiebreakers.0',
            ]);
    }

    public function test_non_nullable_fields_reject_null(): void
    {
        $league = League::factory()->create();

        $this->putJson("/api/leagues/{$league->id}", ['fixture_mode' => null, 'groups_count' => null, 'group_legs' => null, 'third_place' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fixture_mode', 'groups_count', 'group_legs', 'third_place']);
    }

    public function test_tiebreakers_must_start_with_points(): void
    {
        $league = League::factory()->create();

        $this->putJson("/api/leagues/{$league->id}", ['tiebreakers' => ['wins', 'points']])
            ->assertStatus(422)
            ->assertJsonPath('errors.tiebreakers.0', 'O primeiro critério de desempate deve ser points.');

        $this->putJson("/api/leagues/{$league->id}", ['tiebreakers' => []])
            ->assertStatus(422)
            ->assertJsonPath('errors.tiebreakers.0', 'O primeiro critério de desempate deve ser points.');
    }

    public function test_tiebreakers_cannot_repeat(): void
    {
        $league = League::factory()->create();

        $this->putJson("/api/leagues/{$league->id}", ['tiebreakers' => ['points', 'wins', 'wins']])
            ->assertStatus(422)
            ->assertJsonPath('errors.tiebreakers.0', 'Os critérios de desempate não podem se repetir.');
    }

    public function test_tiebreakers_must_be_a_list(): void
    {
        $league = League::factory()->create();

        $this->putJson("/api/leagues/{$league->id}", ['tiebreakers' => ['a' => 'points']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tiebreakers']);
    }

    public function test_tiebreakers_can_be_reset_to_default(): void
    {
        $league = League::factory()->create(['tiebreakers' => ['points', 'wins']]);

        $this->putJson("/api/leagues/{$league->id}", ['tiebreakers' => null])
            ->assertOk()
            ->assertJsonPath('data.tiebreakers', null);

        $this->assertSame(League::DEFAULT_TIEBREAKERS, $league->refresh()->effectiveTiebreakers());
    }

    public function test_knockout_tiebreakers_only_accept_known_sequences(): void
    {
        $league = League::factory()->create();

        foreach ([['penalties', 'away_goals'], ['penalties', 'penalties'], ['away_goals'], []] as $sequence) {
            $this->putJson("/api/leagues/{$league->id}", [...self::HYBRID_DRAW, 'knockout_tiebreakers' => $sequence])
                ->assertStatus(422)
                ->assertJsonPath('errors.knockout_tiebreakers.0', 'A sequência de desempate do mata-mata é inválida.');
        }

        foreach (League::KNOCKOUT_TIEBREAKER_SEQUENCES as $sequence) {
            $this->putJson("/api/leagues/{$league->id}", [...self::HYBRID_DRAW, 'knockout_tiebreakers' => $sequence])
                ->assertOk()
                ->assertJsonPath('data.knockout_tiebreakers', $sequence);
        }
    }

    public function test_away_goals_requires_a_two_legged_phase(): void
    {
        $league = League::factory()->create();

        $this->putJson("/api/leagues/{$league->id}", [
            ...self::HYBRID_DRAW,
            'knockout_legs' => 1,
            'final_legs' => 1,
            'knockout_tiebreakers' => ['away_goals', 'penalties'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.knockout_tiebreakers.0', 'O gol fora de casa só vale com confrontos de ida e volta.');

        $this->putJson("/api/leagues/{$league->id}", [
            ...self::HYBRID_DRAW,
            'knockout_legs' => 1,
            'final_legs' => 2,
            'knockout_tiebreakers' => ['away_goals', 'penalties'],
        ])->assertOk();
    }

    public function test_teams_must_split_evenly_into_groups(): void
    {
        $league = $this->leagueWithTeams(9);

        $this->putJson("/api/leagues/{$league->id}", self::HYBRID_DRAW)
            ->assertStatus(422)
            ->assertJsonPath('errors.groups_count.0', 'A liga tem 9 times, que não podem ser divididos igualmente em 2 grupos.');
    }

    public function test_qualifiers_must_be_fewer_than_group_size(): void
    {
        $league = $this->leagueWithTeams(4);

        $this->putJson("/api/leagues/{$league->id}", self::HYBRID_DRAW)
            ->assertStatus(422)
            ->assertJsonPath('errors.qualifiers_per_group.0', 'Os classificados por grupo devem ser menos que os 2 times de cada grupo.');
    }

    public function test_team_count_is_not_checked_without_teams_or_in_manual_mode(): void
    {
        $empty = League::factory()->create();
        $this->putJson("/api/leagues/{$empty->id}", self::HYBRID_DRAW)->assertOk();

        $manual = $this->leagueWithTeams(9);
        $this->putJson("/api/leagues/{$manual->id}", [...self::HYBRID_DRAW, 'fixture_mode' => 'manual'])->assertOk();
    }

    public function test_team_count_is_only_checked_when_group_fields_change(): void
    {
        $league = $this->leagueWithTeams(9, self::HYBRID_DRAW);

        $this->putJson("/api/leagues/{$league->id}", [...self::HYBRID_DRAW, 'name' => 'Nome novo'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nome novo');
    }

    public function test_structure_is_locked_after_rounds_are_drawn(): void
    {
        $league = League::factory()->create(self::HYBRID_DRAW);
        $this->drawnRound($league);

        foreach ([['groups_count' => 4, 'knockout_size' => 8], ['group_legs' => 1], ['fixture_mode' => 'manual'], ['format' => 'round_robin', 'groups_count' => 1, 'qualifiers_per_group' => null, 'knockout_size' => null, 'knockout_legs' => null, 'final_legs' => null, 'third_place' => false, 'knockout_tiebreakers' => null]] as $payload) {
            $this->putJson("/api/leagues/{$league->id}", $payload)
                ->assertStatus(422)
                ->assertJsonPath('message', 'O formato, o modo de tabela, os grupos e os turnos da liga não podem ser alterados porque já existem rodadas sorteadas.');
        }

        $league->refresh();
        $this->assertSame(2, $league->groups_count);
        $this->assertSame(2, $league->group_legs);
        $this->assertSame('draw', $league->fixture_mode);
        $this->assertSame('hybrid', $league->format);
    }

    public function test_locked_structure_still_accepts_same_values_and_other_fields(): void
    {
        $league = League::factory()->create(self::HYBRID_DRAW);
        $this->drawnRound($league);

        $this->putJson("/api/leagues/{$league->id}", [...self::HYBRID_DRAW, 'name' => 'Copa 2026', 'knockout_legs' => 1])
            ->assertOk()
            ->assertJsonPath('data.name', 'Copa 2026')
            ->assertJsonPath('data.knockout_legs', 1);
    }

    public function test_manual_games_with_round_do_not_lock_structure(): void
    {
        $league = League::factory()->create(self::HYBRID_DRAW);
        Game::factory()->create(['league_id' => $league->id, 'round' => 1]);

        $this->putJson("/api/leagues/{$league->id}", ['group_legs' => 1])->assertOk();
    }

    public function test_knockout_and_criteria_are_locked_after_knockout_games_in_draw_mode(): void
    {
        $league = League::factory()->create(self::HYBRID_DRAW);
        Game::factory()->create(['league_id' => $league->id, 'round' => null, 'phase' => 'Semifinal', 'tie_key' => 'SF1', 'leg' => 1]);

        foreach ([['knockout_legs' => 1], ['final_legs' => 2], ['third_place' => false], ['tiebreakers' => ['points', 'wins']], ['knockout_tiebreakers' => ['better_campaign']]] as $payload) {
            $this->putJson("/api/leagues/{$league->id}", $payload)
                ->assertStatus(422)
                ->assertJsonPath('message', 'O mata-mata e os critérios de desempate da liga não podem ser alterados porque o mata-mata já foi gerado.');
        }

        $this->putJson("/api/leagues/{$league->id}", [...self::HYBRID_DRAW, 'name' => 'Outro nome'])->assertOk();
    }

    public function test_knockout_games_in_manual_mode_do_not_lock_knockout_fields(): void
    {
        $league = League::factory()->create(['format' => 'knockout']);
        Game::factory()->create(['league_id' => $league->id, 'round' => null, 'phase' => 'Final']);

        $this->putJson("/api/leagues/{$league->id}", ['knockout_legs' => 2, 'tiebreakers' => ['points', 'wins']])
            ->assertOk()
            ->assertJsonPath('data.knockout_legs', 2);
    }

    public function test_changing_groups_count_clears_assigned_groups(): void
    {
        $league = $this->leagueWithTeams(8, self::HYBRID_DRAW);
        $league->teams()->newPivotQuery()->update(['group_number' => 1]);
        $other = $this->leagueWithTeams(2);
        $other->teams()->newPivotQuery()->update(['group_number' => 1]);

        $this->putJson("/api/leagues/{$league->id}", ['groups_count' => 4, 'qualifiers_per_group' => 1])->assertOk();

        $this->assertSame(0, $league->teams()->wherePivotNotNull('group_number')->count());
        $this->assertSame(2, $other->teams()->wherePivotNotNull('group_number')->count());
    }

    public function test_keeping_groups_count_keeps_assigned_groups(): void
    {
        $league = $this->leagueWithTeams(8, self::HYBRID_DRAW);
        $league->teams()->newPivotQuery()->update(['group_number' => 1]);

        $this->putJson("/api/leagues/{$league->id}", ['groups_count' => 2, 'group_legs' => 1])->assertOk();

        $this->assertSame(8, $league->teams()->wherePivotNotNull('group_number')->count());
    }

    public function test_championship_store_validates_nested_league_configuration(): void
    {
        $response = $this->postJson('/api/championships', [
            'name' => 'Copa',
            'leagues' => [
                ['name' => 'Série A', 'format' => 'hybrid', 'fixture_mode' => 'draw', 'groups_count' => 2],
                ['name' => 'Série B', 'format' => 'round_robin', 'third_place' => true],
            ],
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertSame('O campo tamanho do mata-mata é obrigatório.', $errors['leagues.0.knockout_size'][0]);
        $this->assertArrayHasKey('leagues.0.qualifiers_per_group', $errors);
        $this->assertSame('Ligas de pontos corridos não têm mata-mata.', $errors['leagues.1.third_place'][0]);
        $this->assertDatabaseCount('championships', 0);
    }

    public function test_championship_store_saves_nested_league_configuration(): void
    {
        $this->postJson('/api/championships', [
            'name' => 'Copa',
            'leagues' => [
                ['name' => 'Série A', ...self::HYBRID_DRAW, 'tiebreakers' => ['points', 'wins']],
                ['name' => 'Série B', 'fixture_mode' => 'draw', 'tiebreakers' => ['points', 'wins']],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.leagues.0.fixture_mode', 'draw')
            ->assertJsonPath('data.leagues.0.knockout_size', 4)
            ->assertJsonPath('data.leagues.0.tiebreakers', ['points', 'wins'])
            ->assertJsonPath('data.leagues.1.format', 'round_robin')
            ->assertJsonPath('data.leagues.1.groups_count', 1);

        $this->assertDatabaseHas('leagues', ['name' => 'Série A', 'fixture_mode' => 'draw', 'groups_count' => 2, 'third_place' => true]);
    }

    public function test_championship_multipart_accepts_string_values(): void
    {
        $this->post('/api/championships', [
            'name' => 'Copa',
            'leagues' => [[
                'name' => 'Série A',
                'format' => 'hybrid',
                'fixture_mode' => 'draw',
                'groups_count' => '2',
                'group_legs' => '1',
                'qualifiers_per_group' => '2',
                'knockout_size' => '4',
                'knockout_legs' => '2',
                'final_legs' => '1',
                'third_place' => '0',
                'knockout_tiebreakers' => ['penalties'],
            ]],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.leagues.0.knockout_size', 4)
            ->assertJsonPath('data.leagues.0.third_place', false);
    }

    public function test_championship_update_validates_against_stored_league(): void
    {
        $championship = Championship::factory()->create();
        $league = League::factory()->create([...self::HYBRID_DRAW, 'championship_id' => $championship->id]);

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [['id' => $league->id, 'name' => $league->name, 'qualifiers_per_group' => 4]],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('leagues.0.knockout_size', $response->json('errors'));
    }

    public function test_championship_update_respects_structure_lock(): void
    {
        $championship = Championship::factory()->create();
        $league = League::factory()->create([...self::HYBRID_DRAW, 'championship_id' => $championship->id]);
        $this->drawnRound($league);

        $this->putJson("/api/championships/{$championship->id}", [
            'name' => 'Nome novo',
            'leagues' => [['id' => $league->id, 'name' => $league->name, 'group_legs' => 1]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O formato, o modo de tabela, os grupos e os turnos da liga não podem ser alterados porque já existem rodadas sorteadas.');

        $this->assertSame(2, $league->refresh()->group_legs);
        $this->assertDatabaseMissing('championships', ['name' => 'Nome novo']);
    }

    public function test_championship_update_clears_groups_when_groups_count_changes(): void
    {
        $championship = Championship::factory()->create();
        $league = $this->leagueWithTeams(8, [...self::HYBRID_DRAW, 'championship_id' => $championship->id]);
        $league->teams()->newPivotQuery()->update(['group_number' => 1]);

        $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [['id' => $league->id, 'name' => $league->name, 'groups_count' => 4, 'qualifiers_per_group' => 1]],
        ])->assertOk();

        $this->assertSame(0, $league->teams()->wherePivotNotNull('group_number')->count());
    }

    public function test_switching_hybrid_to_round_robin_clears_group_and_knockout_settings(): void
    {
        $league = $this->leagueWithTeams(8, self::HYBRID_DRAW);
        $league->teams()->newPivotQuery()->update(['group_number' => 1]);

        $this->putJson("/api/leagues/{$league->id}", ['format' => 'round_robin'])
            ->assertOk()
            ->assertJsonPath('data.format', 'round_robin')
            ->assertJsonPath('data.fixture_mode', 'draw')
            ->assertJsonPath('data.groups_count', 1)
            ->assertJsonPath('data.group_legs', 2)
            ->assertJsonPath('data.qualifiers_per_group', null)
            ->assertJsonPath('data.knockout_size', null)
            ->assertJsonPath('data.knockout_legs', null)
            ->assertJsonPath('data.final_legs', null)
            ->assertJsonPath('data.third_place', false)
            ->assertJsonPath('data.knockout_tiebreakers', null);

        $this->assertSame(0, $league->teams()->wherePivotNotNull('group_number')->count());
    }

    public function test_switching_hybrid_to_knockout_turns_off_draw_and_groups(): void
    {
        $league = $this->leagueWithTeams(8, self::HYBRID_DRAW);
        $league->teams()->newPivotQuery()->update(['group_number' => 2]);

        $this->putJson("/api/leagues/{$league->id}", ['format' => 'knockout'])
            ->assertOk()
            ->assertJsonPath('data.format', 'knockout')
            ->assertJsonPath('data.fixture_mode', 'manual')
            ->assertJsonPath('data.groups_count', 1)
            ->assertJsonPath('data.qualifiers_per_group', null)
            ->assertJsonPath('data.knockout_size', 4)
            ->assertJsonPath('data.knockout_legs', 2)
            ->assertJsonPath('data.third_place', true);

        $this->assertSame(0, $league->teams()->wherePivotNotNull('group_number')->count());
    }

    public function test_switching_round_robin_draw_to_knockout_clears_the_single_group(): void
    {
        $league = $this->leagueWithTeams(6, ['fixture_mode' => 'draw']);
        $league->teams()->newPivotQuery()->update(['group_number' => 1]);

        $this->putJson("/api/leagues/{$league->id}", ['format' => 'knockout'])
            ->assertOk()
            ->assertJsonPath('data.fixture_mode', 'manual');

        $this->assertSame(0, $league->teams()->wherePivotNotNull('group_number')->count());
    }

    public function test_switching_round_robin_to_hybrid_draw_still_requires_knockout_settings(): void
    {
        $league = $this->leagueWithTeams(8, ['fixture_mode' => 'draw']);

        $this->putJson("/api/leagues/{$league->id}", ['format' => 'hybrid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['qualifiers_per_group', 'knockout_size', 'knockout_legs', 'final_legs', 'knockout_tiebreakers']);

        $this->assertSame('round_robin', $league->refresh()->format);

        $this->putJson("/api/leagues/{$league->id}", ['format' => 'hybrid', 'fixture_mode' => 'manual'])
            ->assertOk()
            ->assertJsonPath('data.format', 'hybrid');
    }

    public function test_values_sent_with_the_new_format_are_still_validated(): void
    {
        $league = League::factory()->create(self::HYBRID_DRAW);

        $this->putJson("/api/leagues/{$league->id}", ['format' => 'round_robin', 'groups_count' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.groups_count.0', 'Ligas de pontos corridos têm um único grupo.');

        $this->putJson("/api/leagues/{$league->id}", ['format' => 'knockout', 'fixture_mode' => 'draw'])
            ->assertStatus(422)
            ->assertJsonPath('errors.fixture_mode.0', 'Ligas mata-mata só aceitam tabela manual.');

        $this->assertSame('hybrid', $league->refresh()->format);
    }

    public function test_format_change_is_not_adjusted_after_rounds_are_drawn(): void
    {
        $league = League::factory()->create(self::HYBRID_DRAW);
        $this->drawnRound($league);

        $this->putJson("/api/leagues/{$league->id}", ['format' => 'round_robin'])
            ->assertStatus(422)
            ->assertJsonPath('errors.groups_count.0', 'Ligas de pontos corridos têm um único grupo.');

        $league->refresh();
        $this->assertSame('hybrid', $league->format);
        $this->assertSame(2, $league->groups_count);
        $this->assertSame(4, $league->knockout_size);
    }

    public function test_championship_update_switches_league_format_without_resending_settings(): void
    {
        $championship = Championship::factory()->create();
        $hybrid = $this->leagueWithTeams(8, [...self::HYBRID_DRAW, 'championship_id' => $championship->id]);
        $hybrid->teams()->newPivotQuery()->update(['group_number' => 1]);
        $other = League::factory()->create([...self::HYBRID_DRAW, 'championship_id' => $championship->id]);

        $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [
                ['id' => $hybrid->id, 'name' => $hybrid->name, 'format' => 'knockout'],
                ['id' => $other->id, 'name' => $other->name, 'format' => 'round_robin'],
            ],
        ])->assertOk();

        $hybrid->refresh();
        $this->assertSame('knockout', $hybrid->format);
        $this->assertSame('manual', $hybrid->fixture_mode);
        $this->assertSame(1, $hybrid->groups_count);
        $this->assertNull($hybrid->qualifiers_per_group);
        $this->assertSame(0, $hybrid->teams()->wherePivotNotNull('group_number')->count());

        $other->refresh();
        $this->assertSame('round_robin', $other->format);
        $this->assertSame(1, $other->groups_count);
        $this->assertNull($other->knockout_size);
        $this->assertFalse($other->third_place);
    }

    public function test_championship_multipart_switches_league_format(): void
    {
        $championship = Championship::factory()->create();
        $league = League::factory()->create([...self::HYBRID_DRAW, 'championship_id' => $championship->id]);

        $this->post("/api/championships/{$championship->id}", [
            '_method' => 'PUT',
            'leagues' => [['id' => (string) $league->id, 'name' => $league->name, 'format' => 'round_robin']],
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('round_robin', $league->refresh()->format);
        $this->assertSame(1, $league->groups_count);
    }

    public function test_championship_update_keeps_the_lock_when_rounds_are_drawn(): void
    {
        $championship = Championship::factory()->create();
        $league = League::factory()->create([...self::HYBRID_DRAW, 'championship_id' => $championship->id]);
        $this->drawnRound($league);

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [['id' => $league->id, 'name' => $league->name, 'format' => 'round_robin']],
        ]);

        $response->assertStatus(422);
        $this->assertSame('Ligas de pontos corridos têm um único grupo.', $response->json('errors')['leagues.0.groups_count'][0]);
        $this->assertSame('hybrid', $league->refresh()->format);
    }

    public function test_draw_league_needs_at_least_two_teams_per_group(): void
    {
        $league = $this->leagueWithTeams(2);

        $this->putJson("/api/leagues/{$league->id}", [...self::HYBRID_DRAW, 'qualifiers_per_group' => 1, 'knockout_size' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.groups_count.0', 'Cada grupo precisa ter pelo menos 2 times.');

        $single = $this->leagueWithTeams(1);
        $this->putJson("/api/leagues/{$single->id}", ['fixture_mode' => 'draw'])
            ->assertStatus(422)
            ->assertJsonPath('errors.groups_count.0', 'Cada grupo precisa ter pelo menos 2 times.');

        $pair = $this->leagueWithTeams(2);
        $this->putJson("/api/leagues/{$pair->id}", ['fixture_mode' => 'draw'])->assertOk();
    }

    public function test_championship_update_needs_at_least_two_teams_per_group(): void
    {
        $championship = Championship::factory()->create();
        $league = $this->leagueWithTeams(4, ['championship_id' => $championship->id]);

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'leagues' => [['id' => $league->id, 'name' => $league->name, 'format' => 'hybrid', 'fixture_mode' => 'draw', 'groups_count' => 4, 'qualifiers_per_group' => 1, 'knockout_size' => 4, 'knockout_legs' => 1, 'final_legs' => 1, 'knockout_tiebreakers' => ['penalties']]],
        ]);

        $response->assertStatus(422);
        $this->assertSame('Cada grupo precisa ter pelo menos 2 times.', $response->json('errors')['leagues.0.groups_count'][0]);
    }
}
