<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class OrganizationContextController extends Controller
{
    public function select(Request $request, TenantContext $context, AuditRecorder $auditRecorder): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid'],
        ]);

        return DB::transaction(function () use ($request, $validated, $context, $auditRecorder): JsonResponse {
            $userId = (string) $request->user()->getAuthIdentifier();

            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_user_id', ?, true)", [$userId]);
            }

            $membershipExists = OrganizationMembership::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $validated['organization_id'])
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->exists();

            $organization = Organization::query()
                ->withoutGlobalScopes()
                ->whereKey($validated['organization_id'])
                ->where('status', 'active')
                ->first();

            if (! $membershipExists || $organization === null) {
                return response()->json(['message' => 'Организацията не е намерена.'], Response::HTTP_NOT_FOUND);
            }

            $request->session()->put('active_organization_id', $organization->id);
            $context->select($organization);

            try {
                $auditRecorder->record(
                    AuditAction::OrganizationContextSelected,
                    new AuditableReference('organization', $organization->id),
                );
            } finally {
                $context->clear();
            }

            return response()->json(['organization' => $this->present($organization)]);
        });
    }

    public function current(TenantContext $context): JsonResponse
    {
        $organization = Organization::query()->findOrFail($context->organizationId());

        return response()->json(['organization' => $this->present($organization)]);
    }

    /** @return array{id: string, name: string, slug: string, status: string} */
    private function present(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status->value,
        ];
    }
}
