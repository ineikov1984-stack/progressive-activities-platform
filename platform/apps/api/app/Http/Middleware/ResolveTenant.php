<?php

namespace App\Http\Middleware;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->header('X-Organization-Id')
            ?: $request->session()->get('active_organization_id');

        if (! is_string($organizationId) || $organizationId === '') {
            return new JsonResponse(
                ['message' => 'Изберете организация, за да продължите.'],
                Response::HTTP_CONFLICT,
            );
        }

        return DB::transaction(function () use ($next, $request, $organizationId): Response {
            $userId = (string) $request->user()->getAuthIdentifier();

            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_user_id', ?, true)", [$userId]);
            }

            $membershipExists = OrganizationMembership::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->exists();

            $organization = Organization::query()
                ->withoutGlobalScopes()
                ->whereKey($organizationId)
                ->first();

            if (! $membershipExists || $organization === null) {
                return new JsonResponse(['message' => 'Организацията не е намерена.'], Response::HTTP_NOT_FOUND);
            }

            $this->context->select($organization);

            try {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$organizationId]);
                }

                return $next($request);
            } finally {
                $this->context->clear();
            }
        });
    }
}
