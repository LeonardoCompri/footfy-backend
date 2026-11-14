<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Concerns\AssertsServerErrorIsLogged;
use Tests\TestCase;

class AuthControllerErrorLogTest extends TestCase
{
    use AssertsServerErrorIsLogged, RefreshDatabase;

    public function test_login_logs_unexpected_error_and_returns_500(): void
    {
        $user = User::factory()->create(['document' => '12345678901']);
        $exception = new RuntimeException('falha simulada');
        Hash::shouldReceive('check')->andThrow($exception);
        Log::spy();

        $response = $this->postJson('/api/login', ['document' => $user->document, 'password' => 'password']);

        $this->assertServerErrorIsLogged($response, $exception, AuthController::class.'@login', null);
    }
}
