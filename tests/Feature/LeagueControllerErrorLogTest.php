<?php

namespace Tests\Feature;

use App\Http\Controllers\LeagueController;
use App\Models\Championship;
use App\Models\League;
use App\Services\DashboardService;
use App\Services\LeagueDrawService;
use App\Services\LeagueGroupService;
use App\Services\LeagueKnockoutService;
use App\Services\LeagueService;
use App\Services\StandingsService;
use App\Services\StatisticsService;
use Closure;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class LeagueControllerErrorLogTest extends TestCase
{
    use AssertsServerErrorIsLogged, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    #[DataProvider('failingRoutes')]
    public function test_logs_unexpected_error_and_returns_500(string $method, string $uri, string $service, string $serviceMethod, string $action, array|Closure $payload = []): void
    {
        $league = League::factory()->create();

        $this->assertFailingRouteIsLogged(
            LeagueController::class.'@'.$action,
            $method,
            str_replace('{id}', (string) $league->id, $uri),
            $service,
            $serviceMethod,
            $payload,
        );
    }

    public static function failingRoutes(): array
    {
        return [
            'index' => ['GET', '/api/leagues', LeagueService::class, 'index', 'index'],
            'publicShow' => ['GET', '/api/public/leagues/{id}', LeagueService::class, 'publicShow', 'publicShow'],
            'table' => ['GET', '/api/leagues/{id}/table', StandingsService::class, 'build', 'table'],
            'publicTable' => ['GET', '/api/public/leagues/{id}/table', StandingsService::class, 'build', 'publicTable'],
            'statistics' => ['GET', '/api/leagues/{id}/statistics', StatisticsService::class, 'build', 'statistics'],
            'publicStatistics' => ['GET', '/api/public/leagues/{id}/statistics', StatisticsService::class, 'build', 'publicStatistics'],
            'dashboard' => ['GET', '/api/leagues/{id}/dashboard', DashboardService::class, 'build', 'dashboard'],
            'groups' => ['GET', '/api/leagues/{id}/groups', LeagueGroupService::class, 'build', 'groups'],
            'updateGroups' => ['PUT', '/api/leagues/{id}/groups', LeagueGroupService::class, 'assign', 'updateGroups', ['groups' => [['team_id' => 1, 'group_number' => 1]]]],
            'shuffleGroups' => ['POST', '/api/leagues/{id}/groups/shuffle', LeagueGroupService::class, 'shuffle', 'shuffleGroups'],
            'draw' => ['GET', '/api/leagues/{id}/draw', LeagueDrawService::class, 'build', 'draw'],
            'drawNextRound' => ['POST', '/api/leagues/{id}/draw/next-round', LeagueDrawService::class, 'drawNextRound', 'drawNextRound', ['round' => 1]],
            'undoLastRound' => ['DELETE', '/api/leagues/{id}/draw/last-round', LeagueDrawService::class, 'undoLastRound', 'undoLastRound', ['round' => 1]],
            'resetDraw' => ['DELETE', '/api/leagues/{id}/draw', LeagueDrawService::class, 'reset', 'resetDraw'],
            'knockout' => ['GET', '/api/leagues/{id}/knockout', LeagueKnockoutService::class, 'build', 'knockout'],
            'knockoutPreview' => ['GET', '/api/leagues/{id}/knockout/preview', LeagueKnockoutService::class, 'preview', 'knockoutPreview'],
            'knockoutNextStage' => ['POST', '/api/leagues/{id}/knockout/next-stage', LeagueKnockoutService::class, 'generateNextStage', 'knockoutNextStage', ['stage' => 'SF']],
            'undoKnockoutStage' => ['DELETE', '/api/leagues/{id}/knockout/last-stage', LeagueKnockoutService::class, 'undoLastStage', 'undoKnockoutStage', ['stage' => 'SF']],
            'store' => ['POST', '/api/leagues', LeagueService::class, 'store', 'store', fn () => ['name' => 'Série A', 'championship_id' => Championship::factory()->create()->id]],
            'show' => ['GET', '/api/leagues/{id}', LeagueService::class, 'show', 'show'],
            'update' => ['PUT', '/api/leagues/{id}', LeagueService::class, 'update', 'update', ['name' => 'Série B']],
            'destroy' => ['DELETE', '/api/leagues/{id}', LeagueService::class, 'destroy', 'destroy', ['password' => 'password']],
        ];
    }

    public function test_public_index_logs_unexpected_error_and_returns_500(): void
    {
        $exception = $this->failServiceCall(LeagueService::class, 'publicIndex');

        $response = TestResponse::fromBaseResponse(app(LeagueController::class)->publicIndex());

        $this->assertServerErrorIsLogged($response, $exception, null, null);
    }
}
