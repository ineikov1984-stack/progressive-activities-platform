<?php

namespace App\Http\Middleware;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\OperatorContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolvePlatformOperator
{
    public function __construct(private OperatorContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        return DB::transaction(function () use ($request, $next): Response {
            $userId = (string) $request->user()->getAuthIdentifier();

            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_user_id', ?, true)", [$userId]);
            }

            $isOperator = OrganizationMembership::query()
                ->withoutGlobalScopes()
                ->where('user_id', $userId)
                ->where('role', OrganizationRole::PlatformAdmin->value)
                ->where('status', 'active')
                ->exists();

            if (! $isOperator) {
                return new JsonResponse(['message' => 'Нямате права на платформен администратор.'], Response::HTTP_FORBIDDEN);
            }

            $this->context->activate($userId);

            try {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select("SELECT set_config('app.current_operator_id', ?, true)", [$userId]);
                }

                return $next($request);
            } finally {
                $this->context->clear();
            }
        });
    }
}
