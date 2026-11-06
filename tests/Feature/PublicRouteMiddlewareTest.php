<?php

namespace Tests\Feature;

use App\Http\Middleware\PublicRouteMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PublicRouteMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(string $host): Request
    {
        return Request::create('/api/public/championships', 'GET', [], [], [], ['HTTP_HOST' => $host]);
    }

    private function next(): \Closure
    {
        return fn (Request $request) => response()->json(['ok' => true]);
    }

    public function test_blocks_request_in_production_with_different_host(): void
    {
        config(['app.env' => 'production', 'app.public_origin' => 'meusite.com']);

        $response = (new PublicRouteMiddleware)->handle($this->makeRequest('evil.com'), $this->next());

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals(['message' => 'Requisição bloqueada!'], json_decode($response->getContent(), true));
    }

    public function test_allows_request_in_production_with_matching_host(): void
    {
        config(['app.env' => 'production', 'app.public_origin' => 'meusite.com']);

        $response = (new PublicRouteMiddleware)->handle($this->makeRequest('meusite.com'), $this->next());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_allows_request_outside_production_regardless_of_host(): void
    {
        config(['app.env' => 'testing', 'app.public_origin' => 'meusite.com']);

        $response = (new PublicRouteMiddleware)->handle($this->makeRequest('qualquer-host.com'), $this->next());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_public_route_is_accessible_in_testing_environment(): void
    {
        $response = $this->getJson('/api/public/championships');

        $response->assertOk();
    }
}
