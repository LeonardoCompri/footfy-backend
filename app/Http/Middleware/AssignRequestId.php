<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    private const VALID_ID = '/^[A-Za-z0-9._-]{8,100}$/D';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $received = $request->headers->get(self::HEADER);

        if (is_string($received) && preg_match(self::VALID_ID, $received) === 1) {
            return $received;
        }

        return (string) Str::uuid();
    }
}
