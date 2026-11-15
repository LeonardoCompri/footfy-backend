<?php

namespace Tests\Feature;

use App\Http\Controllers\ProfileController;
use App\Services\PlayerService;
use App\Services\UserService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class ProfileControllerErrorLogTest extends TestCase
{
    use AssertsServerErrorIsLogged, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    #[DataProvider('failingRoutes')]
    public function test_logs_unexpected_error_and_returns_500(string $method, string $uri, string $service, string $serviceMethod, string $action, array $payload = []): void
    {
        $this->assertFailingRouteIsLogged(ProfileController::class.'@'.$action, $method, $uri, $service, $serviceMethod, $payload);
    }

    public static function failingRoutes(): array
    {
        return [
            'show' => ['GET', '/api/me', UserService::class, 'showProfile', 'show'],
            'update' => ['PUT', '/api/me', UserService::class, 'updateProfile', 'update', ['name' => 'Novo nome']],
            'updatePlayer' => ['PUT', '/api/me/player', PlayerService::class, 'updateOwnPlayer', 'updatePlayer', ['name' => 'Novo nome']],
        ];
    }
}
