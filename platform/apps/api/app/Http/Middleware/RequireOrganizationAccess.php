<?php

namespace App\Http\Middleware;

use App\Domain\Organizations\Enums\OrganizationAccessMode;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireOrganizationAccess
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $mode): Response
    {
        $required = OrganizationAccessMode::tryFrom($mode);
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        $status = $organization->status instanceof OrganizationStatus
            ? $organization->status
            : OrganizationStatus::from((string) $organization->status);

        if ($required === null || ! $status->allowsAccess($required)) {
            return new JsonResponse([
                'message' => 'Текущият статус на организацията не позволява това действие.',
                'organization_status' => $status->value,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
