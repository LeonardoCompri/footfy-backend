<?php

namespace Tests\Feature;

use App\Http\Controllers\PlayerController;
use App\Models\Team;
use App\Services\PlayerService;
use Closure;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class PlayerControllerErrorLogTest extends TestCase
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
        $this->assertFailingRouteIsLogged(PlayerController::class.'@'.$action, $method, $uri, PlayerService::class, $serviceMethod, $payload);
    }

    public static function failingRoutes(): array
    {
        return [
            'index' => ['GET', '/api/players', 'index', 'index'],
            'positions' => ['GET', '/api/players/positions', 'positions', 'positions'],
            'publicShow' => ['GET', '/api/public/players/1', 'show', 'publicShow'],
            'store' => ['POST', '/api/players', 'store', 'store', fn () => ['team_id' => Team::factory()->create()->id, 'name' => 'Jogador']],
            'show' => ['GET', '/api/players/1', 'show', 'show'],
            'update' => ['PUT', '/api/players/1', 'update', 'update', ['name' => 'Jogador']],
            'destroy' => ['DELETE', '/api/players/1', 'destroy', 'destroy'],
        ];
    }
}
