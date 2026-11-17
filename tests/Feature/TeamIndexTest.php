<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class TeamIndexTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    private function actingAsRole(string $role, array $leagueIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        if (! empty($leagueIds)) {
            $this->linkUserToLeagues($user, $leagueIds);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function teamsInLeague(League $league, array $names): array
    {
        return array_map(function (string $name) use ($league) {
            $team = Team::factory()->create(['name' => $name]);
            $team->leagues()->attach($league->id);

            return $team;
        }, $names);
    }

    public function test_index_without_page_keeps_the_plain_list(): void
    {
        Team::factory()->count(3)->create();
        $this->actingAsRole('master');

        $this->getJson('/api/teams')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta');
    }

    public function test_index_with_page_returns_data_and_meta(): void
    {
        $teams = Team::factory()->count(25)->create();
        $this->actingAsRole('master');

        $response = $this->getJson('/api/teams?page=2&per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta', ['current_page' => 2, 'per_page' => 10, 'total' => 25, 'last_page' => 3])
            ->assertJsonPath('data.0.id', $teams[10]->id)
            ->assertJsonMissingPath('data.0.players');
    }

    public function test_page_without_per_page_uses_the_configured_default(): void
    {
        config(['footfy.pagination.per_page' => 3]);
        Team::factory()->count(5)->create();
        $this->actingAsRole('master');

        $this->getJson('/api/teams?page=1')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_per_page_above_the_configured_maximum_returns_422(): void
    {
        config(['footfy.pagination.max_per_page' => 5]);
        $this->actingAsRole('master');

        $this->getJson('/api/teams?page=1&per_page=6')
            ->assertStatus(422)
            ->assertJsonPath('errors.per_page.0', 'O campo itens por página não pode ser maior que 5.');

        $this->getJson('/api/teams?page=1&per_page=5')->assertOk();
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_listing_parameters_return_422(string $query, string $field): void
    {
        $this->actingAsRole('master');

        $this->getJson("/api/teams?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public static function invalidQueryProvider(): array
    {
        return [
            'per_page acima do máximo' => ['page=1&per_page=101', 'per_page'],
            'per_page zero' => ['page=1&per_page=0', 'per_page'],
            'página zero' => ['page=0', 'page'],
            'página não numérica' => ['page=abc', 'page'],
            'coluna fora da lista' => ['sort=logo', 'sort'],
            'direção inválida' => ['sort=name&order=up', 'order'],
            'busca como array' => ['search[]=a', 'search'],
        ];
    }

    public function test_search_filters_by_name_with_and_without_page(): void
    {
        $league = League::factory()->create();
        [, $fluminense] = $this->teamsInLeague($league, ['Flamengo', 'Fluminense', 'Santos']);
        $this->actingAsRole('master');

        $this->getJson('/api/teams?search=flu')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fluminense->id)
            ->assertJsonMissingPath('meta');

        $this->getJson('/api/teams?search=FL&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_sort_and_order_apply_to_the_page(): void
    {
        $league = League::factory()->create();
        $this->teamsInLeague($league, ['Bahia', 'Atlético', 'Coritiba']);
        $this->actingAsRole('master');

        $response = $this->getJson('/api/teams?page=1&sort=name&order=desc');

        $response->assertOk();
        $this->assertSame(['Coritiba', 'Bahia', 'Atlético'], array_column($response->json('data'), 'name'));

        $plain = $this->getJson('/api/teams?sort=name');
        $plain->assertOk()->assertJsonMissingPath('meta');
        $this->assertSame(['Atlético', 'Bahia', 'Coritiba'], array_column($plain->json('data'), 'name'));
    }

    public function test_admin_paginated_list_only_counts_teams_of_his_leagues(): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        $this->teamsInLeague($leagueA, ['Time A1', 'Time A2', 'Time A3']);
        $this->teamsInLeague($leagueB, ['Time B1', 'Time B2']);
        Team::factory()->create(['name' => 'Time sem liga']);
        $this->actingAsRole('admin', [$leagueA->id]);

        $this->getJson('/api/teams?page=1&per_page=2&search=Time')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson("/api/teams?page=1&league_id={$leagueB->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Você não tem acesso a essa liga.');
    }

    public function test_league_filter_works_with_pagination(): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        $this->teamsInLeague($leagueA, ['Time A1', 'Time A2']);
        $this->teamsInLeague($leagueB, ['Time B1']);
        $this->actingAsRole('master');

        $this->getJson("/api/teams?page=1&league_id={$leagueA->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_coach_paginated_list_follows_the_leagues_of_his_team(): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        $this->teamsInLeague($leagueA, ['Time A1']);
        $this->teamsInLeague($leagueB, ['Time B1', 'Time B2']);
        $this->actingAsRole('coach', [$leagueA->id]);

        $this->getJson('/api/teams?page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }
}
