<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Team;
use App\Models\TechnicalCommitteeMember;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class TechnicalCommitteeMemberIndexTest extends TestCase
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

    private function teamInLeague(?League $league = null): Team
    {
        $team = Team::factory()->create();
        $team->leagues()->attach(($league ?? League::factory()->create())->id);

        return $team;
    }

    public function test_index_without_page_keeps_the_plain_list(): void
    {
        TechnicalCommitteeMember::factory()->count(3)->create(['team_id' => $this->teamInLeague()->id]);
        $this->actingAsRole('master');

        $this->getJson('/api/technical-committee-members')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta');
    }

    public function test_index_with_page_returns_data_and_meta(): void
    {
        $members = TechnicalCommitteeMember::factory()->count(7)->create(['team_id' => $this->teamInLeague()->id]);
        $this->actingAsRole('master');

        $this->getJson('/api/technical-committee-members?page=2&per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta', ['current_page' => 2, 'per_page' => 3, 'total' => 7, 'last_page' => 3])
            ->assertJsonPath('data.0.id', $members[3]->id);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_listing_parameters_return_422(string $query, string $field): void
    {
        $this->actingAsRole('master');

        $this->getJson("/api/technical-committee-members?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public static function invalidQueryProvider(): array
    {
        return [
            'per_page acima do máximo' => ['page=1&per_page=101', 'per_page'],
            'coluna fora da lista' => ['sort=team_id', 'sort'],
            'direção inválida' => ['order=ASC', 'order'],
            'time como array' => ['team_id[]=1', 'team_id'],
        ];
    }

    public function test_search_by_name_and_sort_by_role(): void
    {
        $team = $this->teamInLeague();
        TechnicalCommitteeMember::factory()->create(['team_id' => $team->id, 'name' => 'Ana Souza', 'role' => 'Treinador']);
        TechnicalCommitteeMember::factory()->create(['team_id' => $team->id, 'name' => 'Bruno Souza', 'role' => 'Auxiliar Técnico']);
        TechnicalCommitteeMember::factory()->create(['team_id' => $team->id, 'name' => 'Carla Lima', 'role' => 'Preparador Físico']);
        $this->actingAsRole('master');

        $response = $this->getJson('/api/technical-committee-members?page=1&search=souza&sort=role');

        $response->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame(['Bruno Souza', 'Ana Souza'], array_column($response->json('data'), 'name'));
    }

    public function test_team_filter(): void
    {
        $league = League::factory()->create();
        $team = $this->teamInLeague($league);
        TechnicalCommitteeMember::factory()->count(2)->create(['team_id' => $team->id]);
        TechnicalCommitteeMember::factory()->create(['team_id' => $this->teamInLeague($league)->id]);
        $this->actingAsRole('admin', [$league->id]);

        $this->getJson("/api/technical-committee-members?team_id={$team->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/technical-committee-members?team_id={$team->id}&page=1")->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_team_filter_outside_the_users_leagues_returns_422(): void
    {
        $outside = $this->teamInLeague();
        $this->actingAsRole('admin', [League::factory()->create()->id]);

        $this->getJson("/api/technical-committee-members?team_id={$outside->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Você não tem acesso a esse time.');
    }

    public function test_admin_paginated_list_only_counts_members_of_his_leagues(): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        TechnicalCommitteeMember::factory()->count(3)->create(['team_id' => $this->teamInLeague($leagueA)->id]);
        TechnicalCommitteeMember::factory()->count(2)->create(['team_id' => $this->teamInLeague($leagueB)->id]);
        $this->actingAsRole('admin', [$leagueA->id]);

        $this->getJson('/api/technical-committee-members?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_coach_paginated_list_follows_the_leagues_of_his_team(): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        TechnicalCommitteeMember::factory()->create(['team_id' => $this->teamInLeague($leagueA)->id]);
        TechnicalCommitteeMember::factory()->count(2)->create(['team_id' => $this->teamInLeague($leagueB)->id]);
        $this->actingAsRole('coach', [$leagueA->id]);

        $this->getJson('/api/technical-committee-members?page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }
}
