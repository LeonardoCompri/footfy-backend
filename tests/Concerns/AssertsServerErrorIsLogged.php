<?php

namespace Tests\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Throwable;

trait AssertsServerErrorIsLogged
{
    protected function assertFailingRouteIsLogged(
        string $action,
        string $method,
        string $uri,
        string $service,
        string $serviceMethod,
        array|Closure $payload = [],
    ): void {
        $userId = str_starts_with($uri, '/api/public') ? null : $this->actingAsMaster()->id;
        $payload = value($payload);
        $exception = $this->failServiceCall($service, $serviceMethod);

        $response = $this->json($method, $uri, $payload);

        $this->assertServerErrorIsLogged($response, $exception, $action, $userId);
    }

    protected function actingAsMaster(): User
    {
        $user = User::factory()->create();
        $user->assignRole('master');
        Sanctum::actingAs($user);

        return $user;
    }

    protected function failServiceCall(string $service, string $method): RuntimeException
    {
        $exception = new RuntimeException('falha simulada');
        $this->mock($service)->shouldReceive($method)->andThrow($exception);
        Log::spy();

        return $exception;
    }

    protected function assertServerErrorIsLogged(TestResponse $response, Throwable $exception, ?string $action, ?int $userId): void
    {
        $response->assertStatus(500)->assertJsonPath('message', 'Houve um erro no servidor, tente novamente!');

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $message === $exception->getMessage()
                && $context['exception'] === $exception
                && $context['action'] === $action
                && $context['user_id'] === $userId
        );
    }
}
