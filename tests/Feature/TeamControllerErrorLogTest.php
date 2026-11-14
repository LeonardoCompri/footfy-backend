<?php

namespace Tests\Feature;

use App\Http\Controllers\TeamController;
use App\Models\League;
use App\Services\TeamService;
use Closure;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class TeamControllerErrorLogTest extends TestCase
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
        $this->assertFailingRouteIsLogged(TeamController::class.'@'.$action, $method, $uri, TeamService::class, $serviceMethod, $payload);
    }

    public static function failingRoutes(): array
    {
        return [
            'index' => ['GET', '/api/teams', 'index', 'index'],
            'publicShow' => ['GET', '/api/public/teams/1', 'publicShow', 'publicShow'],
            'store' => ['POST', '/api/teams', 'store', 'store', ['name' => 'Time']],
            'show' => ['GET', '/api/teams/1', 'show', 'show'],
            'update' => ['PUT', '/api/teams/1', 'update', 'update', ['name' => 'Time']],
            'destroy' => ['DELETE', '/api/teams/1', 'destroy', 'destroy'],
            'syncLeagues' => ['POST', '/api/teams/1/leagues', 'syncLeagues', 'syncLeagues', fn () => ['league_ids' => [League::factory()->create()->id]]],
            'removeLeague' => ['DELETE', '/api/teams/1/leagues/1', 'removeLeague', 'removeLeague'],
        ];
    }
}
