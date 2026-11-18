<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class PlayerIndexTest extends TestCase
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

    private function names(array $data): array
    {
        return array_column($data, 'name');
    }

    public function test_index_without_page_keeps_the_plain_list(): void
    {
        Player::factory()->count(3)->create(['team_id' => $this->teamInLeague()->id]);
        $this->actingAsRole('master');

        $this->getJson('/api/players')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta');
    }

    public function test_index_with_page_returns_data_and_meta(): void
    {
        $team = $this->teamInLeague();
        $players = Player::factory()->count(12)->create(['team_id' => $team->id]);
        $this->actingAsRole('master');

        $this->getJson('/api/players?page=3&per_page=5')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta', ['current_page' => 3, 'per_page' => 5, 'total' => 12, 'last_page' => 3])
            ->assertJsonPath('data.0.id', $players[10]->id);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_listing_parameters_return_422(string $query, string $field): void
    {
        $this->actingAsRole('master');

        $this->getJson("/api/players?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public static function invalidQueryProvider(): array
    {
        return [
            'per_page acima do máximo' => ['page=1&per_page=101', 'per_page'],
            'per_page sem página também é validado' => ['per_page=500', 'per_page'],
            'ordenar por CPF não é permitido' => ['sort=document', 'sort'],
            'coluna inexistente' => ['sort=team_id;drop', 'sort'],
            'direção inválida' => ['order=random', 'order'],
            'time não numérico' => ['team_id=1abc', 'team_id'],
            'busca longa demais' => ['search='.str_repeat('a', 101), 'search'],
            'liga não numérica' => ['league_id=abc', 'league_id'],
            'liga zero' => ['league_id=0', 'league_id'],
            'liga com sufixo' => ['league_id=1abc', 'league_id'],
            'liga como lista' => ['league_id[]=1', 'league_id'],
        ];
    }

    public function test_search_by_name(): void
    {
        $team = $this->teamInLeague();
        Player::factory()->create(['team_id' => $team->id, 'name' => 'Gabriel Barbosa']);
        Player::factory()->create(['team_id' => $team->id, 'name' => 'Pedro Guilherme']);
        $this->actingAsRole('master');

        $response = $this->getJson('/api/players?page=1&search=barbosa');

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame(['Gabriel Barbosa'], $this->names($response->json('data')));
    }

    #[DataProvider('documentSearchProvider')]
    public function test_search_by_document_follows_the_users_list_rule(string $term): void
    {
        $team = $this->teamInLeague();
        Player::factory()->create(['team_id' => $team->id, 'name' => 'Carlos Cpf', 'document' => '12345678901']);
        Player::factory()->create(['team_id' => $team->id, 'name' => 'Outro', 'document' => '98765432100']);
        $this->actingAsRole('master');

        $response = $this->getJson('/api/players?search='.urlencode($term));

        $response->assertOk();
        $this->assertSame(['Carlos Cpf'], $this->names($response->json('data')));
    }

    public static function documentSearchProvider(): array
    {
        return [
            'CPF cru' => ['12345678901'],
            'CPF formatado' => ['123.456.789-01'],
            'parte do CPF formatada' => ['456.789'],
            'com espaços' => ['123 456'],
        ];
    }

    public function test_term_with_letters_does_not_match_document_digits(): void
    {
        $team = $this->teamInLeague();
        Player::factory()->create(['team_id' => $team->id, 'name' => 'Joao2', 'document' => '11111111111']);
        Player::factory()->create(['team_id' => $team->id, 'name' => 'Pedro', 'document' => '22222222222']);
        $this->actingAsRole('master');

        $response = $this->getJson('/api/players?search=joao2');
        $this->assertSame(['Joao2'], $this->names($response->json('data')));

        $this->getJson('/api/players?search=a2')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_punctuation_only_term_matches_nothing(): void
    {
        $team = $this->teamInLeague();
        Player::factory()->create(['team_id' => $team->id, 'name' => 'Carlos', 'document' => '12345678901']);
        $this->actingAsRole('master');

        $this->getJson('/api/players?search='.urlencode('..-'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_team_filter(): void
    {
        $league = League::factory()->create();
        $team = $this->teamInLeague($league);
        $other = $this->teamInLeague($league);
        Player::factory()->count(2)->create(['team_id' => $team->id]);
        Player::factory()->create(['team_id' => $other->id]);
        $this->actingAsRole('admin', [$league->id]);

        $this->getJson("/api/players?team_id={$team->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/players?team_id={$team->id}&page=1")->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_team_filter_outside_the_users_leagues_returns_422(): void
    {
        $league = League::factory()->create();
        $outside = $this->teamInLeague();
        Player::factory()->create(['team_id' => $outside->id]);
        $this->actingAsRole('admin', [$league->id]);

        $this->getJson("/api/players?team_id={$outside->id}&page=1")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Você não tem acesso a esse time.');
    }

    public function test_sort_and_order_apply_to_the_page(): void
    {
        $team = $this->teamInLeague();
        Player::factory()->create(['team_id' => $team->id, 'name' => 'Camisa 7', 'number' => 7]);
        Player::factory()->create(['team_id' => $team->id, 'name' => 'Camisa 10', 'number' => 10]);
        Player::factory()->create(['team_id' => $team->id, 'name' => 'Camisa 1', 'number' => 1]);
        $this->actingAsRole('master');

        $response = $this->getJson('/api/players?page=1&sort=number&order=desc');

        $this->assertSame(['Camisa 10', 'Camisa 7', 'Camisa 1'], $this->names($response->json('data')));
    }

    public function test_admin_paginated_list_respects_his_leagues_and_skips_removed_players(): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        $teamA = $this->teamInLeague($leagueA);
        Player::factory()->count(3)->create(['team_id' => $teamA->id]);
        Player::factory()->create(['team_id' => $teamA->id])->delete();
        Player::factory()->count(4)->create(['team_id' => $this->teamInLeague($leagueB)->id]);
        Player::factory()->create(['team_id' => null]);
        $this->actingAsRole('admin', [$leagueA->id]);

        $this->getJson('/api/players?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_coach_paginated_list_follows_the_leagues_of_his_team(): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        Player::factory()->count(2)->create(['team_id' => $this->teamInLeague($leagueA)->id]);
        Player::factory()->count(3)->create(['team_id' => $this->teamInLeague($leagueB)->id]);
        $this->actingAsRole('coach', [$leagueA->id]);

        $this->getJson('/api/players?page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_player_role_paginated_list_includes_his_own_team(): void
    {
        $league = League::factory()->create();
        Player::factory()->count(2)->create(['team_id' => $this->teamInLeague($league)->id]);
        Player::factory()->create(['team_id' => $this->teamInLeague()->id]);
        $this->actingAsRole('player', [$league->id]);

        $this->getJson('/api/players?page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_master_league_filter_with_and_without_page(): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        Player::factory()->count(3)->create(['team_id' => $this->teamInLeague($leagueA)->id]);
        Player::factory()->count(2)->create(['team_id' => $this->teamInLeague($leagueB)->id]);
        Player::factory()->create(['team_id' => null]);
        $this->actingAsRole('master');

        $this->getJson("/api/players?league_id={$leagueA->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta');

        $this->getJson("/api/players?league_id={$leagueB->id}&page=1&per_page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/players')->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_league_filter_lists_a_team_in_several_leagues_once(): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        $team = $this->teamInLeague($leagueA);
        $team->leagues()->attach($leagueB->id);
        Player::factory()->count(2)->create(['team_id' => $team->id]);
        $this->actingAsRole('admin', [$leagueA->id, $leagueB->id]);

        $this->getJson("/api/players?league_id={$leagueA->id}&page=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_admin_league_filter_keeps_only_the_requested_league(): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        $teamA = $this->teamInLeague($leagueA);
        Player::factory()->create(['team_id' => $teamA->id, 'name' => 'Jogador A']);
        Player::factory()->create(['team_id' => $teamA->id, 'name' => 'Removido A'])->delete();
        Player::factory()->create(['team_id' => $this->teamInLeague($leagueB)->id, 'name' => 'Jogador B']);
        $this->actingAsRole('admin', [$leagueA->id, $leagueB->id]);

        $response = $this->getJson("/api/players?league_id={$leagueA->id}&page=1");

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame(['Jogador A'], $this->names($response->json('data')));
        $this->assertSame(['Jogador B'], $this->names($this->getJson("/api/players?league_id={$leagueB->id}")->json('data')));
        $this->getJson('/api/players?page=1')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_league_filter_combines_with_search(): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        $teamA = $this->teamInLeague($leagueA);
        Player::factory()->create(['team_id' => $teamA->id, 'name' => 'Gabriel Barbosa']);
        Player::factory()->create(['team_id' => $teamA->id, 'name' => 'Pedro Guilherme']);
        Player::factory()->create(['team_id' => $this->teamInLeague($leagueB)->id, 'name' => 'Diego Barbosa']);
        $this->actingAsRole('admin', [$leagueA->id, $leagueB->id]);

        $paginated = $this->getJson("/api/players?league_id={$leagueA->id}&search=barbosa&page=1");
        $plain = $this->getJson("/api/players?league_id={$leagueA->id}&search=barbosa");

        $paginated->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame(['Gabriel Barbosa'], $this->names($paginated->json('data')));
        $this->assertSame(['Gabriel Barbosa'], $this->names($plain->json('data')));
    }

    public function test_league_filter_combines_with_team_filter(): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        $teamA = $this->teamInLeague($leagueA);
        $otherA = $this->teamInLeague($leagueA);
        $teamB = $this->teamInLeague($leagueB);
        Player::factory()->count(2)->create(['team_id' => $teamA->id]);
        Player::factory()->create(['team_id' => $otherA->id]);
        Player::factory()->create(['team_id' => $teamB->id]);
        $this->actingAsRole('admin', [$leagueA->id, $leagueB->id]);

        $this->getJson("/api/players?league_id={$leagueA->id}&team_id={$teamA->id}&page=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson("/api/players?league_id={$leagueA->id}&team_id={$teamB->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[DataProvider('scopedRoleProvider')]
    public function test_league_filter_outside_the_users_leagues_returns_422(string $role): void
    {
        [$leagueA, $leagueB] = League::factory()->count(2)->create();
        Player::factory()->create(['team_id' => $this->teamInLeague($leagueB)->id]);
        $this->actingAsRole($role, [$leagueA->id]);

        $this->getJson("/api/players?league_id={$leagueB->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Você não tem acesso a essa liga.');

        $this->getJson("/api/players?league_id={$leagueB->id}&page=1")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Você não tem acesso a essa liga.');
    }

    public static function scopedRoleProvider(): array
    {
        return [
            'admin' => ['admin'],
            'coach' => ['coach'],
            'player' => ['player'],
        ];
    }

    public function test_coach_league_filter_uses_the_leagues_of_his_team(): void
    {
        $league = League::factory()->create();
        Player::factory()->count(2)->create(['team_id' => $this->teamInLeague($league)->id]);
        Player::factory()->create(['team_id' => $this->teamInLeague()->id]);
        $this->actingAsRole('coach', [$league->id]);

        $this->getJson("/api/players?league_id={$league->id}&page=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_master_league_filter_with_unknown_league_returns_empty_list(): void
    {
        Player::factory()->create(['team_id' => $this->teamInLeague()->id]);
        $this->actingAsRole('master');

        $this->getJson('/api/players?league_id=999999')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/players?league_id=999999&page=1')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_empty_league_filter_keeps_the_full_list(): void
    {
        Player::factory()->count(2)->create(['team_id' => $this->teamInLeague()->id]);
        $this->actingAsRole('master');

        $this->getJson('/api/players?league_id=')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
