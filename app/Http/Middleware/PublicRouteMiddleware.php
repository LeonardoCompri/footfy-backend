<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicRouteMiddleware
{
    /**
     * Bloqueia requisições cujo host não corresponda ao ORIGIN_HOST configurado.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.env') === 'production' && $request->getHost() !== config('app.public_origin')) {
            return response()->json(['message' => 'Requisição bloqueada!'], 403);
        }

        return $next($request);
    }
}
