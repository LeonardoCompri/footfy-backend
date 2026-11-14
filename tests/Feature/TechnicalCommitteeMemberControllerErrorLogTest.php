<?php

namespace Tests\Feature;

use App\Http\Controllers\TechnicalCommitteeMemberController;
use App\Models\Team;
use App\Services\TechnicalCommitteeMemberService;
use Closure;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class TechnicalCommitteeMemberControllerErrorLogTest extends TestCase
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
        $this->assertFailingRouteIsLogged(TechnicalCommitteeMemberController::class.'@'.$action, $method, $uri, TechnicalCommitteeMemberService::class, $serviceMethod, $payload);
    }

    public static function failingRoutes(): array
    {
        return [
            'index' => ['GET', '/api/technical-committee-members', 'index', 'index'],
            'roles' => ['GET', '/api/technical-committee-members/roles', 'roles', 'roles'],
            'store' => ['POST', '/api/technical-committee-members', 'store', 'store', fn () => ['team_id' => Team::factory()->create()->id, 'name' => 'Membro', 'role' => 'Treinador']],
            'show' => ['GET', '/api/technical-committee-members/1', 'show', 'show'],
            'update' => ['PUT', '/api/technical-committee-members/1', 'update', 'update', ['name' => 'Membro']],
            'destroy' => ['DELETE', '/api/technical-committee-members/1', 'destroy', 'destroy'],
        ];
    }
}
