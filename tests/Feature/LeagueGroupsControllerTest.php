<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class LeagueGroupsControllerTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

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

    private function league(int $teams = 8, array $attributes = []): League
    {
        $league = League::factory()->create([...self::HYBRID_DRAW, ...$attributes]);
        $league->teams()->attach(Team::factory()->count($teams)->create()->pluck('id'));

        return $league;
    }

    private function assignment(League $league, array $groups): array
    {
        $teamIds = $league->teams()->orderBy('teams.id')->pluck('teams.id')->all();

        return array_map(fn ($teamId, $index) => ['team_id' => $teamId, 'group_number' => $groups[$index]], $teamIds, array_keys($teamIds));
    }

    private function groupSizes(League $league): array
    {
        return $league->teams()->get()->groupBy(fn ($team) => $team->pivot->group_number)->map->count()->sortKeys()->all();
    }

    public function test_guest_cannot_access_groups(): void
    {
        $league = $this->league();

        $this->getJson("/api/leagues/{$league->id}/groups")->assertStatus(401);
        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")->assertStatus(401);
        $this->putJson("/api/leagues/{$league->id}/groups", [])->assertStatus(401);
    }

    public function test_show_groups_lists_unassigned_teams_before_assembly(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4);

        $this->getJson("/api/leagues/{$league->id}/groups")
            ->assertOk()
            ->assertJsonPath('data.league_id', $league->id)
            ->assertJsonPath('data.fixture_mode', 'draw')
            ->assertJsonPath('data.groups_count', 2)
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.groups', [
                ['group_number' => 1, 'teams' => []],
                ['group_number' => 2, 'teams' => []],
            ])
            ->assertJsonCount(4, 'data.unassigned')
            ->assertJsonStructure(['data' => ['unassigned' => [['id', 'name', 'nickname', 'logo']]]]);
    }

    public function test_show_groups_of_single_group_league_has_no_unassigned_teams(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4, ['groups_count' => 1, 'qualifiers_per_group' => null, 'knockout_size' => null]);

        $response = $this->getJson("/api/leagues/{$league->id}/groups")
            ->assertOk()
            ->assertJsonPath('data.unassigned', [])
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.group_number', 1)
            ->assertJsonCount(4, 'data.groups.0.teams');

        $this->assertEqualsCanonicalizing(
            $league->teams()->pluck('teams.id')->all(),
            array_column($response->json('data.groups.0.teams'), 'id'),
        );
    }

    public function test_show_groups_reports_locked_after_draw(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4);
        Game::factory()->create(['league_id' => $league->id, 'group_number' => 1]);

        $this->getJson("/api/leagues/{$league->id}/groups")->assertOk()->assertJsonPath('data.locked', true);
    }

    public function test_shuffle_distributes_all_teams_in_equal_groups(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(12, ['groups_count' => 4, 'qualifiers_per_group' => 1]);

        $response = $this->postJson("/api/leagues/{$league->id}/groups/shuffle");

        $response->assertOk()
            ->assertJsonCount(4, 'data.groups')
            ->assertJsonPath('data.unassigned', []);
        foreach (range(0, 3) as $index) {
            $response->assertJsonPath("data.groups.{$index}.group_number", $index + 1)
                ->assertJsonCount(3, "data.groups.{$index}.teams");
        }

        $this->assertSame([1 => 3, 2 => 3, 3 => 3, 4 => 3], $this->groupSizes($league));
        $returnedIds = collect($response->json('data.groups'))->flatMap(fn ($group) => array_column($group['teams'], 'id'))->sort()->values()->all();
        $this->assertSame($league->teams()->orderBy('teams.id')->pluck('teams.id')->all(), $returnedIds);
    }

    public function test_shuffle_can_run_again_before_the_draw(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(8);

        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")->assertOk();
        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")->assertOk();

        $this->assertSame([1 => 4, 2 => 4], $this->groupSizes($league));
    }

    public function test_shuffle_with_a_single_group_puts_everyone_in_group_one(): void
    {
        $this->authenticateAs('master');
        $league = League::factory()->create(['fixture_mode' => 'draw']);
        $league->teams()->attach(Team::factory()->count(5)->create()->pluck('id'));

        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")
            ->assertOk()
            ->assertJsonCount(5, 'data.groups.0.teams');

        $this->assertSame([1 => 5], $this->groupSizes($league));
    }

    public function test_shuffle_requires_draw_mode(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(8, ['fixture_mode' => 'manual']);

        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A montagem dos grupos só está disponível em ligas com sorteio.');
    }

    public function test_shuffle_requires_teams_divisible_by_groups(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(9);

        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A liga tem 9 times, que não podem ser divididos igualmente em 2 grupos.');

        $this->assertSame(0, $league->teams()->wherePivotNotNull('group_number')->count());
    }

    public function test_shuffle_requires_teams(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(0);

        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A liga não tem times para distribuir nos grupos.');
    }

    public function test_shuffle_requires_at_least_two_teams_per_group(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4, ['groups_count' => 4, 'qualifiers_per_group' => null, 'knockout_size' => null]);

        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cada grupo precisa ter pelo menos 2 times.');
    }

    public function test_shuffle_requires_qualifiers_fewer_than_group_size(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4);

        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Os classificados por grupo devem ser menos que os 2 times de cada grupo.');
    }

    public function test_shuffle_is_blocked_after_rounds_are_drawn(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(8);
        Game::factory()->create(['league_id' => $league->id, 'group_number' => 1]);

        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Os grupos não podem ser alterados porque a liga já tem rodadas sorteadas.');
    }

    public function test_shuffle_of_missing_league_returns_422_for_master(): void
    {
        $this->authenticateAs('master');

        $this->postJson('/api/leagues/999999/groups/shuffle')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Liga não encontrada!');
    }

    public function test_manual_assignment_saves_the_groups(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(6, ['groups_count' => 2, 'qualifiers_per_group' => 2]);
        $payload = $this->assignment($league, [2, 1, 2, 1, 1, 2]);

        $response = $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => $payload]);

        $response->assertOk()
            ->assertJsonCount(3, 'data.groups.0.teams')
            ->assertJsonCount(3, 'data.groups.1.teams')
            ->assertJsonPath('data.unassigned', []);
        foreach ($payload as $item) {
            $this->assertDatabaseHas('league_team', ['league_id' => $league->id, 'team_id' => $item['team_id'], 'group_number' => $item['group_number']]);
        }
    }

    public function test_manual_assignment_rejects_team_outside_the_league(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);
        $payload = $this->assignment($league, [1, 1, 2, 2]);
        $payload[3]['team_id'] = Team::factory()->create()->id;

        $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => $payload])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Um ou mais times informados não pertencem à liga.');

        $this->assertSame(0, $league->teams()->wherePivotNotNull('group_number')->count());
    }

    public function test_manual_assignment_must_cover_every_team(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);
        $payload = array_slice($this->assignment($league, [1, 1, 2, 2]), 0, 3);

        $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => $payload])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Todos os times da liga devem ser atribuídos a um grupo.');
    }

    public function test_manual_assignment_rejects_group_out_of_range(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);

        $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => $this->assignment($league, [1, 1, 2, 3])])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O número do grupo deve estar entre 1 e 2.');
    }

    public function test_manual_assignment_requires_groups_of_the_same_size(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(6, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);

        $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => $this->assignment($league, [1, 1, 1, 1, 2, 2])])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Todos os grupos devem ter o mesmo número de times (3 por grupo).');
    }

    public function test_manual_assignment_requires_draw_mode(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4, ['fixture_mode' => 'manual', 'qualifiers_per_group' => 1, 'knockout_size' => 2]);

        $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => $this->assignment($league, [1, 1, 2, 2])])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A montagem dos grupos só está disponível em ligas com sorteio.');
    }

    public function test_manual_assignment_is_blocked_after_rounds_are_drawn(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);
        Game::factory()->create(['league_id' => $league->id, 'group_number' => 2]);

        $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => $this->assignment($league, [1, 1, 2, 2])])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Os grupos não podem ser alterados porque a liga já tem rodadas sorteadas.');
    }

    public function test_manual_assignment_validates_payload_shape(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);
        $teamId = $league->teams()->first()->id;

        $this->putJson("/api/leagues/{$league->id}/groups", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['groups']);

        $response = $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => [
            ['team_id' => $teamId, 'group_number' => 1],
            ['team_id' => $teamId, 'group_number' => 0],
            ['group_number' => 'a'],
        ]]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertArrayHasKey('groups.0.team_id', $errors);
        $this->assertArrayHasKey('groups.1.group_number', $errors);
        $this->assertArrayHasKey('groups.2.team_id', $errors);
        $this->assertSame('O campo número do grupo deve ser um número inteiro.', $errors['groups.2.group_number'][0]);
    }

    public function test_admin_of_the_league_can_assemble_groups(): void
    {
        $league = $this->league(4, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);
        $this->authenticateAs('admin', [$league->id]);

        $this->getJson("/api/leagues/{$league->id}/groups")->assertOk();
        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")->assertOk();
        $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => $this->assignment($league, [1, 2, 1, 2])])->assertOk();
    }

    public function test_admin_of_another_league_is_forbidden(): void
    {
        $league = $this->league(4, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);
        $other = League::factory()->create();
        $this->authenticateAs('admin', [$other->id]);

        $this->getJson("/api/leagues/{$league->id}/groups")->assertStatus(403);
        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")->assertStatus(403);
        $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => $this->assignment($league, [1, 2, 1, 2])])->assertStatus(403);
        $this->assertSame(0, $league->teams()->wherePivotNotNull('group_number')->count());
    }

    public function test_coach_can_view_but_not_assemble_groups(): void
    {
        $league = $this->league(3, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);
        $this->authenticateAs('coach', [$league->id]);

        $this->getJson("/api/leagues/{$league->id}/groups")->assertOk();
        $this->postJson("/api/leagues/{$league->id}/groups/shuffle")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->putJson("/api/leagues/{$league->id}/groups", ['groups' => []])->assertStatus(403);
    }

    public function test_public_league_show_exposes_team_group_number(): void
    {
        $league = $this->league(2, ['groups_count' => 1, 'qualifiers_per_group' => null, 'knockout_size' => null]);
        $team = $league->teams()->first();
        $league->teams()->updateExistingPivot($team->id, ['group_number' => 1]);

        $teams = collect($this->getJson("/api/public/leagues/{$league->id}")->assertOk()->json('data.teams'))->keyBy('id');

        $this->assertSame(1, $teams[$team->id]['group_number']);
        $this->assertNull($teams->firstWhere('id', '!=', $team->id)['group_number']);
    }

    public function test_team_in_a_drawn_group_cannot_leave_the_league(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);
        $team = $league->teams()->first();
        $league->teams()->updateExistingPivot($team->id, ['group_number' => 1]);
        Game::factory()->create(['league_id' => $league->id, 'group_number' => 2]);

        $this->deleteJson("/api/teams/{$team->id}/leagues/{$league->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Não é possível remover o time da liga pois ele está em um grupo com rodadas sorteadas.');

        $this->assertDatabaseHas('league_team', ['league_id' => $league->id, 'team_id' => $team->id]);
    }

    public function test_team_can_leave_the_league_before_the_draw_or_without_a_group(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);
        [$grouped, $ungrouped] = $league->teams()->orderBy('teams.id')->get()->all();
        $league->teams()->updateExistingPivot($grouped->id, ['group_number' => 1]);

        $this->deleteJson("/api/teams/{$grouped->id}/leagues/{$league->id}")->assertOk();

        Game::factory()->create(['league_id' => $league->id, 'group_number' => 1]);

        $this->deleteJson("/api/teams/{$ungrouped->id}/leagues/{$league->id}")->assertOk();
        $this->assertDatabaseMissing('league_team', ['league_id' => $league->id, 'team_id' => $ungrouped->id]);
    }

    public function test_team_in_a_drawn_group_of_another_league_can_leave_this_one(): void
    {
        $this->authenticateAs('master');
        $drawn = $this->league(4, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);
        $team = $drawn->teams()->first();
        $drawn->teams()->updateExistingPivot($team->id, ['group_number' => 1]);
        Game::factory()->create(['league_id' => $drawn->id, 'group_number' => 1]);
        $other = League::factory()->create();
        $other->teams()->attach($team->id, ['group_number' => 1]);

        $this->deleteJson("/api/teams/{$team->id}/leagues/{$other->id}")->assertOk();
    }

    public function test_team_in_a_drawn_group_cannot_be_deleted(): void
    {
        $this->authenticateAs('master');
        $league = $this->league(4, ['qualifiers_per_group' => 1, 'knockout_size' => 2]);
        $team = $league->teams()->first();
        $league->teams()->updateExistingPivot($team->id, ['group_number' => 1]);
        Game::factory()->create(['league_id' => $league->id, 'group_number' => 2]);

        $this->deleteJson("/api/teams/{$team->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Não é possível remover o time pois ele está no grupo de uma liga com rodadas sorteadas.');

        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }
}
