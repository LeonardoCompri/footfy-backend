<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Models\League;
use App\Services\UserService;
use Closure;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class UserControllerErrorLogTest extends TestCase
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
        $this->assertFailingRouteIsLogged(UserController::class.'@'.$action, $method, $uri, UserService::class, $serviceMethod, $payload);
    }

    public static function failingRoutes(): array
    {
        return [
            'index' => ['GET', '/api/users', 'index', 'index'],
            'store' => ['POST', '/api/users', 'store', 'store', ['name' => 'Admin', 'document' => '12345678901', 'password' => 'secret1', 'role' => 'admin']],
            'show' => ['GET', '/api/users/1', 'show', 'show'],
            'update' => ['PUT', '/api/users/1', 'update', 'update', ['name' => 'Admin']],
            'destroy' => ['DELETE', '/api/users/1', 'destroy', 'destroy'],
            'syncLeagues' => ['POST', '/api/users/1/leagues', 'syncLeagues', 'syncLeagues', fn () => ['league_ids' => [League::factory()->create()->id]]],
            'removeLeague' => ['DELETE', '/api/users/1/leagues/1', 'removeLeague', 'removeLeague'],
        ];
    }
}
