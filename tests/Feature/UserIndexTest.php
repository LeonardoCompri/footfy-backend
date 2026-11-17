<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class UserIndexTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    private function userWithRoles(array $roles, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($roles);

        return $user;
    }

    private function actingAsMaster(): User
    {
        $master = $this->userWithRoles(['master'], ['name' => 'Zz Master']);
        Sanctum::actingAs($master);

        return $master;
    }

    private function actingAsAdminOf(League $league, array $attributes = []): User
    {
        $admin = $this->userWithRoles(['admin'], ['name' => 'Zz Admin', ...$attributes]);
        $admin->leagues()->attach($league->id);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function ids(array $data): array
    {
        return array_column($data, 'id');
    }

    public function test_index_without_page_keeps_the_plain_list(): void
    {
        $master = $this->actingAsMaster();
        $users = User::factory()->count(3)->create();

        $response = $this->getJson('/api/users');

        $response->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonMissingPath('meta')
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'document', 'roles', 'leagues', 'team', 'player', 'created_at', 'updated_at']]]);
        $this->assertEqualsCanonicalizing([$master->id, ...$users->pluck('id')], $this->ids($response->json('data')));
    }

    public function test_index_with_page_returns_data_and_meta(): void
    {
        $this->actingAsMaster();
        $users = User::factory()->count(11)->create();

        $this->getJson('/api/users?page=3&per_page=5')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta', ['current_page' => 3, 'per_page' => 5, 'total' => 12, 'last_page' => 3])
            ->assertJsonPath('data.0.id', $users[9]->id)
            ->assertJsonPath('data.1.id', $users[10]->id);
    }

    public function test_page_without_per_page_uses_the_configured_default(): void
    {
        $this->actingAsMaster();
        User::factory()->count(21)->create();

        $this->getJson('/api/users?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 22);
    }

    public function test_paginated_item_keeps_roles_leagues_and_links(): void
    {
        $this->actingAsMaster();
        $league = League::factory()->create(['name' => 'Série A']);
        $coach = $this->userWithRoles(['coach'], ['name' => 'Aa Coach']);
        $this->linkUserToLeagues($coach, [$league->id]);
        $player = $this->userWithRoles(['player'], ['name' => 'Ab Player']);
        $this->linkUserToLeagues($player, [$league->id]);
        $admin = $this->userWithRoles(['admin'], ['name' => 'Ac Admin']);
        $admin->leagues()->attach($league->id);

        $response = $this->getJson('/api/users?page=1&sort=name');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $coach->id)
            ->assertJsonPath('data.0.roles', ['coach'])
            ->assertJsonPath('data.0.team.id', $coach->fresh()->team_id)
            ->assertJsonPath('data.0.player', null)
            ->assertJsonPath('data.1.id', $player->id)
            ->assertJsonPath('data.1.player.id', $player->player->id)
            ->assertJsonPath('data.1.team.id', $player->player->team_id)
            ->assertJsonPath('data.2.id', $admin->id)
            ->assertJsonPath('data.2.leagues', [['id' => $league->id, 'name' => 'Série A']])
            ->assertJsonPath('data.2.team', null);
        $this->assertSame(
            $this->getJson("/api/users/{$player->id}")->json('data'),
            $response->json('data.1'),
        );
    }

    public function test_sort_by_name_descending(): void
    {
        $this->actingAsMaster();
        User::factory()->create(['name' => 'Bruno']);
        User::factory()->create(['name' => 'Ana']);
        User::factory()->create(['name' => 'Carla']);

        $response = $this->getJson('/api/users?page=1&sort=name&order=desc');

        $this->assertSame(['Zz Master', 'Carla', 'Bruno', 'Ana'], array_column($response->json('data'), 'name'));
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_listing_parameters_return_422(string $query, string $field): void
    {
        $this->actingAsMaster();

        $this->getJson("/api/users?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public static function invalidQueryProvider(): array
    {
        return [
            'per_page acima do máximo' => ['page=1&per_page=101', 'per_page'],
            'per_page sem página também é validado' => ['per_page=500', 'per_page'],
            'ordenar por CPF não é permitido' => ['sort=document', 'sort'],
            'ordenar por senha não é permitido' => ['sort=password', 'sort'],
            'direção inválida' => ['order=random', 'order'],
            'página zero' => ['page=0', 'page'],
            'liga não numérica' => ['league_id=1abc', 'league_id'],
            'busca longa demais' => ['search='.str_repeat('a', 101), 'search'],
        ];
    }

    #[DataProvider('searchProvider')]
    public function test_search_by_name_email_and_document(string $term, array $expected): void
    {
        $this->actingAsMaster();
        User::factory()->create(['name' => 'Carlos Souza', 'email' => 'carlos@clube.com', 'document' => '12345678901']);
        User::factory()->create(['name' => 'Joana Lima', 'email' => 'jl@footfy.com', 'document' => '98765432100']);

        $response = $this->getJson('/api/users?page=1&search='.urlencode($term));

        $response->assertOk()->assertJsonPath('meta.total', count($expected));
        $this->assertSame($expected, array_column($response->json('data'), 'name'));
    }

    public static function searchProvider(): array
    {
        return [
            'nome sem caixa' => ['souza', ['Carlos Souza']],
            'e-mail' => ['jl@footfy', ['Joana Lima']],
            'domínio do e-mail' => ['clube.com', ['Carlos Souza']],
            'CPF formatado' => ['123.456.789-01', ['Carlos Souza']],
            'trecho do CPF' => ['432.10', ['Joana Lima']],
            'termo com letra não busca no CPF' => ['a123', []],
            'nada encontrado' => ['inexistente', []],
        ];
    }

    public function test_search_also_works_without_page(): void
    {
        $this->actingAsMaster();
        User::factory()->create(['name' => 'Carlos Souza']);
        User::factory()->create(['name' => 'Joana Lima']);

        $this->getJson('/api/users?search=joana')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Joana Lima')
            ->assertJsonMissingPath('meta');
    }

    public function test_admin_search_stays_inside_his_scope(): void
    {
        $league = League::factory()->create();
        $admin = $this->actingAsAdminOf($league);
        $coach = $this->userWithRoles(['coach'], ['name' => 'Rafael Dentro']);
        $this->linkUserToLeagues($coach, [$league->id]);
        $outsider = $this->userWithRoles(['coach'], ['name' => 'Rafael Fora']);
        $this->linkUserToLeagues($outsider, [League::factory()->create()->id]);
        $this->userWithRoles(['master'], ['name' => 'Rafael Master'])->leagues()->attach($league->id);

        $response = $this->getJson('/api/users?page=1&search=rafael');

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame([$coach->id], $this->ids($response->json('data')));
        $this->assertNotContains($admin->id, $this->ids($response->json('data')));
    }

    public function test_admin_paginated_list_keeps_scope_and_includes_himself(): void
    {
        $league = League::factory()->create();
        $admin = $this->actingAsAdminOf($league);
        $otherAdmin = $this->userWithRoles(['admin']);
        $otherAdmin->leagues()->attach($league->id);
        $coach = $this->userWithRoles(['coach']);
        $this->linkUserToLeagues($coach, [$league->id]);
        $master = $this->userWithRoles(['master']);
        $master->leagues()->attach($league->id);
        $this->userWithRoles(['admin'])->leagues()->attach(League::factory()->create()->id);
        User::factory()->create();

        $response = $this->getJson('/api/users?page=1');

        $response->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame([$admin->id, $otherAdmin->id, $coach->id], $this->ids($response->json('data')));
    }

    public function test_admin_who_is_also_coach_is_listed_by_league_path(): void
    {
        $league = League::factory()->create();
        $this->actingAsAdminOf($league);
        $otherLeague = League::factory()->create();
        $teamInLeague = Team::factory()->create();
        $teamInLeague->leagues()->attach($league->id);

        $byLeague = $this->userWithRoles(['admin', 'coach']);
        $byLeague->leagues()->attach($league->id);
        $byTeamOnly = $this->userWithRoles(['admin', 'coach'], ['team_id' => $teamInLeague->id]);
        $byTeamOnly->leagues()->attach($otherLeague->id);

        $ids = $this->ids($this->getJson('/api/users?page=1')->assertOk()->json('data'));

        $this->assertContains($byLeague->id, $ids);
        $this->assertNotContains($byTeamOnly->id, $ids);
    }

    public function test_team_scoped_users_are_listed_by_team_path(): void
    {
        $league = League::factory()->create();
        $this->actingAsAdminOf($league);
        $team = Team::factory()->create();
        $team->leagues()->attach($league->id);

        $coach = $this->userWithRoles(['coach'], ['team_id' => $team->id]);
        $legacyCoach = $this->userWithRoles(['coach']);
        $legacyCoach->leagues()->attach($league->id);
        $playerUser = $this->userWithRoles(['player']);
        $this->linkUserToLeagues($playerUser, [$league->id]);
        $noRole = User::factory()->create();
        $noRole->leagues()->attach($league->id);

        $ids = $this->ids($this->getJson('/api/users')->assertOk()->json('data'));

        $this->assertContains($coach->id, $ids);
        $this->assertContains($playerUser->id, $ids);
        $this->assertContains($noRole->id, $ids);
        $this->assertNotContains($legacyCoach->id, $ids);
    }

    public function test_admin_filter_by_league_with_page(): void
    {
        $league = League::factory()->create();
        $other = League::factory()->create();
        $admin = $this->actingAsAdminOf($league);
        $admin->leagues()->attach($other->id);
        $coachA = $this->userWithRoles(['coach']);
        $this->linkUserToLeagues($coachA, [$league->id]);
        $coachB = $this->userWithRoles(['coach']);
        $this->linkUserToLeagues($coachB, [$other->id]);

        $response = $this->getJson("/api/users?page=1&league_id={$other->id}");

        $response->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([$admin->id, $coachB->id], $this->ids($response->json('data')));
    }

    public function test_listing_query_has_no_dependent_role_subqueries(): void
    {
        $league = League::factory()->create();
        $this->actingAsAdminOf($league);
        $coach = $this->userWithRoles(['coach']);
        $this->linkUserToLeagues($coach, [$league->id]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $sql = strtolower(str_replace(['"', '`'], '', $query->sql));
            if (str_contains($sql, 'from users')) {
                $queries[] = $sql;
            }
        });

        $this->getJson('/api/users?page=1')->assertOk()->assertJsonPath('meta.total', 2);

        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('exists', $sql);
        }
    }
}
