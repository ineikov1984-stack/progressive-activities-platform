<?php

namespace Tests\Feature\Identity;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * Guard for the 29.10.2026 session bug: starting the session twice gave every response a
 * cookie for an empty session. Feature tests cannot see that (no cookie round trip), so the
 * middleware stack itself is checked here.
 */
class SessionMiddlewareTest extends TestCase
{
    public function test_signed_in_api_routes_start_the_session_exactly_once(): void
    {
        foreach ([['GET', '/api/v1/me'], ['POST', '/api/v1/auth/login'], ['POST', '/api/v1/context/organization']] as [$method, $uri]) {
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));
            $middleware = array_map(
                fn (mixed $item): string => is_string($item) ? explode(':', $item)[0] : $item::class,
                app('router')->gatherRouteMiddleware($route),
            );

            $this->assertNotContains(EnsureFrontendRequestsAreStateful::class, $middleware, "{$method} {$uri}");
            $this->assertCount(1, array_filter($middleware, fn (string $name): bool => is_a($name, StartSession::class, true)), "{$method} {$uri}");
            $this->assertCount(1, array_filter($middleware, fn (string $name): bool => is_a($name, EncryptCookies::class, true)), "{$method} {$uri}");
        }
    }
}
