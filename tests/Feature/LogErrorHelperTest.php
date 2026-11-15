<?php

namespace Tests\Feature;

use App\Http\Controllers\LeagueController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class LogErrorHelperTest extends TestCase
{
    use RefreshDatabase;

    private function bindRequestToRoute(array $action): void
    {
        $route = Route::get('/api/leagues', $action);
        $request = Request::create('/api/leagues');
        $request->setRouteResolver(fn () => $route);

        $this->app->instance('request', $request);
    }

    public function test_logs_exception_message_with_action_and_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->bindRequestToRoute([LeagueController::class, 'index']);
        $exception = new RuntimeException('falha simulada');
        Log::spy();

        log_error($exception);

        Log::shouldHaveReceived('error')->once()->with('falha simulada', [
            'exception' => $exception,
            'action' => LeagueController::class.'@index',
            'user_id' => $user->id,
        ]);
    }

    public function test_uses_custom_message_and_merges_extra_context(): void
    {
        $exception = new RuntimeException('falha simulada');
        Log::spy();

        log_error($exception, 'Falha ao sortear a rodada', ['league_id' => 7, 'user_id' => 999]);

        Log::shouldHaveReceived('error')->once()->with('Falha ao sortear a rodada', [
            'exception' => $exception,
            'action' => null,
            'user_id' => null,
            'league_id' => 7,
        ]);
    }

    public function test_logs_without_user_when_auth_fails(): void
    {
        Auth::shouldReceive('id')->andThrow(new RuntimeException('banco fora do ar'));
        $exception = new RuntimeException('falha simulada');
        Log::spy();

        log_error($exception);

        Log::shouldHaveReceived('error')->once()->with('falha simulada', [
            'exception' => $exception,
            'action' => null,
            'user_id' => null,
        ]);
    }

    public function test_logs_user_authenticated_by_sanctum_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api-token')->plainTextToken;
        Route::middleware('auth:sanctum')->get('/api/log-error-probe', function () {
            log_error(new RuntimeException('falha simulada'));

            return response()->noContent();
        });
        Log::spy();

        $this->withToken($token)->get('/api/log-error-probe')->assertNoContent();

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $message === 'falha simulada' && $context['user_id'] === $user->id
        );
    }
}
