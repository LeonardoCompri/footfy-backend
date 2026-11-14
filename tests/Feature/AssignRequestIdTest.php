<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssignRequestIdTest extends TestCase
{
    public function test_keeps_valid_request_id_received(): void
    {
        $response = $this->withHeader('X-Request-Id', 'front-123.abc_XYZ')->postJson('/api/login', []);

        $response->assertStatus(422)->assertHeader('X-Request-Id', 'front-123.abc_XYZ');
        $this->assertSame('front-123.abc_XYZ', Context::get('request_id'));
    }

    public function test_generates_uuid_when_header_is_missing(): void
    {
        $response = $this->postJson('/api/login', []);

        $requestId = $response->headers->get('X-Request-Id');

        $this->assertTrue(Str::isUuid($requestId));
        $this->assertSame($requestId, Context::get('request_id'));
    }

    #[DataProvider('invalidRequestIds')]
    public function test_replaces_invalid_request_id_with_uuid(string $invalid): void
    {
        $response = $this->withHeader('X-Request-Id', $invalid)->postJson('/api/login', []);

        $requestId = $response->headers->get('X-Request-Id');

        $this->assertNotSame($invalid, $requestId);
        $this->assertTrue(Str::isUuid($requestId));
        $this->assertSame($requestId, Context::get('request_id'));
    }

    public static function invalidRequestIds(): array
    {
        return [
            'curto demais' => ['abc1234'],
            'longo demais' => [str_repeat('a', 101)],
            'com espaço' => ['abc 12345678'],
            'com caractere especial' => ['abc12345;rm'],
        ];
    }

    public function test_request_id_reaches_log_records(): void
    {
        config([
            'logging.default' => 'capture',
            'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        ]);
        Route::get('/api/request-id-probe', function () {
            Log::error('falha simulada');

            return response()->noContent();
        });

        $this->withHeader('X-Request-Id', 'request-id-probe')->get('/api/request-id-probe')->assertNoContent();

        $records = Log::channel('capture')->getLogger()->getHandlers()[0]->getRecords();

        $this->assertCount(1, $records);
        $this->assertSame('request-id-probe', $records[0]->extra['request_id']);
    }
}
