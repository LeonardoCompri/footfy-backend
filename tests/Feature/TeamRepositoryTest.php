<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Team;
use App\Repositories\TeamRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private TeamRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new TeamRepository;
    }

    public function test_index_filters_by_leagues_without_duplicates(): void
    {
        [$leagueA, $leagueB, $leagueC] = League::factory()->count(3)->create();
        $inBoth = Team::factory()->create();
        $inBoth->leagues()->attach([$leagueA->id, $leagueB->id]);
        $onlyB = Team::factory()->create();
        $onlyB->leagues()->attach($leagueB->id);
        $otherLeague = Team::factory()->create();
        $otherLeague->leagues()->attach($leagueC->id);
        Team::factory()->create();

        $ids = $this->repository->index([$leagueA->id, $leagueB->id])->pluck('id')->sort()->values()->all();

        $this->assertSame([$inBoth->id, $onlyB->id], $ids);
    }

    public function test_index_without_filter_returns_all_teams(): void
    {
        $league = League::factory()->create();
        Team::factory()->create()->leagues()->attach($league->id);
        Team::factory()->create();

        $this->assertCount(2, $this->repository->index());
    }

    public function test_index_with_empty_leagues_returns_no_team(): void
    {
        $league = League::factory()->create();
        Team::factory()->create()->leagues()->attach($league->id);

        $this->assertCount(0, $this->repository->index([]));
    }

    public function test_index_filters_with_league_team_subquery_instead_of_exists(): void
    {
        $league = League::factory()->create();
        Team::factory()->create()->leagues()->attach($league->id);

        DB::enableQueryLog();
        $this->repository->index([$league->id]);
        $sql = strtolower(DB::getQueryLog()[0]['query']);

        $this->assertStringContainsString('"teams"."id" in (select "team_id" from "league_team"', $sql);
        $this->assertStringNotContainsString('exists', $sql);
    }
}
