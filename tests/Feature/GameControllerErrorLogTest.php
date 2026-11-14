<?php

namespace Tests\Feature;

use App\Http\Controllers\GameController;
use App\Models\League;
use App\Models\Team;
use App\Services\GameService;
use Closure;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class GameControllerErrorLogTest extends TestCase
{
    use AssertsServerErrorIsLogged, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    #[DataProvider('failingRoutes')]
    public function test_logs_unexpected_error_and_returns_500(string $method, string $uri, string $serviceMethod, string $action, array|Closure $payload = []): void
    {
        $this->assertFailingRouteIsLogged(GameController::class.'@'.$action, $method, $uri, GameService::class, $serviceMethod, $payload);
    }

    public static function failingRoutes(): array
    {
        return [
            'index' => ['GET', '/api/games?league_id=1', 'index', 'index'],
            'publicShow' => ['GET', '/api/public/games/1', 'publicShow', 'publicShow'],
            'store' => ['POST', '/api/games', 'store', 'store', fn () => self::storePayload()],
            'show' => ['GET', '/api/games/1', 'show', 'show'],
            'update' => ['PUT', '/api/games/1', 'update', 'update', ['match_date' => '2026-12-01 16:00:00']],
            'updatePenaltyKicks' => ['PUT', '/api/games/1/penalty-kicks', 'updatePenaltyKicks', 'updatePenaltyKicks', ['kicks' => []]],
            'destroy' => ['DELETE', '/api/games/1', 'destroy', 'destroy'],
        ];
    }

    private static function storePayload(): array
    {
        $league = League::factory()->create();
        [$home, $away] = Team::factory()->count(2)->create()->all();
        $league->teams()->attach([$home->id, $away->id]);

        return [
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'scheduled',
        ];
    }
}
