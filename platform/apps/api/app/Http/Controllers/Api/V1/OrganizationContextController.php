<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Services\MembershipActivity;
use App\Domain\Organizations\Services\StaffRights;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class OrganizationContextController extends Controller
{
    public function select(Request $request, TenantContext $context, AuditRecorder $auditRecorder, MembershipActivity $activity): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid'],
        ]);

        return DB::transaction(function () use ($request, $validated, $context, $auditRecorder, $activity): JsonResponse {
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
                // "Последен вход" in this organization; the audit above left it selected for RLS.
                /** @var User $user */
                $user = $request->user();
                $activity->selected($user, (string) $organization->id);
            } finally {
                $context->clear();
            }

            return response()->json(['organization' => $this->present($organization)]);
        });
    }

    /**
     * The selected organization and what the user has in it (docs/product/team-rights.md, т. 9): the role, the rights
     * with the added ones, and the scope — for the menu and the buttons. `GET /organizations` keeps the role's rights.
     * `grants` are the user's own added rights with their last day, for "Какво можете" in "Моят профил" — read only,
     * never anyone else's.
     */
    public function current(Request $request, TenantContext $context, StaffRights $rights): JsonResponse
    {
        $organization = Organization::query()->findOrFail($context->organizationId());
        /** @var User $user */
        $user = $request->user();
        $access = $rights->for($user);
        $abilities = $access->abilities;
        sort($abilities);

        return response()->json(['organization' => $this->present($organization), 'access' => [
            'role' => $access->role?->value,
            'scope' => $access->scope->value,
            'abilities' => $abilities,
            'groups' => $access->groupIds,
            'activities' => $access->activityIds,
            'grants' => array_map(fn (string $ability, array $grant): array => [
                'ability' => $ability,
                // The end is kept as the start of the next day in Sofia; the screen says the last day.
                'expires_on' => $grant['expires_at'] === null ? null
                    : CarbonImmutable::parse($grant['expires_at'])->setTimezone('Europe/Sofia')->subDay()->toDateString(),
            ], array_keys($access->grants), array_values($access->grants)),
        ]]);
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
