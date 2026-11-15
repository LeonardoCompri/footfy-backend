<?php

namespace Tests\Feature;

use App\Http\Controllers\ChampionshipController;
use App\Services\ChampionshipService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class ChampionshipControllerErrorLogTest extends TestCase
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
        $this->assertFailingRouteIsLogged(ChampionshipController::class.'@'.$action, $method, $uri, ChampionshipService::class, $serviceMethod, $payload);
    }

    public static function failingRoutes(): array
    {
        return [
            'index' => ['GET', '/api/championships', 'index', 'index'],
            'publicIndex' => ['GET', '/api/public/championships', 'publicIndex', 'publicIndex'],
            'publicShow' => ['GET', '/api/public/championships/1', 'publicShow', 'publicShow'],
            'store' => ['POST', '/api/championships', 'store', 'store', ['name' => 'Copa X']],
            'show' => ['GET', '/api/championships/1', 'show', 'show'],
            'update' => ['PUT', '/api/championships/1', 'update', 'update', ['name' => 'Copa Y']],
            'destroy' => ['DELETE', '/api/championships/1', 'destroy', 'destroy', ['password' => 'password']],
        ];
    }
}
