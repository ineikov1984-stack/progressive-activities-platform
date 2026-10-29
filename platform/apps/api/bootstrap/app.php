<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\RequireOrganizationAccess;
use App\Http\Middleware\ResolvePlatformOperator;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // No statefulApi(): the signed-in routes in routes/api.php already run in the `web`
        // group (cookies, session, CSRF). Running both started the session twice; the second
        // decrypt failed, the session got a new ID that was never saved, and every response
        // handed the browser a cookie for an empty session (lost writes, logout after login).
        $middleware->appendToGroup('api', AssignCorrelationId::class);
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'organization.access' => RequireOrganizationAccess::class,
            'platform.operator' => ResolvePlatformOperator::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
