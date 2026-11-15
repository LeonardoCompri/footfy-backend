<?php

namespace Tests\Feature;

use App\Http\Controllers\StadiumController;
use App\Models\League;
use App\Services\StadiumService;
use Closure;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class StadiumControllerErrorLogTest extends TestCase
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
        $this->assertFailingRouteIsLogged(StadiumController::class.'@'.$action, $method, $uri, StadiumService::class, $serviceMethod, $payload);
    }

    public static function failingRoutes(): array
    {
        return [
            'index' => ['GET', '/api/stadiums', 'index', 'index'],
            'store' => ['POST', '/api/stadiums', 'store', 'store', fn () => ['league_id' => League::factory()->create()->id, 'name' => 'Arena', 'city' => 'Campinas', 'capacity' => 1000]],
            'show' => ['GET', '/api/stadiums/1', 'show', 'show'],
            'update' => ['PUT', '/api/stadiums/1', 'update', 'update', ['name' => 'Arena Nova']],
            'destroy' => ['DELETE', '/api/stadiums/1', 'destroy', 'destroy'],
        ];
    }
}
