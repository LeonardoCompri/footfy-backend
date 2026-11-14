<?php

namespace Tests\Feature;

use App\Http\Controllers\GameEventController;
use App\Models\GameEvent;
use App\Services\GameEventService;
use Closure;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class GameEventControllerErrorLogTest extends TestCase
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
        $this->assertFailingRouteIsLogged(GameEventController::class.'@'.$action, $method, $uri, GameEventService::class, $serviceMethod, $payload);
    }

    public static function failingRoutes(): array
    {
        return [
            'index' => ['GET', '/api/game-events', 'index', 'index'],
            'store' => ['POST', '/api/game-events', 'store', 'store', fn () => Arr::only(GameEvent::factory()->raw(), ['game_id', 'player_id', 'team_id', 'type'])],
            'show' => ['GET', '/api/game-events/1', 'show', 'show'],
            'update' => ['PUT', '/api/game-events/1', 'update', 'update', ['minute' => 10]],
            'destroy' => ['DELETE', '/api/game-events/1', 'destroy', 'destroy'],
        ];
    }
}
