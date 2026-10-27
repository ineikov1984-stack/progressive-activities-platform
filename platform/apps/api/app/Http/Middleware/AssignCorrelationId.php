<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header('X-Request-Id');
        $correlationId = is_string($provided) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $provided) === 1
            ? $provided
            : (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $correlationId);

        return $response;
    }
}
