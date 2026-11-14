<?php

namespace Tests\Feature;

use App\Http\Controllers\GameEventTypeController;
use App\Services\GameEventTypeService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class GameEventTypeControllerErrorLogTest extends TestCase
{
    use AssertsServerErrorIsLogged, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    #[DataProvider('failingRoutes')]
    public function test_logs_unexpected_error_and_returns_500(string $method, string $uri, string $serviceMethod, string $action, array $payload = []): void
    {
        $this->assertFailingRouteIsLogged(GameEventTypeController::class.'@'.$action, $method, $uri, GameEventTypeService::class, $serviceMethod, $payload);
    }

    public static function failingRoutes(): array
    {
        return [
            'index' => ['GET', '/api/event-types', 'index', 'index'],
            'store' => ['POST', '/api/event-types', 'store', 'store', ['slug' => 'falta', 'name' => 'Falta', 'icon' => 'mdi-whistle', 'color' => 'grey']],
            'update' => ['PUT', '/api/event-types/1', 'update', 'update', ['name' => 'Gol de placa']],
            'destroy' => ['DELETE', '/api/event-types/1', 'destroy', 'destroy'],
        ];
    }
}
