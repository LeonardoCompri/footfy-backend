<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Championship;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GameEventType;
use App\Models\League;
use App\Models\Player;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\User;
use App\Services\ChampionshipService;
use App\Services\GameEventService;
use App\Services\GameEventTypeService;
use App\Services\GameService;
use App\Services\LeagueCacheVersion;
use App\Services\LeagueDrawService;
use App\Services\LeagueGroupService;
use App\Services\LeagueKnockoutService;
use App\Services\LeagueService;
use App\Services\PlayerService;
use App\Services\StadiumService;
use App\Services\TeamService;
use Closure;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LeagueCacheVersionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Championship $championship;

    private Championship $otherChampionship;

    private Championship $untouchedChampionship;

    private League $league;

    private League $otherLeague;

    private League $untouchedLeague;

    private Team $home;

    private Team $away;

    private Game $game;

    private Game $otherGame;

    private Player $player;

    private GameEvent $event;

    private Stadium $stadium;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->user = User::factory()->create();
        $this->user->assignRole('master');
        Sanctum::actingAs($this->user);

        $this->championship = Championship::factory()->create();
        $this->otherChampionship = Championship::factory()->create();
        $this->untouchedChampionship = Championship::factory()->create();
        $this->league = League::factory()->create(['championship_id' => $this->championship->id, 'format' => 'round_robin']);
        $this->otherLeague = League::factory()->create(['championship_id' => $this->otherChampionship->id, 'format' => 'round_robin']);
        $this->untouchedLeague = League::factory()->create(['championship_id' => $this->untouchedChampionship->id, 'format' => 'round_robin']);

        [$this->home, $this->away] = Team::factory()->count(2)->create();
        $this->league->teams()->attach([$this->home->id, $this->away->id]);
        $this->game = $this->game($this->league, ['round' => 1, 'status' => 'finished', 'home_score' => 1, 'away_score' => 0]);
        $this->otherGame = $this->game($this->otherLeague, ['round' => 1]);
        $this->player = Player::factory()->create(['team_id' => $this->home->id, 'name' => 'Jogador Original']);
        $this->event = GameEvent::create([
            'game_id' => $this->game->id,
            'team_id' => $this->home->id,
            'player_id' => $this->player->id,
            'league_id' => $this->league->id,
            'type' => 'goal',
            'minute' => 10,
        ]);
        $this->stadium = Stadium::factory()->create(['league_id' => $this->league->id]);
    }

    private function game(League $league, array $attributes = []): Game
    {
        return Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $this->home->id,
            'away_team_id' => $this->away->id,
            ...$attributes,
        ]);
    }

    private function versions(): LeagueCacheVersion
    {
        return app(LeagueCacheVersion::class);
    }

    private function snapshot(): array
    {
        $leagues = [
            'league' => $this->league,
            'other_league' => $this->otherLeague,
            'untouched_league' => $this->untouchedLeague,
        ];
        $championships = [
            'championship' => $this->championship,
            'other_championship' => $this->otherChampionship,
            'untouched_championship' => $this->untouchedChampionship,
        ];

        return [
            ...array_map(fn (League $league) => $this->versions()->key($league->id, 'table'), $leagues),
            ...array_map(fn (Championship $championship) => $this->versions()->championshipKey($championship->id, 'public_show'), $championships),
            'championships' => $this->versions()->championshipsKey('public_index'),
        ];
    }

    private function drawLeague(int $groupsCount = 1, int $teams = 4): League
    {
        $league = League::factory()->create([
            'format' => 'hybrid',
            'fixture_mode' => 'draw',
            'groups_count' => $groupsCount,
            'group_legs' => 1,
            'qualifiers_per_group' => 2,
            'knockout_size' => 2 * $groupsCount,
            'knockout_legs' => 1,
            'final_legs' => 1,
            'third_place' => false,
            'knockout_tiebreakers' => ['penalties'],
        ]);

        foreach (Team::factory()->count($teams)->create() as $index => $team) {
            $league->teams()->attach($team->id, ['group_number' => $groupsCount === 1 ? 1 : ($index % $groupsCount) + 1]);
        }

        return $league;
    }

    private function knockoutReadyLeague(): League
    {
        $league = $this->drawLeague();
        $draw = app(LeagueDrawService::class);
        $state = $draw->build($league);

        while ($state['next_round'] !== null) {
            $state = $draw->drawNextRound((string) $league->id, $state['next_round']);
        }

        foreach (Game::where('league_id', $league->id)->get() as $game) {
            $game->update(['status' => 'finished', 'home_score' => 1 + ($game->id % 3), 'away_score' => 0]);
        }

        return $league;
    }

    public static function writes(): array
    {
        return [
            'game store' => [fn (self $t) => fn () => app(GameService::class)->store([
                'league_id' => $t->league->id,
                'home_team_id' => $t->home->id,
                'away_team_id' => $t->away->id,
                'round' => 2,
                'status' => 'scheduled',
            ]), ['league']],
            'game update' => [fn (self $t) => fn () => app(GameService::class)->update(['status' => 'in_progress'], (string) $t->game->id), ['league']],
            'game update moving league' => [fn (self $t) => fn () => app(GameService::class)->update(['league_id' => $t->otherLeague->id], (string) $t->game->id), ['league', 'other_league']],
            'game penalty kicks' => [fn (self $t) => fn () => app(GameService::class)->updatePenaltyKicks([], (string) $t->game->id), ['league']],
            'game destroy' => [function (self $t) {
                $game = $t->game($t->league, ['round' => null]);

                return fn () => app(GameService::class)->destroy((string) $game->id);
            }, ['league']],
            'event store' => [fn (self $t) => fn () => app(GameEventService::class)->store([
                'game_id' => $t->game->id,
                'team_id' => $t->home->id,
                'player_id' => $t->player->id,
                'type' => 'goal',
                'minute' => 20,
            ]), ['league']],
            'event update' => [fn (self $t) => fn () => app(GameEventService::class)->update(['minute' => 30], (string) $t->event->id), ['league']],
            'event update moving game' => [fn (self $t) => fn () => app(GameEventService::class)->update(['game_id' => $t->otherGame->id], (string) $t->event->id), ['league', 'other_league']],
            'event destroy' => [fn (self $t) => fn () => app(GameEventService::class)->destroy((string) $t->event->id), ['league']],
            'league store' => [fn (self $t) => fn () => app(LeagueService::class)->store([
                ...League::factory()->raw(),
                'championship_id' => $t->championship->id,
            ]), ['championship', 'championships']],
            'league update' => [fn (self $t) => fn () => app(LeagueService::class)->update(['name' => 'Nova Liga'], (string) $t->league->id), ['league', 'championship', 'championships']],
            'league update moving championship' => [fn (self $t) => fn () => app(LeagueService::class)->update(['championship_id' => $t->otherChampionship->id], (string) $t->league->id), ['league', 'championship', 'other_championship', 'championships']],
            'league destroy' => [fn (self $t) => fn () => app(LeagueService::class)->destroy((string) $t->league->id), ['league', 'championship', 'championships']],
            'groups assign' => [function (self $t) {
                $league = $t->drawLeague(groupsCount: 2, teams: 6);
                $assignments = $league->teams()->pluck('teams.id')->values()
                    ->map(fn ($id, $index) => ['team_id' => $id, 'group_number' => $index < 3 ? 1 : 2])->all();

                return [fn () => app(LeagueGroupService::class)->assign((string) $league->id, $assignments), $league];
            }, []],
            'groups shuffle' => [function (self $t) {
                $league = $t->drawLeague(groupsCount: 2, teams: 6);

                return [fn () => app(LeagueGroupService::class)->shuffle((string) $league->id), $league];
            }, []],
            'draw next round' => [function (self $t) {
                $league = $t->drawLeague();

                return [fn () => app(LeagueDrawService::class)->drawNextRound((string) $league->id, 1), $league];
            }, []],
            'draw undo last round' => [function (self $t) {
                $league = $t->drawLeague();
                app(LeagueDrawService::class)->drawNextRound((string) $league->id, 1);

                return [fn () => app(LeagueDrawService::class)->undoLastRound((string) $league->id, 1), $league];
            }, []],
            'draw reset' => [function (self $t) {
                $league = $t->drawLeague();
                app(LeagueDrawService::class)->drawNextRound((string) $league->id, 1);

                return [fn () => app(LeagueDrawService::class)->reset((string) $league->id), $league];
            }, []],
            'knockout generate' => [function (self $t) {
                $league = $t->knockoutReadyLeague();

                return [fn () => app(LeagueKnockoutService::class)->generateNextStage((string) $league->id, 'F'), $league];
            }, []],
            'knockout undo' => [function (self $t) {
                $league = $t->knockoutReadyLeague();
                app(LeagueKnockoutService::class)->generateNextStage((string) $league->id, 'F');

                return [fn () => app(LeagueKnockoutService::class)->undoLastStage((string) $league->id, 'F'), $league];
            }, []],
            'stadium store' => [fn (self $t) => fn () => app(StadiumService::class)->store(Stadium::factory()->raw(['league_id' => $t->league->id])), ['league']],
            'stadium update moving league' => [fn (self $t) => fn () => app(StadiumService::class)->update(['league_id' => $t->otherLeague->id], (string) $t->stadium->id), ['league', 'other_league']],
            'stadium destroy' => [fn (self $t) => fn () => app(StadiumService::class)->destroy((string) $t->stadium->id), ['league']],
            'team store with leagues' => [fn (self $t) => fn () => app(TeamService::class)->store([...Team::factory()->raw(), 'league_ids' => [$t->otherLeague->id]]), ['other_league']],
            'team update' => [fn (self $t) => fn () => app(TeamService::class)->update(['name' => 'Novo Nome'], (string) $t->home->id), ['league', 'other_league']],
            'team destroy' => [function (self $t) {
                $team = Team::factory()->create();
                $t->otherLeague->teams()->attach($team->id);

                return fn () => app(TeamService::class)->destroy((string) $team->id);
            }, ['other_league']],
            'team sync leagues' => [fn (self $t) => fn () => app(TeamService::class)->syncLeagues((string) $t->home->id, [$t->otherLeague->id]), ['other_league']],
            'team remove league' => [fn (self $t) => fn () => app(TeamService::class)->removeLeague((string) $t->home->id, (string) $t->league->id), ['league']],
            'player update' => [fn (self $t) => fn () => app(PlayerService::class)->update(['name' => 'Outro Nome'], (string) $t->player->id), ['league']],
            'player update own player' => [function (self $t) {
                $t->player->forceFill(['user_id' => $t->user->id])->save();

                return fn () => app(PlayerService::class)->updateOwnPlayer(['name' => 'Meu Nome']);
            }, ['league']],
            'player destroy' => [fn (self $t) => fn () => app(PlayerService::class)->destroy((string) $t->player->id), ['league']],
            'player restore' => [function (self $t) {
                $t->player->delete();

                return fn () => app(PlayerService::class)->store([
                    'team_id' => $t->home->id,
                    'name' => 'Jogador Restaurado',
                    'document' => $t->player->document,
                ]);
            }, ['league']],
            'event type store' => [fn () => fn () => app(GameEventTypeService::class)->store(GameEventType::factory()->raw()), ['*']],
            'event type update' => [fn () => fn () => app(GameEventTypeService::class)->update(['name' => 'Gol de placa'], (string) GameEventType::where('slug', 'goal')->value('id')), ['*']],
            'event type destroy' => [function () {
                $type = GameEventType::factory()->create();

                return fn () => app(GameEventTypeService::class)->destroy((string) $type->id);
            }, ['*']],
            'championship store' => [fn () => fn () => app(ChampionshipService::class)->store(['name' => 'Copa Nova']), ['championships']],
            'championship update' => [fn (self $t) => fn () => app(ChampionshipService::class)->update(['name' => 'Copa Renomeada'], (string) $t->championship->id), ['championship', 'championships', 'league']],
            'championship destroy' => [fn (self $t) => fn () => app(ChampionshipService::class)->destroy((string) $t->championship->id), ['championship', 'championships', 'league']],
        ];
    }

    #[DataProvider('writes')]
    public function test_each_write_bumps_only_the_affected_versions(Closure $prepare, array $expected): void
    {
        $prepared = $prepare($this);
        [$write, $extraLeague] = is_array($prepared) ? $prepared : [$prepared, null];
        $before = $this->snapshot();
        $extraBefore = $extraLeague ? $this->versions()->key($extraLeague->id, 'table') : null;

        $write();

        $after = $this->snapshot();

        foreach ($before as $name => $key) {
            $changed = $expected === ['*'] || in_array($name, $expected, true);
            $this->assertSame($changed, $key !== $after[$name], sprintf('%s %s', $name, $changed ? 'deveria mudar' : 'não deveria mudar'));
        }

        if ($extraLeague) {
            $this->assertNotSame($extraBefore, $this->versions()->key($extraLeague->id, 'table'));
        }
    }

    public function test_table_and_statistics_change_after_an_event_is_posted(): void
    {
        $table = $this->getJson("/api/public/leagues/{$this->league->id}/table")->assertOk();
        $statistics = $this->getJson("/api/public/leagues/{$this->league->id}/statistics")->assertOk();
        $this->assertSame(1, $statistics->json('data.top_scorers.0.goals'));

        $this->postJson('/api/game-events', [
            'game_id' => $this->game->id,
            'team_id' => $this->home->id,
            'player_id' => $this->player->id,
            'type' => 'goal',
            'minute' => 50,
        ])->assertCreated();

        $this->assertNotSame($table->getContent(), $this->getJson("/api/public/leagues/{$this->league->id}/table")->getContent());
        $this->assertSame(2, $this->getJson("/api/public/leagues/{$this->league->id}/statistics")->json('data.top_scorers.0.goals'));
        $this->assertSame(2, $this->getJson("/api/leagues/{$this->league->id}/statistics")->json('data.top_scorers.0.goals'));
    }

    public function test_second_call_is_served_from_the_cache(): void
    {
        $first = $this->getJson("/api/public/leagues/{$this->league->id}/statistics")->assertOk();

        DB::table('players')->where('id', $this->player->id)->update(['name' => 'Mudou Sem Service']);

        $this->assertSame($first->getContent(), $this->getJson("/api/public/leagues/{$this->league->id}/statistics")->getContent());
    }

    public function test_admin_and_public_routes_share_the_same_entry(): void
    {
        $routes = [
            'table' => ['/api/leagues/%d/table', '/api/public/leagues/%d/table'],
            'statistics' => ['/api/leagues/%d/statistics', '/api/public/leagues/%d/statistics'],
        ];

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        foreach ($routes as $name => [$admin, $public]) {
            $adminResponse = $this->getJson(sprintf($admin, $this->league->id))->assertOk();
            $this->assertTrue(Cache::has($this->versions()->key($this->league->id, $name)));

            $queries = 0;
            $publicResponse = $this->getJson(sprintf($public, $this->league->id))->assertOk();

            $this->assertSame($adminResponse->getContent(), $publicResponse->getContent());
            $this->assertSame(1, $queries, $name);
        }
    }

    public function test_rolled_back_write_keeps_the_version_and_the_cached_response(): void
    {
        $table = $this->getJson("/api/public/leagues/{$this->league->id}/table")->assertOk();
        $key = $this->versions()->key($this->league->id, 'table');

        AuditLog::created(function (AuditLog $log) {
            if ($log->action === 'game_event.created') {
                throw new RuntimeException('falha simulada');
            }
        });

        $this->postJson('/api/game-events', [
            'game_id' => $this->game->id,
            'team_id' => $this->home->id,
            'player_id' => $this->player->id,
            'type' => 'goal',
            'minute' => 50,
        ])->assertStatus(500);

        $this->assertSame($key, $this->versions()->key($this->league->id, 'table'));
        $this->assertSame(1, GameEvent::count());
        $this->assertSame($table->getContent(), $this->getJson("/api/public/leagues/{$this->league->id}/table")->getContent());
    }

    public function test_bump_inside_a_rolled_back_transaction_does_nothing(): void
    {
        $key = $this->versions()->key($this->league->id, 'table');

        try {
            DB::transaction(function () {
                $this->versions()->bump($this->league->id);
                $this->versions()->bumpChampionship($this->championship->id);
                $this->versions()->bumpAll();

                throw new RuntimeException('falha simulada');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame($key, $this->versions()->key($this->league->id, 'table'));

        DB::transaction(fn () => $this->versions()->bump($this->league->id));

        $this->assertNotSame($key, $this->versions()->key($this->league->id, 'table'));
    }

    public function test_key_written_by_a_request_that_overlapped_a_cache_clear_never_becomes_current_again(): void
    {
        $versions = $this->versions();
        $versions->bump($this->league->id);
        $versions->bump($this->league->id);
        $staleKey = $versions->key($this->league->id, 'table');

        $this->travel(5)->seconds();
        Artisan::call('cache:clear');

        Cache::put($staleKey, ['dado' => 'antigo'], 600);

        $versions->bump($this->league->id);
        $versions->bump($this->league->id);

        $this->assertSame(2, (int) Cache::get($versions->leagueCounter($this->league->id)));
        $this->assertNotSame($staleKey, $versions->key($this->league->id, 'table'));
        $this->assertNotSame(['dado' => 'antigo'], $this->getJson("/api/public/leagues/{$this->league->id}/table")->json());
    }

    public function test_reading_does_not_create_counters_for_unknown_leagues(): void
    {
        $this->getJson('/api/public/leagues/999999')->assertStatus(422);
        $this->getJson('/api/public/leagues/999999/table')->assertStatus(422);

        $this->assertNull(Cache::get($this->versions()->leagueCounter(999999)));
        $this->assertFalse(Cache::has($this->versions()->key(999999, 'public_show')));
    }

    public function test_zero_ttl_disables_the_cache(): void
    {
        config(['footfy.league_cache_ttl' => 0]);

        $this->getJson("/api/public/leagues/{$this->league->id}/statistics")->assertOk();
        DB::table('players')->where('id', $this->player->id)->update(['name' => 'Mudou Sem Service']);

        $this->assertSame('Mudou Sem Service', $this->getJson("/api/public/leagues/{$this->league->id}/statistics")->json('data.top_scorers.0.player_name'));
    }

    public function test_public_league_and_championship_follow_their_versions(): void
    {
        $this->getJson("/api/public/leagues/{$this->league->id}")->assertJsonPath('data.name', $this->league->name);
        $this->getJson("/api/public/championships/{$this->championship->id}")->assertJsonPath('data.leagues.0.name', $this->league->name);

        app(LeagueService::class)->update(['name' => 'Série Nova'], (string) $this->league->id);

        $this->getJson("/api/public/leagues/{$this->league->id}")->assertJsonPath('data.name', 'Série Nova');
        $this->getJson("/api/public/championships/{$this->championship->id}")->assertJsonPath('data.leagues.0.name', 'Série Nova');

        app(ChampionshipService::class)->update(['name' => 'Copa Nova'], (string) $this->championship->id);

        $this->getJson("/api/public/leagues/{$this->league->id}")->assertJsonPath('data.championship.name', 'Copa Nova');
    }

    public function test_only_the_first_page_without_search_of_public_championships_is_cached(): void
    {
        DB::table('championships')->where('id', $this->untouchedChampionship->id)->update(['name' => 'Copa Sem Service']);

        $firstPage = $this->getJson('/api/public/championships?page=1&per_page=2&sort=id&order=asc')->json('data');
        $cachedFirstPage = $this->getJson('/api/public/championships?page=1&per_page=2&sort=id&order=asc')->json('data');
        $this->assertSame($firstPage, $cachedFirstPage);

        DB::table('championships')->where('id', $this->championship->id)->update(['name' => 'Outro Nome']);

        $this->assertSame($cachedFirstPage, $this->getJson('/api/public/championships?page=1&per_page=2&sort=id&order=asc')->json('data'));
        $this->assertSame('Outro Nome', $this->getJson('/api/public/championships?page=1&search=Outro')->json('data.0.name'));
        $this->assertSame('Copa Sem Service', $this->getJson('/api/public/championships?page=2&per_page=2&sort=id&order=asc')->json('data.0.name'));
        $this->assertSame('Outro Nome', $this->getJson('/api/public/championships?sort=id&order=asc')->json('data.0.name'));
    }
}
