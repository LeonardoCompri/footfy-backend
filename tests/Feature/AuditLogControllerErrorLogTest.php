<?php

namespace Tests\Feature;

use App\Http\Controllers\AuditLogController;
use App\Services\AuditLogService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class AuditLogControllerErrorLogTest extends TestCase
{
    use AssertsServerErrorIsLogged, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    public function test_index_logs_unexpected_error_and_returns_500(): void
    {
        $this->assertFailingRouteIsLogged(AuditLogController::class.'@index', 'GET', '/api/audit-logs', AuditLogService::class, 'index');
    }
}
