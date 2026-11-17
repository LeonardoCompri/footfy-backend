<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\League;
use App\Models\Player;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicLeagueGamesTest extends TestCase
{
    use RefreshDatabase;

    private function leagueWithTeams(int $teams = 2): array
    {
        $league = League::factory()->create();
        $created = Team::factory()->count($teams)->create();
        $league->teams()->attach($created->pluck('id'));

        return [$league, $created];
    }

    private function game(League $league, array $teams, array $attributes = []): Game
    {
        return Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $teams[0]->id,
            'away_team_id' => $teams[1]->id,
            ...$attributes,
        ]);
    }

    public function test_guest_lists_league_games_paginated_with_default_per_page(): void
    {
        [$league, $teams] = $this->leagueWithTeams();
        foreach (range(1, 25) as $round) {
            $this->game($league, $teams->all(), ['round' => $round]);
        }
        [$other, $otherTeams] = $this->leagueWithTeams();
        $this->game($other, $otherTeams->all());

        $first = $this->getJson("/api/public/leagues/{$league->id}/games");
        $first->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 20, 'total' => 25, 'last_page' => 2]);

        $second = $this->getJson("/api/public/leagues/{$league->id}/games?page=2");
        $second->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.round', 21)
            ->assertJsonPath('data.4.round', 25);
    }

    public function test_items_bring_teams_and_stadium_without_private_data(): void
    {
        [$league, $teams] = $this->leagueWithTeams();
        Player::factory()->create(['team_id' => $teams[0]->id, 'document' => '12345678901']);
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $game = $this->game($league, $teams->all(), ['stadium_id' => $stadium->id]);

        $this->getJson("/api/public/leagues/{$league->id}/games?per_page=5")
            ->assertOk()
            ->assertJsonPath('data.0.id', $game->id)
            ->assertJsonPath('data.0.homeTeam.id', $teams[0]->id)
            ->assertJsonPath('data.0.awayTeam.id', $teams[1]->id)
            ->assertJsonPath('data.0.stadium.id', $stadium->id)
            ->assertJsonMissingPath('data.0.homeTeam.players')
            ->assertJsonMissingPath('data.0.events');
    }

    public function test_items_match_the_admin_games_listing(): void
    {
        [$league, $teams] = $this->leagueWithTeams();
        foreach ([3, 1, 2] as $round) {
            $this->game($league, $teams->all(), ['round' => $round]);
        }
        $public = $this->getJson("/api/public/leagues/{$league->id}/games?page=1&per_page=10")->assertOk()->json();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $master = User::factory()->create();
        $master->assignRole('master');
        Sanctum::actingAs($master);
        $admin = $this->getJson("/api/games?league_id={$league->id}&page=1&per_page=10")->assertOk()->json();

        $this->assertSame($admin, $public);
    }

    public function test_orders_by_round_then_phase_with_nulls_last(): void
    {
        [$league, $teams] = $this->leagueWithTeams();
        $final = $this->game($league, $teams->all(), ['round' => null, 'phase' => 'Final', 'phase_order' => 2]);
        $semi = $this->game($league, $teams->all(), ['round' => null, 'phase' => 'Semifinal', 'phase_order' => 1]);
        $friendly = $this->game($league, $teams->all(), ['round' => null, 'phase' => null, 'phase_order' => null]);
        $round2 = $this->game($league, $teams->all(), ['round' => 2]);
        $round1 = $this->game($league, $teams->all(), ['round' => 1]);

        $ids = array_column($this->getJson("/api/public/leagues/{$league->id}/games")->assertOk()->json('data'), 'id');

        $this->assertSame([$round1->id, $round2->id, $semi->id, $final->id, $friendly->id], $ids);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_pagination_returns_422(string $query, string $field): void
    {
        [$league] = $this->leagueWithTeams();

        $this->getJson("/api/public/leagues/{$league->id}/games?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public static function invalidQueryProvider(): array
    {
        return [
            'per_page acima do máximo' => ['per_page=101', 'per_page'],
            'per_page zero' => ['per_page=0', 'per_page'],
            'página zero' => ['page=0', 'page'],
            'página não numérica' => ['page=abc', 'page'],
        ];
    }

    public function test_per_page_at_the_configured_maximum_is_accepted(): void
    {
        config(['footfy.pagination.max_per_page' => 3]);
        [$league, $teams] = $this->leagueWithTeams();
        foreach (range(1, 4) as $round) {
            $this->game($league, $teams->all(), ['round' => $round]);
        }

        $this->getJson("/api/public/leagues/{$league->id}/games?per_page=3")
            ->assertOk()
            ->assertJsonCount(3, 'data');
        $this->getJson("/api/public/leagues/{$league->id}/games?per_page=4")
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_unknown_league_returns_422(): void
    {
        $this->getJson('/api/public/leagues/999/games')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Liga não encontrada!']);
    }

    public function test_non_numeric_league_returns_404(): void
    {
        $this->getJson('/api/public/leagues/1abc/games')->assertNotFound();
    }

    public function test_league_without_games_returns_empty_page(): void
    {
        [$league] = $this->leagueWithTeams();

        $this->getJson("/api/public/leagues/{$league->id}/games")
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]);
    }

    public function test_does_not_load_the_league_relations(): void
    {
        [$league, $teams] = $this->leagueWithTeams();
        Stadium::factory()->create(['league_id' => $league->id]);
        $this->game($league, $teams->all());

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower(str_replace(['"', '`'], '', $query->sql));
        });

        $this->getJson("/api/public/leagues/{$league->id}/games")->assertOk();

        $sql = implode("\n", $queries);
        $this->assertStringNotContainsString('from championships', $sql);
        $this->assertStringNotContainsString('league_team', $sql);
        $this->assertStringNotContainsString('league_id in (', $sql);
        $this->assertLessThanOrEqual(6, count($queries));
    }

    public function test_public_show_brings_the_counters_and_keeps_games(): void
    {
        [$league, $teams] = $this->leagueWithTeams(3);
        Stadium::factory()->count(2)->create(['league_id' => $league->id]);
        foreach (range(1, 4) as $round) {
            $this->game($league, $teams->all(), ['round' => $round]);
        }
        [$other, $otherTeams] = $this->leagueWithTeams();
        $this->game($other, $otherTeams->all());

        $response = $this->getJson("/api/public/leagues/{$league->id}");

        $response->assertOk()
            ->assertJsonPath('data.teams_count', 3)
            ->assertJsonPath('data.games_count', 4)
            ->assertJsonPath('data.stadiums_count', 2)
            ->assertJsonCount(4, 'data.games')
            ->assertJsonCount(3, 'data.teams')
            ->assertJsonCount(2, 'data.stadiums');
        $this->assertSame(
            $response->json('data.games_count'),
            $this->getJson("/api/public/leagues/{$league->id}/games")->json('meta.total'),
        );
    }

    public function test_public_show_of_empty_league_has_zero_counters(): void
    {
        $league = League::factory()->create();

        $this->getJson("/api/public/leagues/{$league->id}")
            ->assertOk()
            ->assertJsonPath('data.teams_count', 0)
            ->assertJsonPath('data.games_count', 0)
            ->assertJsonPath('data.stadiums_count', 0);
    }

    public function test_admin_show_does_not_bring_the_counters(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $master = User::factory()->create();
        $master->assignRole('master');
        Sanctum::actingAs($master);
        [$league] = $this->leagueWithTeams();

        $this->getJson("/api/leagues/{$league->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.teams_count')
            ->assertJsonMissingPath('data.games_count')
            ->assertJsonMissingPath('data.stadiums_count');
    }
}
